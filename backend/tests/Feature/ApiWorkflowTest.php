<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Jobs\GenerateCertificate;
use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** API contract, validation, authentication and authorization checks. */
class ApiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $approver;

    private User $custodian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requester = User::factory()->create(['email' => 'juan@example.test', 'password' => 'Password123!']);
        $this->approver = User::factory()->approver()->create();
        $this->custodian = User::factory()->custodian()->create();
    }

    public function test_login_returns_token_and_bad_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'juan@example.test', 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['role']]]);

        $this->postJson('/api/v1/auth/login', ['email' => 'juan@example.test', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => 'juan@example.test', 'password' => 'wrong']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'juan@example.test', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_missing_token_is_401_with_correlation_id(): void
    {
        $this->getJson('/api/v1/requests')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertHeader('X-Correlation-ID');
    }

    public function test_submit_validates_input(): void
    {
        Sanctum::actingAs($this->requester);

        $this->postJson('/api/v1/requests', ['certificate_type' => 'passport', 'purpose' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['certificate_type', 'purpose']]]);
    }

    public function test_idempotency_key_prevents_duplicate_requests(): void
    {
        Sanctum::actingAs($this->requester);
        $body = ['certificate_type' => 'enrollment', 'purpose' => 'Scholarship application'];
        $headers = ['Idempotency-Key' => 'abc12345-key'];

        $this->postJson('/api/v1/requests', $body, $headers)->assertCreated();
        $this->postJson('/api/v1/requests', $body, $headers)->assertOk();
        $this->postJson('/api/v1/requests', ['certificate_type' => 'grades', 'purpose' => 'Something else'], $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');

        $this->assertSame(1, CertificateRequest::count());
    }

    public function test_requester_cannot_approve(): void
    {
        $request = CertificateRequest::factory()->for($this->requester, 'requester')->create();
        Sanctum::actingAs($this->requester);

        $this->postJson("/api/v1/requests/{$request->id}/approve")
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_requester_cannot_see_another_requesters_request(): void
    {
        $other = CertificateRequest::factory()->create();
        Sanctum::actingAs($this->requester);

        $this->getJson("/api/v1/requests/{$other->id}")->assertStatus(404);
        $this->getJson('/api/v1/requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_approve_queues_one_job_and_second_approve_is_409(): void
    {
        Queue::fake();
        $request = CertificateRequest::factory()->for($this->requester, 'requester')->create();
        Sanctum::actingAs($this->approver);

        $this->postJson("/api/v1/requests/{$request->id}/approve")
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->postJson("/api/v1/requests/{$request->id}/approve")
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE');

        Queue::assertPushed(GenerateCertificate::class, 1);
        $this->assertNotNull($request->refresh()->generation_key);
    }

    public function test_full_lifecycle_from_approval_to_archive(): void
    {
        Storage::fake('local');
        Http::fake(['generator.test/*' => Http::response(['pdf_base64' => base64_encode("%PDF-1.4\nok")], 201)]);
        $request = CertificateRequest::factory()->for($this->requester, 'requester')->create();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/requests/{$request->id}/approve")->assertStatus(202);
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        $certificate = $request->refresh()->certificate;
        $this->assertSame(RequestStatus::Generated, $request->status);

        // Requester cannot download before release.
        Sanctum::actingAs($this->requester);
        $this->get("/api/v1/certificates/{$certificate->id}/download", ['Accept' => 'application/json'])->assertStatus(403);

        Sanctum::actingAs($this->custodian);
        $this->postJson("/api/v1/certificates/{$certificate->id}/verify", ['certificate_number' => 'WRONG'])->assertStatus(422);
        $this->postJson("/api/v1/certificates/{$certificate->id}/verify", ['certificate_number' => $certificate->certificate_number])
            ->assertOk()->assertJsonPath('data.status', 'verified');
        $this->postJson("/api/v1/certificates/{$certificate->id}/release")->assertOk()->assertJsonPath('data.status', 'released');

        Sanctum::actingAs($this->requester);
        $this->get("/api/v1/certificates/{$certificate->id}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/certificates/{$certificate->id}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');

        Sanctum::actingAs($this->custodian);
        $this->postJson("/api/v1/certificates/{$certificate->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_tampered_certificate_file_fails_verification(): void
    {
        Storage::fake('local');
        Http::fake(['generator.test/*' => Http::response(['pdf_base64' => base64_encode("%PDF-1.4\nok")], 201)]);
        $request = CertificateRequest::factory()->for($this->requester, 'requester')->create();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/requests/{$request->id}/approve");
        $this->artisan('queue:work', ['--stop-when-empty' => true]);
        $certificate = $request->refresh()->certificate;

        Storage::disk('local')->put($certificate->file_path, "%PDF-1.4\naltered");

        Sanctum::actingAs($this->custodian);
        $this->postJson("/api/v1/certificates/{$certificate->id}/verify", ['certificate_number' => $certificate->certificate_number])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $this->assertSame(RequestStatus::Generated, $request->refresh()->status);
    }

    public function test_requeue_only_allowed_from_needs_review(): void
    {
        Queue::fake();
        $request = CertificateRequest::factory()->for($this->requester, 'requester')
            ->create(['status' => RequestStatus::NeedsReview, 'generation_key' => 'k-1', 'review_reason' => 'x']);
        $busy = CertificateRequest::factory()->create(['status' => RequestStatus::Generating]);
        Sanctum::actingAs($this->approver);

        $this->postJson("/api/v1/requests/{$busy->id}/requeue")->assertStatus(409);
        $this->postJson("/api/v1/requests/{$request->id}/requeue", ['requester_id_number' => 'bad'])->assertStatus(422);
        $this->postJson("/api/v1/requests/{$request->id}/requeue", ['requester_id_number' => '2026-12345'])
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');

        $this->assertSame('k-1', $request->refresh()->generation_key); // same key kept
        Queue::assertPushed(GenerateCertificate::class, 1);
    }

    public function test_audit_log_is_insert_only(): void
    {
        $request = CertificateRequest::factory()->create();
        $log = app(\App\Services\CertificateWorkflow::class)->audit($request, 'test');

        $this->expectException(\LogicException::class);
        $log->update(['action' => 'changed']);
    }
}
