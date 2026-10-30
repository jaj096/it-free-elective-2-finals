<?php

namespace App\Notifications;

use App\Models\CertificateRequest;
use Illuminate\Notifications\Notification;

/**
 * In-app (database) notification about a certificate request.
 * Only the request ID, a kind and a short safe message are stored.
 */
class RequestUpdate extends Notification
{
    public const GENERATED = 'certificate_generated';      // to custodians

    public const NEEDS_REVIEW = 'needs_review';            // to approving staff

    public const RELEASED = 'certificate_released';        // to the requester

    public const REJECTED = 'request_rejected';            // to the requester

    public const CANCELLED = 'request_cancelled';          // to the requester

    public function __construct(
        public readonly CertificateRequest $request,
        public readonly string $kind,
        public readonly string $message,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'request_id' => $this->request->id,
            'message' => $this->message,
        ];
    }
}
