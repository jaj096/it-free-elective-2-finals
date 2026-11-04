<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'id_number'])]
#[Hidden(['password', 'remember_token', 'id_number'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // One-way: bcrypt hash, can only be checked, never read back.
            'password' => 'hashed',
            'role' => Role::class,
            // Week 14: two-way, because the certificate must print it. Encrypted at rest with APP_KEY.
            'id_number' => 'encrypted',
        ];
    }

    /** ID number for staff screens, e.g. 2026-***01 (data minimisation: the full value is only needed on the certificate). */
    public function maskedIdNumber(): ?string
    {
        $id = (string) $this->id_number;
        if ($id === '') {
            return null;
        }

        return strlen($id) <= 6 ? str_repeat('*', strlen($id)) : substr($id, 0, 5).'***'.substr($id, -2);
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** @return HasMany<CertificateRequest, $this> */
    public function certificateRequests(): HasMany
    {
        return $this->hasMany(CertificateRequest::class, 'requester_id');
    }
}
