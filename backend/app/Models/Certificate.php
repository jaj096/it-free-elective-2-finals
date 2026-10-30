<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'certificate_request_id', 'certificate_number', 'file_path', 'sha256', 'generated_at',
    'verified_by', 'verified_at', 'released_by', 'released_at', 'acknowledged_at', 'archived_by', 'archived_at',
])]
class Certificate extends Model
{
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'verified_at' => 'datetime',
            'released_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CertificateRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CertificateRequest::class, 'certificate_request_id');
    }
}
