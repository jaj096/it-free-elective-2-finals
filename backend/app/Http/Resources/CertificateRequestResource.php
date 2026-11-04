<?php

namespace App\Http\Resources;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\CertificateRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificateRequest
 */
class CertificateRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isStaff = $viewer && $viewer->hasRole(Role::Approver, Role::Custodian);
        $maxAttempts = (int) config('certificates.retry.max_attempts');

        return [
            'id' => $this->id,
            'certificate_type' => $this->certificate_type->value,
            'certificate_type_label' => $this->certificate_type->label(),
            'purpose' => $this->purpose,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_message' => $this->statusMessage($isStaff, $maxAttempts),
            'attempts' => $this->attempts,
            'max_attempts' => $maxAttempts,
            'rejection_reason' => $this->rejection_reason,
            // Staff see the technical reason; requesters only see the safe status message.
            'review_reason' => $this->when($isStaff, $this->review_reason),
            'requester' => $this->when($isStaff, fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
                // Week 14: masked; staff only need to see that it exists and roughly matches.
                'id_number_masked' => $this->requester->maskedIdNumber(),
                'has_id_number' => filled($this->requester->id_number),
            ]),
            'certificate' => $this->whenLoaded('certificate', fn () => $this->certificate ? [
                'id' => $this->certificate->id,
                'certificate_number' => $this->certificate->certificate_number,
                'sha256' => $this->when($isStaff, $this->certificate->sha256),
                'generated_at' => $this->certificate->generated_at?->toIso8601String(),
                'verified_at' => $this->certificate->verified_at?->toIso8601String(),
                'released_at' => $this->certificate->released_at?->toIso8601String(),
                'acknowledged_at' => $this->certificate->acknowledged_at?->toIso8601String(),
                'archived_at' => $this->certificate->archived_at?->toIso8601String(),
                'downloadable' => $this->status === RequestStatus::Released
                    || $this->status === RequestStatus::Acknowledged
                    || ($isStaff && $this->status->hasCertificate()),
            ] : null),
            'history' => $this->whenLoaded('auditLogs', fn () => $this->auditLogs->map(fn ($log) => [
                'action' => $log->action,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'actor' => $log->actor_type === 'system' ? 'System' : ($log->actor?->name ?? 'User'),
                'attempt' => $log->attempt,
                'reason' => $isStaff ? $log->reason : null,
                'correlation_id' => $isStaff ? $log->correlation_id : null,
                'at' => $log->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** A short message that is safe to show any user. */
    private function statusMessage(bool $isStaff, int $maxAttempts): string
    {
        return match ($this->status) {
            RequestStatus::Submitted => 'Waiting for approval',
            RequestStatus::Approved, RequestStatus::Queued => 'Queued for generation',
            RequestStatus::Generating => "Generating (attempt {$this->attempts} of {$maxAttempts})",
            RequestStatus::Retrying => 'Retrying (attempt '.min($this->attempts + 1, $maxAttempts)." of {$maxAttempts})",
            RequestStatus::Generated => 'Generated, waiting for verification',
            RequestStatus::Verified => 'Verified, waiting for release',
            RequestStatus::Released => 'Released - ready to download',
            RequestStatus::Acknowledged => 'Received',
            RequestStatus::Archived => 'Archived',
            RequestStatus::Rejected => 'Rejected',
            RequestStatus::Cancelled => 'Cancelled',
            RequestStatus::NeedsReview => $isStaff && $this->review_reason
                ? 'Needs review: '.$this->review_reason
                : 'Needs review: staff have been notified',
        };
    }
}
