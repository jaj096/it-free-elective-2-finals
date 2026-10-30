<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs the real GenerateCertificate job through a real (database) queue worker,
 * with the generator faked, to prove the retry and idempotency rules.
 */
class GenerationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        User::factory()->approver()->create();
        User::factory()->custodian()->create();
    }

    private function queuedRequest(array $userAttributes = []): CertificateRequest
    {
        return CertificateRequest::factory()
            ->for(User::factory()->create($userAttributes), 'requester')
            ->create([
                'status' => RequestStatus::Queued,
                'generation_key' => (string) Str::uuid(),
            ]);
    }

    private function pdfResponse(): array
    {
        return ['job_key' => 'x', 'replayed' => false, 'pdf_base64' => base64_encode("%PDF-1.4\n%test\n")];
    }

    private function runWorker(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    }

    public function test_normal_success_creates_exactly_one_certificate(): void
    {
        Http::fake(['generator.test/*' => Http::response($this->pdfResponse(), 201)]);
        $request = $this->queuedRequest();

        GenerateCertificate::dispatch($request->id);
        $this->runWorker();

        $request->refresh();
        $this->assertSame(RequestStatus::Generated, $request->status);
        $this->assertSame(1, $request->attempts);
        $this->assertSame(1, Certificate::where('certificate_request_id', $request->id)->count());
        Storage::disk('local')->assertExists($request->certificate->file_path);
        $this->assertSame(hash('sha256', "%PDF-1.4\n%test\n"), $request->certificate->sha256);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Service-Key', 'test-key') && $r['job_key'] === $request->generation_key);
    }

    public function test_transient_failure_is_retried_and_recovers(): void
    {
        Http::fakeSequence('generator.test/*')
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push($this->pdfResponse(), 201);
        $request = $this->queuedRequest();

        GenerateCertificate::dispatch($request->id);
        $this->runWorker();

        $request->refresh();
        $this->assertSame(RequestStatus::Generated, $request->status);
        $this->assertSame(3, $request->attempts);
        $this->assertSame(2, AuditLog::where('action', 'generation_retry_scheduled')->count());
        $this->assertSame(1, Certificate::count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_permanent_failure_goes_to_review_without_retry(): void
    {
        Http::fake(['generator.test/*' => Http::response(['error' => ['message' => 'Template unavailable']], 422)]);
        $request = $this->queuedRequest();

        GenerateCertificate::dispatch($request->id);
        $this->runWorker();

        $request->refresh();
        $this->assertSame(RequestStatus::NeedsReview, $request->status);
        $this->assertSame(1, $request->attempts);
        $this->assertStringContainsString('HTTP 422', $request->review_reason);
        Http::assertSentCount(1);
        $this->assertSame(0, Certificate::count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        // Approving staff were notified.
        $this->assertSame(1, DB::table('notifications')->where('data', 'like', '%needs_review%')->count());
    }

    public function test_retries_stop_after_max_attempts(): void
    {
        Http::fake(['generator.test/*' => Http::response(['error' => ['message' => 'down']], 503)]);
        $request = $this->queuedRequest();

        GenerateCertificate::dispatch($request->id);
        $this->runWorker();

        $request->refresh();
        $this->assertSame(RequestStatus::NeedsReview, $request->status);
        $this->assertSame(3, $request->attempts);
        Http::assertSentCount(3); // never a 4th attempt
        $this->assertStringContainsString('after 3 attempts', $request->review_reason);
    }

    public function test_missing_id_number_is_permanent_and_never_calls_generator(): void
    {
        Http::fake();
        $request = $this->queuedRequest(['id_number' => null]);

        GenerateCertificate::dispatch($request->id);
        $this->runWorker();

        $this->assertSame(RequestStatus::NeedsReview, $request->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_duplicate_jobs_produce_one_certificate(): void
    {
        Http::fake(['generator.test/*' => Http::response($this->pdfResponse(), 201)]);
        $request = $this->queuedRequest();

        GenerateCertificate::dispatch($request->id);
        GenerateCertificate::dispatch($request->id);
        GenerateCertificate::dispatch($request->id);
        $this->runWorker();

        $this->assertSame(1, Certificate::where('certificate_request_id', $request->id)->count());
        $this->assertSame(2, AuditLog::where('action', 'duplicate_skipped')->count());
        Http::assertSentCount(1);
    }

    public function test_sweeper_redispatches_then_flags_stuck_items(): void
    {
        Http::fake();
        $request = $this->queuedRequest();
        $request->forceFill(['status' => RequestStatus::Generating, 'status_changed_at' => now()->subHour()])->save();

        $this->artisan('certificates:sweep')->assertSuccessful();
        $request->refresh();
        $this->assertSame(RequestStatus::Queued, $request->status);
        $this->assertNotNull($request->sweeper_redispatched_at);
        $this->assertSame(1, DB::table('jobs')->count());

        // Still stuck later (worker never ran) -> human review.
        DB::table('jobs')->delete();
        $request->forceFill(['status_changed_at' => now()->subHour()])->save();
        $this->artisan('certificates:sweep')->assertSuccessful();
        $this->assertSame(RequestStatus::NeedsReview, $request->refresh()->status);
    }
}
