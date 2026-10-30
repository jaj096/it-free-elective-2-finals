<?php

namespace App\Http\Controllers\Api;

use App\Enums\CertificateType;
use App\Enums\RequestStatus;
use App\Exceptions\InvalidStateTransition;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateRequestResource;
use App\Jobs\GenerateCertificate;
use App\Models\CertificateRequest;
use App\Notifications\RequestUpdate;
use App\Services\CertificateWorkflow;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Manual handling of permanent failures (human approval point). */
class ReviewQueueController extends Controller
{
    public function __construct(private CertificateWorkflow $workflow, private Notifier $notifier) {}

    public function index(): AnonymousResourceCollection
    {
        $items = CertificateRequest::with(['requester', 'certificate'])
            ->where('status', RequestStatus::NeedsReview)
            ->oldest('status_changed_at')
            ->get();

        return CertificateRequestResource::collection($items);
    }

    /**
     * Re-queues an item after staff fixed the cause. Staff may correct the request's
     * own fields and the requester's ID number in the same call.
     * The generation_key is kept, so the generator still treats it as the same job.
     */
    public function requeue(Request $request, CertificateRequest $certificateRequest): JsonResponse
    {
        $data = $request->validate([
            'certificate_type' => ['sometimes', Rule::enum(CertificateType::class)],
            'purpose' => ['sometimes', 'string', 'min:5', 'max:255'],
            'requester_id_number' => ['sometimes', 'string', 'regex:/^\d{4}-\d{5}$/'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'requester_id_number.regex' => 'ID number must look like 2026-00001.',
        ]);
        $user = $request->user();

        DB::transaction(function () use ($certificateRequest, $data, $user) {
            $locked = CertificateRequest::whereKey($certificateRequest->id)->lockForUpdate()->firstOrFail();

            // Only items waiting for review can be re-queued by staff.
            if ($locked->status !== RequestStatus::NeedsReview) {
                throw new InvalidStateTransition($locked->status, RequestStatus::Queued);
            }

            if (isset($data['requester_id_number'])) {
                $locked->requester->update(['id_number' => $data['requester_id_number']]);
            }
            $locked->fill(array_intersect_key($data, array_flip(['certificate_type', 'purpose'])));
            $locked->attempts = 0;
            $locked->review_reason = null;
            $locked->sweeper_redispatched_at = null;

            $this->workflow->transition($locked, RequestStatus::Queued, 'requeued', $user, $data['note'] ?? 'Re-queued after review.');

            GenerateCertificate::dispatch($locked->id)->afterCommit();
        });

        return (new CertificateRequestResource($certificateRequest->refresh()->load('requester')))
            ->response()->setStatusCode(202);
    }

    public function cancel(Request $request, CertificateRequest $certificateRequest): CertificateRequestResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $user = $request->user();

        DB::transaction(function () use ($certificateRequest, $data, $user) {
            $locked = CertificateRequest::whereKey($certificateRequest->id)->lockForUpdate()->firstOrFail();
            $this->workflow->transition($locked, RequestStatus::Cancelled, 'cancelled', $user, $data['reason']);
        });

        $certificateRequest->refresh();
        $this->notifier->toRequester($certificateRequest, RequestUpdate::CANCELLED,
            "Your request #{$certificateRequest->id} was cancelled: {$data['reason']}");

        return new CertificateRequestResource($certificateRequest->load('requester'));
    }
}
