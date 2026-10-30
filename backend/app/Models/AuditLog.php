<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Insert-only audit trail. Updates and deletes are blocked in code as well as by the API.
 */
#[Fillable([
    'certificate_request_id', 'actor_id', 'actor_type', 'action', 'from_status', 'to_status',
    'attempt', 'reason', 'correlation_id', 'created_at',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'attempt' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are insert-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are insert-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<CertificateRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CertificateRequest::class, 'certificate_request_id');
    }
}
