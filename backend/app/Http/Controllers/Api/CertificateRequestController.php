<?php

namespace App\Http\Controllers\Api;

use App\Enums\CertificateType;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateRequestResource;
use App\Jobs\GenerateCertificate;
use App\Models\CertificateRequest;
use App\Models\User;
use App\Notifications\RequestUpdate;
use App\Services\CertificateWorkflow;
use App\Services\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CertificateRequestController extends Controller
{
    public function __construct(private CertificateWorkflow $workflow, private Notifier $notifier) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(RequestStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $query = CertificateRequest::with(['requester', 'certificate'])
            ->when($user->hasRole(Role::Requester), fn ($q) => $q->where('requester_id', $user->id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        // Staff queues read oldest first; requesters see their newest first.
        $query = $user->hasRole(Role::Requester) ? $query->latest('id') : $query->oldest('id');

        // Week 15 (W5): paginated. Before, the list stopped at 200 rows without saying so, so staff
        // could miss requests. meta.total / meta.last_page now tell the client exactly how much exists.
        return CertificateRequestResource::collection(
            $query->paginate((int) ($data['per_page'] ?? 50))->withQueryString()
        );
    }

    public function show(Request $request, CertificateRequest $certificateRequest): CertificateRequestResource
    {
        $this->ensureCanView($request->user(), $certificateRequest);

        return new CertificateRequestResource(
            $certificateRequest->load(['requester', 'certificate', 'auditLogs.actor'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'certificate_type' => ['required', Rule::enum(CertificateType::class)],
            'purpose' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'certificate_type.required' => 'Choose a certificate type.',
            'certificate_type.enum' => 'Choose a valid certificate type.',
            'purpose.min' => 'Purpose must be at least 5 characters.',
        ]);

        $user = $request->user();
        $key = $request->header('Idempotency-Key');
        $hash = hash('sha256', json_encode([$data['certificate_type'], trim($data['purpose'])]));

        // Idempotency: the same key from the same user returns the original request
        // instead of creating a duplicate (e.g. a double-click or a network retry).
        if ($key !== null) {
            abort_unless(preg_match('/^[A-Za-z0-9-]{8,64}$/', $key), 400, 'Invalid Idempotency-Key.');

            if ($replay = $this->replayIdempotent($user, $key, $hash)) {
                return $replay;
            }
        }

        try {
            $certificateRequest = $this->createRequest($user, $data, $key, $hash);
        } catch (UniqueConstraintViolationException $e) {
            // Week 15 (W6): two submits with the same key can both pass the check above at the same moment.
            // The UNIQUE(requester_id, idempotency_key) index stops the second insert; before, that surfaced
            // as a 500. Now the loser answers exactly like a normal retry.
            if ($key !== null && ($replay = $this->replayIdempotent($user, $key, $hash))) {
                return $replay;
            }
            throw $e;
        }

        return (new CertificateRequestResource($certificateRequest->load('requester')))->response()->setStatusCode(201);
    }

    /** 200 with the original request (same data) or 409 (different data); null if the key is unused. */
    private function replayIdempotent(User $user, string $key, string $hash): ?JsonResponse
    {
        $existing = CertificateRequest::where('requester_id', $user->id)->where('idempotency_key', $key)->first();
        if (! $existing) {
            return null;
        }
        if ($existing->idempotency_hash !== $hash) {
            return ApiError::response(409, 'DUPLICATE_REQUEST', 'This Idempotency-Key was already used with different data.');
        }

        return (new CertificateRequestResource($existing->load('requester')))->response()->setStatusCode(200);
    }

    /** @param array{certificate_type: string, purpose: string} $data */
    private function createRequest(User $user, array $data, ?string $key, string $hash): CertificateRequest
    {
        return DB::transaction(function () use ($user, $data, $key, $hash) {
            $certificateRequest = CertificateRequest::create([
                'requester_id' => $user->id,
                'certificate_type' => $data['certificate_type'],
                'purpose' => trim($data['purpose']),
                'status' => RequestStatus::Submitted,
                'idempotency_key' => $key,
                'idempotency_hash' => $key ? $hash : null,
                'status_changed_at' => now(),
            ]);
            $this->workflow->audit($certificateRequest, 'submitted', $user, null, RequestStatus::Submitted);

            return $certificateRequest;
        });
    }

    /** Approves and queues generation. Returns 202 because the work happens in the background. */
    public function approve(Request $request, CertificateRequest $certificateRequest): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($certificateRequest, $user) {
            $locked = CertificateRequest::whereKey($certificateRequest->id)->lockForUpdate()->firstOrFail();

            $locked->approved_by = $user->id;
            $locked->approved_at = now();
            $this->workflow->transition($locked, RequestStatus::Approved, 'approved', $user);

            $locked->generation_key = (string) Str::uuid();
            $locked->attempts = 0;
            $this->workflow->transition($locked, RequestStatus::Queued, 'queued', $user);

            // afterCommit: the job is only pushed if the approval is saved, so it can't
            // run against a request that was rolled back (and can't be lost if this commits).
            GenerateCertificate::dispatch($locked->id)->afterCommit();
        });

        return (new CertificateRequestResource($certificateRequest->refresh()->load('requester')))
            ->response()->setStatusCode(202);
    }

    public function reject(Request $request, CertificateRequest $certificateRequest): CertificateRequestResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $user = $request->user();

        DB::transaction(function () use ($certificateRequest, $user, $data) {
            $locked = CertificateRequest::whereKey($certificateRequest->id)->lockForUpdate()->firstOrFail();
            $locked->rejection_reason = $data['reason'];
            $this->workflow->transition($locked, RequestStatus::Rejected, 'rejected', $user, $data['reason']);
        });

        $certificateRequest->refresh();
        $this->notifier->toRequester($certificateRequest, RequestUpdate::REJECTED,
            "Your request #{$certificateRequest->id} was rejected: {$data['reason']}");

        return new CertificateRequestResource($certificateRequest->load('requester'));
    }

    /** Requesters may only see their own requests; others get 404 so IDs can't be probed. */
    private function ensureCanView(User $user, CertificateRequest $certificateRequest): void
    {
        if ($user->hasRole(Role::Requester) && $certificateRequest->requester_id !== $user->id) {
            abort(404);
        }
    }
}
