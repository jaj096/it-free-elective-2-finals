<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateRequestResource;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Notifications\RequestUpdate;
use App\Services\CertificateWorkflow;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Custodian verification, release and archive; requester download and acknowledgement. */
class CertificateController extends Controller
{
    public function __construct(private CertificateWorkflow $workflow, private Notifier $notifier) {}

    /**
     * Verification recomputes the SHA-256 of the stored file and compares it with the hash
     * recorded at generation, so a file changed on disk is caught before release.
     */
    public function verify(Request $request, Certificate $certificate): CertificateRequestResource
    {
        $data = $request->validate([
            'certificate_number' => ['required', 'string', 'max:30'],
        ]);

        abort_unless(hash_equals($certificate->certificate_number, $data['certificate_number']), 422,
            'Certificate number does not match.');

        $disk = Storage::disk('local');
        abort_unless($disk->exists($certificate->file_path), 409, 'Certificate file is missing.');
        $actualHash = hash('sha256', $disk->get($certificate->file_path));
        abort_unless(hash_equals($certificate->sha256, $actualHash), 409,
            'Certificate file does not match its recorded hash. Do not release; send for regeneration.');

        return $this->move($request, $certificate, RequestStatus::Verified, 'verified', function (Certificate $c) use ($request) {
            $c->verified_by = $request->user()->id;
            $c->verified_at = now();
        });
    }

    public function release(Request $request, Certificate $certificate): CertificateRequestResource
    {
        $resource = $this->move($request, $certificate, RequestStatus::Released, 'released', function (Certificate $c) use ($request) {
            $c->released_by = $request->user()->id;
            $c->released_at = now();
        });

        $this->notifier->toRequester($certificate->request, RequestUpdate::RELEASED,
            "Your certificate {$certificate->certificate_number} is ready to download.");

        return $resource;
    }

    public function acknowledge(Request $request, Certificate $certificate): CertificateRequestResource
    {
        abort_unless($certificate->request->requester_id === $request->user()->id, 404);

        return $this->move($request, $certificate, RequestStatus::Acknowledged, 'acknowledged', function (Certificate $c) {
            $c->acknowledged_at = now();
        });
    }

    public function archive(Request $request, Certificate $certificate): CertificateRequestResource
    {
        return $this->move($request, $certificate, RequestStatus::Archived, 'archived', function (Certificate $c) use ($request) {
            $c->archived_by = $request->user()->id;
            $c->archived_at = now();
        });
    }

    /** Requesters may download only their own released certificate; custodians any generated one. */
    public function download(Request $request, Certificate $certificate): StreamedResponse
    {
        $user = $request->user();
        $status = $certificate->request->status;

        if ($user->hasRole(Role::Requester)) {
            abort_unless($certificate->request->requester_id === $user->id, 404);
            abort_unless(in_array($status, [RequestStatus::Released, RequestStatus::Acknowledged], true), 403,
                'This certificate has not been released yet.');
        } elseif (! $user->hasRole(Role::Custodian)) {
            abort(403);
        }

        $this->workflow->audit($certificate->request, 'downloaded', $user);

        return Storage::disk('local')->download($certificate->file_path, $certificate->certificate_number.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function move(Request $request, Certificate $certificate, RequestStatus $to, string $action, callable $update): CertificateRequestResource
    {
        DB::transaction(function () use ($request, $certificate, $to, $action, $update) {
            $locked = CertificateRequest::whereKey($certificate->certificate_request_id)->lockForUpdate()->firstOrFail();
            $this->workflow->transition($locked, $to, $action, $request->user());
            $update($certificate);
            $certificate->save();
        });

        return new CertificateRequestResource(
            $certificate->request->refresh()->load(['requester', 'certificate'])
        );
    }
}
