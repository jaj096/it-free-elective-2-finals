<?php

namespace Tests\Feature;

use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Week 15 hardening sprint: one regression test per fixed weakness (W2-W6).
 * W1 (vulnerable dependency) is checked by `composer audit` in scripts/run-week15.sh.
 * Each test failed (or could not pass) on the code before the fix; see docs/week15/05-defect-records.md.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- W2 login timing

    public function test_w2_unknown_email_still_runs_a_password_hash_check(): void
    {
        Hash::spy();

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        // Same work as a real account, so response time does not reveal whether the email exists.
        Hash::shouldHaveReceived('check')->once();
    }

    public function test_w2_unknown_email_and_wrong_password_get_the_same_answer(): void
    {
        User::factory()->create(['email' => 'juan@example.test', 'password' => 'Password123!']);

        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x-wrong-1'])->assertStatus(401);
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'juan@example.test', 'password' => 'x-wrong-1'])->assertStatus(401);

        $this->assertSame($unknown->json('error.code'), $wrong->json('error.code'));
        $this->assertSame($unknown->json('error.message'), $wrong->json('error.message'));
    }

    public function test_w2_correct_password_still_logs_in(): void
    {
        User::factory()->create(['email' => 'juan@example.test', 'password' => 'Password123!']);

        $this->postJson('/api/v1/auth/login', ['email' => 'juan@example.test', 'password' => 'Password123!'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'expires_at']]);
    }

    // ---------------------------------------------------------------- W3 malformed JSON

    public function test_w3_malformed_json_gets_400_not_a_misleading_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->call('POST', '/api/v1/requests', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"certificate_type":"enrollment","purpose":');

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'MALFORMED_JSON')
            ->assertJsonMissingPath('error.fields');
        $this->assertSame(0, CertificateRequest::count());
    }

    public function test_w3_valid_json_and_empty_bodies_are_unaffected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/requests', ['certificate_type' => 'enrollment', 'purpose' => 'Scholarship application'])
            ->assertCreated();
        // An empty body is not malformed: it reaches validation as before.
        $this->postJson('/api/v1/requests')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    // ---------------------------------------------------------------- W4 security headers

    public function test_w4_hardening_headers_on_success_error_and_unknown_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ([
            $this->getJson('/api/v1/auth/me')->assertOk(),
            $this->getJson('/api/v1/requests/999999')->assertNotFound(),
            $this->getJson('/api/v1/no-such-route')->assertNotFound(),
        ] as $response) {
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $response->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeaderMissing('X-Powered-By');
            $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        }
    }

    public function test_w4_headers_do_not_touch_the_web_welcome_page(): void
    {
        $this->get('/')->assertOk()->assertHeaderMissing('Content-Security-Policy');
    }

    // ---------------------------------------------------------------- W5 pagination

    public function test_w5_request_list_is_paginated_with_totals(): void
    {
        $approver = User::factory()->approver()->create();
        CertificateRequest::factory()->count(5)->create();
        Sanctum::actingAs($approver);

        $page = $this->getJson('/api/v1/requests?per_page=2&page=3')->assertOk();

        $this->assertCount(1, $page->json('data'));
        $page->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.current_page', 3);
    }

    public function test_w5_more_than_200_requests_are_all_reachable(): void
    {
        $approver = User::factory()->approver()->create();
        CertificateRequest::factory()->count(230)->create();
        Sanctum::actingAs($approver);

        $first = $this->getJson('/api/v1/requests?per_page=100')->assertOk();
        $first->assertJsonPath('meta.total', 230)->assertJsonPath('meta.last_page', 3);

        $ids = collect([1, 2, 3])->flatMap(fn ($p) => $this->getJson("/api/v1/requests?per_page=100&page={$p}")->json('data.*.id'));
        $this->assertSame(230, $ids->unique()->count());
    }

    public function test_w5_page_size_is_bounded(): void
    {
        Sanctum::actingAs(User::factory()->approver()->create());

        $this->getJson('/api/v1/requests?per_page=1000')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->getJson('/api/v1/requests?per_page=0')->assertStatus(422);
    }

    // ---------------------------------------------------------------- W6 idempotency race

    /**
     * Forces the race deterministically: right after this request's "is the key used?" lookup misses,
     * a competing request with the same key is inserted (as if a parallel request won the race).
     * The insert then hits the UNIQUE index. Before the fix this was a 500.
     */
    public function test_w6_losing_a_duplicate_submit_race_returns_the_original_not_500(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $key = 'race-key-0001';

        $this->insertCompetitorAfterFirstLookup($user, $key, hash('sha256', json_encode(['enrollment', 'Scholarship application'])));

        $response = $this->postJson('/api/v1/requests',
            ['certificate_type' => 'enrollment', 'purpose' => 'Scholarship application'],
            ['Idempotency-Key' => $key]);

        $response->assertOk(); // 200 = "this is the request you already made"
        $this->assertSame(1, CertificateRequest::where('idempotency_key', $key)->count());
        $this->assertSame(CertificateRequest::where('idempotency_key', $key)->value('id'), $response->json('data.id'));
    }

    public function test_w6_losing_the_race_with_different_data_returns_409(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $key = 'race-key-0002';

        $this->insertCompetitorAfterFirstLookup($user, $key, hash('sha256', json_encode(['good_moral', 'Other data'])));

        $this->postJson('/api/v1/requests',
            ['certificate_type' => 'enrollment', 'purpose' => 'Scholarship application'],
            ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, CertificateRequest::where('idempotency_key', $key)->count());
    }

    private function insertCompetitorAfterFirstLookup(User $user, string $key, string $hash): void
    {
        $done = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$done, $user, $key, $hash) {
            if ($done || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'idempotency_key')) {
                return;
            }
            $done = true;
            DB::table('certificate_requests')->insert([
                'requester_id' => $user->id,
                'certificate_type' => 'enrollment',
                'purpose' => 'Competing request',
                'status' => 'submitted',
                'attempts' => 0,
                'idempotency_key' => $key,
                'idempotency_hash' => $hash,
                'status_changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
