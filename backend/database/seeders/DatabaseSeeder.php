<?php

namespace Database\Seeders;

use App\Enums\CertificateType;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fictional demo data only. No real students or staff.
 * Every account uses the password: Password123!
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = 'Password123!';

        $juan = User::create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.test', 'password' => $password,
            'role' => Role::Requester, 'id_number' => '2026-00001']);
        $maria = User::create(['name' => 'Maria Santos', 'email' => 'maria@example.test', 'password' => $password,
            'role' => Role::Requester, 'id_number' => '2026-00002']);
        // Deliberately has no ID number: approving his request shows a business-rule
        // failure that goes to needs_review without calling the generator.
        $pedro = User::create(['name' => 'Pedro Reyes', 'email' => 'pedro@example.test', 'password' => $password,
            'role' => Role::Requester, 'id_number' => null]);

        User::create(['name' => 'Ana Lim', 'email' => 'approver@example.test', 'password' => $password,
            'role' => Role::Approver]);
        User::create(['name' => 'Carlo Garcia', 'email' => 'custodian@example.test', 'password' => $password,
            'role' => Role::Custodian]);

        $requests = [
            [$juan, CertificateType::Enrollment, 'Scholarship application'],
            [$maria, CertificateType::GoodMoral, 'Internship requirement'],
            [$juan, CertificateType::Grades, 'Transfer credential evaluation'],
            [$maria, CertificateType::Enrollment, 'Travel visa application'],
            [$pedro, CertificateType::Enrollment, 'Bank account opening'],
            [$juan, CertificateType::Graduation, 'Job application'],
        ];

        foreach ($requests as [$user, $type, $purpose]) {
            $request = CertificateRequest::create([
                'requester_id' => $user->id,
                'certificate_type' => $type,
                'purpose' => $purpose,
                'status' => RequestStatus::Submitted,
                'status_changed_at' => now(),
            ]);
            AuditLog::create([
                'certificate_request_id' => $request->id,
                'actor_id' => $user->id,
                'actor_type' => 'user',
                'action' => 'submitted',
                'to_status' => RequestStatus::Submitted->value,
                'correlation_id' => 'seed',
                'created_at' => now(),
            ]);
        }
    }
}
