<?php

namespace App\Models;

use App\Enums\CertificateType;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'requester_id', 'certificate_type', 'purpose', 'status', 'idempotency_key', 'idempotency_hash',
    'generation_key', 'attempts', 'review_reason', 'rejection_reason', 'approved_by', 'approved_at',
    'sweeper_redispatched_at', 'status_changed_at',
])]
class CertificateRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'certificate_type' => CertificateType::class,
            'approved_at' => 'datetime',
            'sweeper_redispatched_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasOne<Certificate, $this> */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class)->orderBy('id');
    }
}
