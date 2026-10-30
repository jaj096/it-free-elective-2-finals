<?php

namespace App\Jobs;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Exceptions\GeneratorException;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Notifications\RequestUpdate;
use App\Services\CertificateWorkflow;
use App\Services\GeneratorClient;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates exactly one certificate for one approved request.
 *
 * Reliability controls:
 *  - Idempotency: the request row is locked and its status checked before work starts,
 *    and certificates.certificate_request_id is UNIQUE, so a duplicate job can never
 *    create a second certificate. The generation_key is also sent as the generator's job_key.
 *  - Bounded retry: transient failures are retried up to config('certificates.retry.max_attempts')
 *    attempts with backoff + jitter. Permanent failures are never retried.
 *  - No silent loss: every failure ends in needs_review with a reason and a notification,
 *    and failed() catches anything unexpected.
 *
 * Only the request ID is stored in the queue payload (no personal data).
 */
class GenerateCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** Hard limit for one attempt; longer than the generator HTTP timeout. */
    public int $timeout = 30;

    public function __construct(public int $requestId)
    {
        $this->tries = (int) config('certificates.retry.max_attempts', 3);
    }

    public function handle(CertificateWorkflow $workflow, GeneratorClient $client, Notifier $notifier): void
    {
        $attempt = $this->attempts();
        $log = Log::channel('automation');

        // 1. Claim the request inside a transaction with a row lock.
        $request = DB::transaction(function () use ($workflow, $attempt, $log) {
            $request = CertificateRequest::whereKey($this->requestId)->lockForUpdate()->first();

            if (! $request) {
                $log->warning('generation.skipped', ['request_id' => $this->requestId, 'reason' => 'request not found']);

                return null;
            }

            if ($request->status->hasCertificate() || $request->certificate()->exists()) {
                $workflow->audit($request, 'duplicate_skipped', attempt: $attempt, reason: 'Certificate already exists.');
                $log->info('generation.duplicate_skipped', ['request_id' => $request->id, 'attempt' => $attempt, 'status' => $request->status->value]);

                return null;
            }

            if (! in_array($request->status, [RequestStatus::Queued, RequestStatus::Retrying], true)) {
                // e.g. another worker is already generating it, or staff cancelled it.
                $workflow->audit($request, 'duplicate_skipped', attempt: $attempt, reason: "Not processable while {$request->status->value}.");
                $log->info('generation.duplicate_skipped', ['request_id' => $request->id, 'attempt' => $attempt, 'status' => $request->status->value]);

                return null;
            }

            $request->attempts = $attempt;
            $workflow->transition($request, RequestStatus::Generating, 'generation_started', attempt: $attempt);

            return $request;
        });

        if (! $request) {
            return;
        }

        // 2. Business-rule validation before calling the service (permanent: no retry, no call).
        if ($problem = $this->validationProblem($request)) {
            $log->warning('generation.attempt_failed', [
                'request_id' => $request->id, 'attempt' => $attempt, 'error_class' => 'permanent', 'reason' => $problem,
            ]);
            $this->sendToReview($request, $problem, $attempt, $workflow, $notifier);
            $this->fail(GeneratorException::permanent($problem));

            return;
        }

        // 3. Call the generator. No database lock is held during the HTTP call.
        $started = microtime(true);
        try {
            $pdf = $client->generate($request->load('requester'));
        } catch (GeneratorException $e) {
            $this->handleFailure($request, $e, $attempt, $this->elapsedMs($started), $workflow, $notifier);

            return;
        }
        $durationMs = $this->elapsedMs($started);

        // 4. Store the certificate exactly once.
        $certificate = DB::transaction(function () use ($request, $pdf, $attempt, $durationMs, $workflow, $log) {
            $request = CertificateRequest::whereKey($request->id)->lockForUpdate()->first();

            if ($request->certificate()->exists()) {
                $workflow->audit($request, 'duplicate_skipped', attempt: $attempt, reason: 'Certificate already exists.');
                $log->info('generation.duplicate_skipped', ['request_id' => $request->id, 'attempt' => $attempt]);

                return null;
            }

            $number = sprintf('%s-%s-%06d', config('certificates.number_prefix'), now()->format('Y'), $request->id);
            $path = "certificates/{$number}.pdf";
            Storage::disk('local')->put($path, $pdf);

            $certificate = Certificate::create([
                'certificate_request_id' => $request->id,
                'certificate_number' => $number,
                'file_path' => $path,
                'sha256' => hash('sha256', $pdf),
                'generated_at' => now(),
            ]);

            $request->review_reason = null;
            $workflow->transition($request, RequestStatus::Generated, 'generation_succeeded', attempt: $attempt, extra: [
                'duration_ms' => $durationMs,
                'certificate_number' => $number,
            ]);

            return $certificate;
        });

        if ($certificate) {
            $notifier->toRole(Role::Custodian, $request, RequestUpdate::GENERATED,
                "Certificate {$certificate->certificate_number} is ready for verification.");
        }
    }

    /** Runs when the job fails for good, including unexpected errors and timeouts. */
    public function failed(?Throwable $exception): void
    {
        $request = CertificateRequest::find($this->requestId);
        if (! $request || $request->status === RequestStatus::NeedsReview || $request->status->hasCertificate()) {
            return;
        }

        if (in_array($request->status, [RequestStatus::Queued, RequestStatus::Generating, RequestStatus::Retrying], true)) {
            $reason = 'Generation job stopped unexpectedly ('.class_basename($exception ?? 'Unknown').'). Check logs with the correlation ID.';
            $this->sendToReview($request, $reason, $this->attempts(), app(CertificateWorkflow::class), app(Notifier::class));
        }
    }

    private function handleFailure(
        CertificateRequest $request,
        GeneratorException $e,
        int $attempt,
        int $durationMs,
        CertificateWorkflow $workflow,
        Notifier $notifier,
    ): void {
        Log::channel('automation')->warning('generation.attempt_failed', array_filter([
            'request_id' => $request->id,
            'attempt' => $attempt,
            'max_attempts' => $this->tries,
            'error_class' => $e->transient ? 'transient' : 'permanent',
            'http_status' => $e->httpStatus,
            'duration_ms' => $durationMs,
            'reason' => $e->safeReason,
        ], fn ($v) => $v !== null));

        if ($e->transient && $attempt < $this->tries) {
            $delay = $this->retryDelay($attempt);
            $request->review_reason = $e->safeReason;
            $workflow->transition($request, RequestStatus::Retrying, 'generation_retry_scheduled',
                reason: $e->safeReason, attempt: $attempt, extra: ['retry_in_seconds' => $delay]);
            $this->release($delay);

            return;
        }

        $reason = $e->transient
            ? "Generator still unavailable after {$attempt} attempts. Last error: {$e->safeReason}"
            : $e->safeReason;

        $this->sendToReview($request, $reason, $attempt, $workflow, $notifier);
        $this->fail($e);
    }

    private function sendToReview(
        CertificateRequest $request,
        string $reason,
        int $attempt,
        CertificateWorkflow $workflow,
        Notifier $notifier,
    ): void {
        DB::transaction(function () use ($request, $reason, $attempt, $workflow) {
            $request = CertificateRequest::whereKey($request->id)->lockForUpdate()->first();
            if ($request->status === RequestStatus::NeedsReview) {
                return;
            }
            $request->review_reason = mb_substr($reason, 0, 255);
            $workflow->transition($request, RequestStatus::NeedsReview, 'sent_to_review', reason: $reason, attempt: $attempt);
        });

        $notifier->toRole(Role::Approver, $request, RequestUpdate::NEEDS_REVIEW,
            "Request #{$request->id} needs review: {$reason}");
    }

    /** Checks the data the certificate needs. Returns a safe reason, or null if valid. */
    private function validationProblem(CertificateRequest $request): ?string
    {
        $requester = $request->requester;

        return match (true) {
            ! $requester => 'Requester account no longer exists.',
            blank($requester->name) => 'Requester name is missing.',
            blank($requester->id_number) => 'Requester ID number is missing; update the requester record, then re-queue.',
            blank($request->purpose) => 'Purpose is missing.',
            blank($request->generation_key) => 'Generation key is missing.',
            default => null,
        };
    }

    private function retryDelay(int $attempt): int
    {
        $backoff = config('certificates.retry.backoff', [10, 30]);
        $base = $backoff[$attempt - 1] ?? end($backoff);
        $jitter = (int) config('certificates.retry.jitter', 0);

        return max(0, (int) $base) + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
