<?php

namespace Database\Factories;

use App\Enums\CertificateType;
use App\Enums\RequestStatus;
use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificateRequest>
 */
class CertificateRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'certificate_type' => CertificateType::Enrollment,
            'purpose' => 'Scholarship application',
            'status' => RequestStatus::Submitted,
            'status_changed_at' => now(),
        ];
    }
}
