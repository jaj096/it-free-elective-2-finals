<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\AuditLog;
use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * The only place that changes a request's status.
 *
 * Every transition is checked against RequestStatus::allowedNext(), then written to
 * the audit_logs table and the structured automation log with the correlation ID.
 */
class CertificateWorkflow
{
    /**
     * @param  array<string, mixed>  $extra  Extra fields for the log line (never secrets or names).
     */
    public function transition(
        CertificateRequest $request,
        RequestStatus $to,
        string $action,
        ?User $actor = null,
        ?string $reason = null,
        ?int $attempt = null,
        array $extra = [],
    ): CertificateRequest {
        $from = $request->status;

        if (! $from->canMoveTo($to)) {
            throw new InvalidStateTransition($from, $to);
        }

        $request->status = $to;
        $request->status_changed_at = now();
        $request->save();

        $this->audit($request, $action, $actor, $from, $to, $reason, $attempt);

        Log::channel('automation')->info('request.'.$action, array_filter([
            'request_id' => $request->id,
            'actor' => $actor ? 'user:'.$actor->id : 'system',
            'from_status' => $from->value,
            'to_status' => $to->value,
            'attempt' => $attempt,
            'reason' => $reason,
        ] + $extra, fn ($v) => $v !== null));

        return $request;
    }

    /** Records an event that is not a status change (e.g. a skipped duplicate job). */
    public function audit(
        CertificateRequest $request,
        string $action,
        ?User $actor = null,
        ?RequestStatus $from = null,
        ?RequestStatus $to = null,
        ?string $reason = null,
        ?int $attempt = null,
    ): AuditLog {
        return AuditLog::create([
            'certificate_request_id' => $request->id,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? 'user' : 'system',
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'attempt' => $attempt,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'correlation_id' => Context::get('correlation_id'),
            'created_at' => now(),
        ]);
    }
}
