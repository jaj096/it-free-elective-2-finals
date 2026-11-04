<?php

namespace Tests\Feature;

use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Week 14 regression checks: the Week 13 API controls still hold after the security changes,
 * plus token expiry and HTTPS-only access.
 */
class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user, $expiresAt): string
    {
        return $user->createToken('flutter-web', ['*'], $expiresAt)->plainTextToken;
    }

    public function test_expired_sanctum_token_is_rejected_with_token_expired(): void
    {
        $user = User::factory()->create();
        $expired = $this->token($user, now()->subMinute());

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$expired}"])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_EXPIRED')
            ->assertJsonPath('error.message', 'Your session has expired. Please sign in again.');
    }

    public function test_token_works_until_it_expires(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user, now()->addHours(8));

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->travel(8)->hours();
        $this->travel(1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401)->assertJsonPath('error.code', 'TOKEN_EXPIRED');
    }

    public function test_login_issues_a_token_that_expires_in_eight_hours(): void
    {
        User::factory()->create(['email' => 'juan@example.test', 'password' => 'Password123!']);

        $expiresAt = $this->postJson('/api/v1/auth/login', ['email' => 'juan@example.test', 'password' => 'Password123!'])
            ->assertOk()->json('data.expires_at');

        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, strtotime($expiresAt), 5);
    }

    public function test_invalid_token_is_plain_unauthenticated(): void
    {
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer 999|not-a-real-token'])
            ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user, now()->addHour());

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_plain_http_is_refused_when_https_is_required(): void
    {
        config(['app.require_https' => true]);

        $this->getJson('http://localhost/api/v1/health')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'HTTPS_REQUIRED');
        // Login over plain HTTP is refused too, so a password is never accepted in clear.
        $this->postJson('http://localhost/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'x'])
            ->assertStatus(403);
    }

    public function test_https_and_trusted_local_proxy_are_accepted(): void
    {
        config(['app.require_https' => true]);

        $this->getJson('https://localhost/api/v1/health')->assertOk();
        // Caddy on 127.0.0.1 terminates TLS and forwards with X-Forwarded-Proto: https.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('http://localhost/api/v1/health', ['X-Forwarded-Proto' => 'https'])->assertOk();
    }

    public function test_forwarded_proto_from_an_untrusted_address_is_ignored(): void
    {
        config(['app.require_https' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('http://localhost/api/v1/health', ['X-Forwarded-Proto' => 'https'])
            ->assertStatus(403);
    }

    public function test_role_checks_validation_and_safe_errors_still_apply(): void
    {
        $requester = User::factory()->create();
        $other = CertificateRequest::factory()->create();
        $token = $this->token($requester, now()->addHour());
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson("/api/v1/requests/{$other->id}/approve", [], $auth)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson("/api/v1/requests/{$other->id}", $auth)->assertStatus(404);
        $this->postJson('/api/v1/requests', ['certificate_type' => 'bogus', 'purpose' => 'x'], $auth)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'correlation_id']])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_download_sends_hash_and_refuses_an_altered_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $custodian = User::factory()->custodian()->create();
        $request = CertificateRequest::factory()->create(['status' => \App\Enums\RequestStatus::Generated]);
        \Illuminate\Support\Facades\Storage::disk('local')->put('certificates/T-1.pdf', "%PDF-1.4\nok");
        $certificate = \App\Models\Certificate::create([
            'certificate_request_id' => $request->id, 'certificate_number' => 'T-1', 'file_path' => 'certificates/T-1.pdf',
            'sha256' => hash('sha256', "%PDF-1.4\nok"), 'generated_at' => now(),
        ]);
        $auth = ['Authorization' => 'Bearer '.$this->token($custodian, now()->addHour())];

        $this->get("/api/v1/certificates/{$certificate->id}/download", $auth)
            ->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', "%PDF-1.4\nok"));

        \Illuminate\Support\Facades\Storage::disk('local')->put('certificates/T-1.pdf', "%PDF-1.4\naltered");
        $this->getJson("/api/v1/certificates/{$certificate->id}/download", $auth)
            ->assertStatus(409)->assertJsonPath('error.code', 'INTEGRITY_FAILED');
    }
}
