<?php

namespace App\Enums;

/**
 * Single source of truth for the certificate request lifecycle.
 * Every status change goes through CertificateWorkflow::transition(),
 * which refuses any move not listed in allowedNext().
 */
enum RequestStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Queued = 'queued';
    case Generating = 'generating';
    case Retrying = 'retrying';
    case Generated = 'generated';
    case Verified = 'verified';
    case Released = 'released';
    case Acknowledged = 'acknowledged';
    case Archived = 'archived';
    case NeedsReview = 'needs_review';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved => [self::Queued],
            // queued -> needs_review is used by the sweeper for stuck items.
            self::Queued => [self::Generating, self::NeedsReview],
            // generating -> queued is used by the sweeper to re-dispatch a stuck job.
            self::Generating => [self::Generated, self::Retrying, self::NeedsReview, self::Queued],
            self::Retrying => [self::Generating, self::NeedsReview, self::Queued],
            self::Generated => [self::Verified],
            self::Verified => [self::Released],
            self::Released => [self::Acknowledged],
            self::Acknowledged => [self::Archived],
            self::NeedsReview => [self::Queued, self::Cancelled],
            self::Rejected, self::Archived, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** True once a certificate exists for the request. */
    public function hasCertificate(): bool
    {
        return in_array($this, [self::Generated, self::Verified, self::Released, self::Acknowledged, self::Archived], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NeedsReview => 'Needs review',
            default => ucfirst($this->value),
        };
    }
}
