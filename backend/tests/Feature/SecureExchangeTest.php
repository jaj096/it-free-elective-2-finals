<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Exceptions\GeneratorException;
use App\Jobs\GenerateCertificate;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\User;
use App\Security\GeneratorKeyring;
use App\Security\PayloadEncryptor;
use App\Security\RequestSigner;
use App\Security\SecureRequestBuilder;
use App\Security\SignedRequest;
use App\Security\Tamper;
use App\Services\GeneratorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GeneratorProcess;
use Tests\TestCase;

/**
 * Week 14: sender (Laravel GeneratorClient) <-> receiver (real generator-service process).
 * Nothing is faked here: real encryption, real signatures, real HTTP.
 */
class SecureExchangeTest extends TestCase
{
    use RefreshDatabase;

    private const ENC = 'base64:VEVTVC1FTkNSWVBUSU9OLUtFWS0wMDAwMDAwMDAwMDA=';

    private const SIGN = 'base64:VEVTVC1TSUdOSU5HLUtFWS0wMDAwMDAwMDAwMDAwMDA=';

    private const NAME = 'Juan Dela Cruz';

    private const ID_NUMBER = '2026-00001';

    private static ?GeneratorProcess $receiver = null;

    public static function receiverEnv(array $overrides = []): array
    {
        return $overrides + [
            'GENERATOR_MODE' => 'success',
            'GENERATOR_REQUIRE_HTTPS' => 'false', // no TLS inside phpunit; HTTPS is tested separately below
            'GENERATOR_ACCEPTED_KEY_IDS' => 'ktest',
            'GENERATOR_ENCRYPTION_KEY_ktest' => self::ENC,
            'GENERATOR_SIGNING_KEY_ktest' => self::SIGN,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$receiver ??= new GeneratorProcess(self::receiverEnv());
        self::$receiver->configure(self::receiverEnv());
        config(['certificates.generator.url' => self::$receiver->url]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$receiver?->stop();
        self::$receiver = null;
        parent::tearDownAfterClass();
    }

    private function fields(array $overrides = []): array
    {
        return $overrides + [
            'job_key' => (string) Str::uuid(),
            'certificate_type' => 'enrollment',
            'purpose' => 'Scholarship application',
            'requester_name' => self::NAME,
            'requester_id_number' => self::ID_NUMBER,
        ];
    }

    private function send(SignedRequest $request): \Illuminate\Http\Client\Response
    {
        return app(GeneratorClient::class)->send($request);
    }

    private function build(?Tamper $tamper = null): SignedRequest
    {
        return app(SecureRequestBuilder::class)->build($this->fields(), $tamper);
    }

    private function assertRejected(SignedRequest $request, int $status, string $code): void
    {
        $response = $this->send($request);
        $this->assertSame($status, $response->status(), $response->body());
        $this->assertSame($code, $response->json('error.code'));
        $this->assertNull($response->json('pdf_base64'));

        $last = collect(self::$receiver->logLines())->last();
        $this->assertSame('security.rejected', $last['event']);
        $this->assertSame($code, $last['reason']);
    }

    public function test_valid_request_is_accepted_and_personal_data_is_encrypted_on_the_wire(): void
    {
        $request = $this->build();

        // Minimised: only these top-level fields leave the backend.
        $body = json_decode($request->body, true);
        $this->assertSame(['v', 'job_key', 'certificate_type', 'purpose', 'protected'], array_keys($body));
        $this->assertSame(['alg', 'kid', 'nonce', 'ciphertext'], array_keys($body['protected']));
        // Confidential: the name and ID number never appear in plaintext.
        $this->assertStringNotContainsString(self::NAME, $request->body);
        $this->assertStringNotContainsString(self::ID_NUMBER, $request->body);
        foreach (['X-Key-Id', 'X-Timestamp', 'X-Nonce', 'X-Content-SHA256', 'X-Signature'] as $header) {
            $this->assertArrayHasKey($header, $request->headers);
        }

        $response = $this->send($request);
        $this->assertSame(201, $response->status(), $response->body());
        $pdf = base64_decode($response->json('pdf_base64'));
        $this->assertStringStartsWith('%PDF', $pdf);
        // The receiver could decrypt: the name is printed on the certificate.
        $this->assertStringContainsString(self::NAME, $pdf);
    }

    public function test_altered_ciphertext_is_rejected(): void
    {
        $this->assertRejected($this->build(Tamper::AlteredCiphertext), 400, 'DECRYPTION_FAILED');
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->assertRejected($this->build(Tamper::BadSignature), 401, 'BAD_SIGNATURE');
    }

    public function test_body_changed_after_signing_is_rejected(): void
    {
        $request = $this->build();
        $altered = str_replace('Scholarship application', 'Scholarship applicatioN', $request->body);

        $this->assertRejected(new SignedRequest('POST', '/generate', $request->headers, $altered), 401, 'BAD_SIGNATURE');
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        $this->assertRejected($this->build(Tamper::ExpiredTimestamp), 401, 'REQUEST_EXPIRED');
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $request = $this->build();

        $this->assertSame(201, $this->send($request)->status());
        $this->assertRejected($request, 409, 'REPLAY_DETECTED');
    }

    public function test_wrong_key_id_is_rejected(): void
    {
        $this->assertRejected($this->build(Tamper::WrongKeyId), 401, 'UNKNOWN_KEY_ID');
    }

    public function test_unsigned_request_is_rejected(): void
    {
        $request = $this->build();

        $this->assertRejected(new SignedRequest('POST', '/generate', [], $request->body), 401, 'MISSING_SIGNATURE');
    }

    public function test_ciphertext_moved_to_another_job_fails_decryption(): void
    {
        // An insider with the signing key copies Juan's ciphertext into another job and re-signs it.
        // The associated data (job_key) no longer matches, so the AEAD tag check fails.
        $body = json_decode($this->build()->body, true);
        $body['job_key'] = (string) Str::uuid();
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = RequestSigner::headers('POST', '/generate', $raw, GeneratorKeyring::fromConfig());

        $this->assertRejected(new SignedRequest('POST', '/generate', $headers, $raw), 400, 'DECRYPTION_FAILED');
    }

    public function test_plain_http_is_refused_by_sender_and_by_receiver(): void
    {
        // Receiver: a strict generator (GENERATOR_REQUIRE_HTTPS=true) rejects a plain-HTTP request.
        $strict = new GeneratorProcess(self::receiverEnv(['GENERATOR_REQUIRE_HTTPS' => 'true']));
        try {
            $response = app(GeneratorClient::class)->send($this->build(), $strict->url, allowPlainHttp: true);
            $this->assertSame(403, $response->status());
            $this->assertSame('HTTPS_REQUIRED', $response->json('error.code'));
            $this->assertSame('HTTPS_REQUIRED', collect($strict->logLines())->last()['reason']);
        } finally {
            $strict->stop();
        }

        // Sender: with HTTPS required, the client refuses before any byte is sent.
        config(['certificates.generator.require_https' => true]);
        Http::fake();
        try {
            app(GeneratorClient::class)->send($this->build(), 'http://127.0.0.1:1');
            $this->fail('Plain HTTP should have been refused.');
        } catch (GeneratorException $e) {
            $this->assertFalse($e->transient);
            $this->assertStringContainsString('plain HTTP', $e->safeReason);
        }
        Http::assertNothingSent();
    }

    public function test_rejection_logs_contain_no_keys_signatures_or_personal_data(): void
    {
        foreach (Tamper::cases() as $tamper) {
            $this->send($this->build($tamper));
        }
        $request = $this->build();
        $this->send($request);
        $this->send($request); // replay

        $log = self::$receiver->rawLog();
        $this->assertStringContainsString('security.rejected', $log);
        foreach ([self::NAME, self::ID_NUMBER, self::ENC, self::SIGN, substr(self::ENC, 7), substr(self::SIGN, 7),
            $request->headers['X-Signature'], $request->headers['X-Nonce'], json_decode($request->body, true)['protected']['ciphertext']] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    public function test_key_rotation_old_key_works_while_listed_and_fails_after_removal(): void
    {
        $newEnc = 'base64:'.base64_encode(random_bytes(32));
        $newSign = 'base64:'.base64_encode(random_bytes(32));

        // Step 1: the receiver accepts both the old and the new key ID.
        self::$receiver->configure(self::receiverEnv([
            'GENERATOR_ACCEPTED_KEY_IDS' => 'ktest,k2',
            'GENERATOR_ENCRYPTION_KEY_k2' => $newEnc,
            'GENERATOR_SIGNING_KEY_k2' => $newSign,
        ]));
        $this->assertSame(201, $this->send($this->build())->status()); // sender still on the old key

        // Step 2: the sender switches to the new key.
        config(['certificates.generator.key_id' => 'k2', 'certificates.generator.encryption_key' => $newEnc, 'certificates.generator.signing_key' => $newSign]);
        $this->assertSame(201, $this->send($this->build())->status());

        // Step 3: the old key is removed (revoked). Anything still signed with it is rejected.
        self::$receiver->configure(self::receiverEnv([
            'GENERATOR_ACCEPTED_KEY_IDS' => 'k2',
            'GENERATOR_ENCRYPTION_KEY_k2' => $newEnc,
            'GENERATOR_SIGNING_KEY_k2' => $newSign,
        ]));
        config(['certificates.generator.key_id' => 'ktest', 'certificates.generator.encryption_key' => self::ENC, 'certificates.generator.signing_key' => self::SIGN]);
        $this->assertRejected($this->build(), 401, 'UNKNOWN_KEY_ID');
    }

    public function test_missing_or_reused_keys_fail_safely_without_calling_the_generator(): void
    {
        Http::fake();
        config(['certificates.generator.signing_key' => self::ENC]); // same as encryption key

        try {
            $this->build();
            $this->fail('Reused key should be refused.');
        } catch (GeneratorException $e) {
            $this->assertStringContainsString('must be different', $e->safeReason);
            $this->assertStringNotContainsString(substr(self::ENC, 7), $e->safeReason);
        }

        config(['certificates.generator.encryption_key' => null]);
        $this->expectException(GeneratorException::class);
        $this->build();
    }

    public function test_full_job_generates_through_the_real_receiver(): void
    {
        Storage::fake('local');
        User::factory()->approver()->create();
        User::factory()->custodian()->create();
        $request = CertificateRequest::factory()
            ->for(User::factory()->create(['name' => self::NAME, 'id_number' => self::ID_NUMBER]), 'requester')
            ->create(['status' => RequestStatus::Queued, 'generation_key' => (string) Str::uuid()]);

        GenerateCertificate::dispatch($request->id);
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame(RequestStatus::Generated, $request->refresh()->status);
        $this->assertSame(1, Certificate::count());
        $this->assertSame('generate.succeeded', collect(self::$receiver->logLines())->last()['event']);
    }

    public function test_security_rejection_sends_job_to_review_without_retry(): void
    {
        Storage::fake('local');
        User::factory()->approver()->create();
        // The receiver no longer accepts the backend's key ID (e.g. the key was revoked).
        self::$receiver->configure(self::receiverEnv(['GENERATOR_ACCEPTED_KEY_IDS' => 'kother']));
        $request = CertificateRequest::factory()->for(User::factory()->create(), 'requester')
            ->create(['status' => RequestStatus::Queued, 'generation_key' => (string) Str::uuid()]);

        GenerateCertificate::dispatch($request->id);
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

        $request->refresh();
        $this->assertSame(RequestStatus::NeedsReview, $request->status);
        $this->assertSame(1, $request->attempts);
        $this->assertStringContainsString('UNKNOWN_KEY_ID', $request->review_reason);
    }

    public function test_payload_encryptor_round_trip_and_tamper_detection(): void
    {
        $keys = GeneratorKeyring::fromConfig();
        $aad = PayloadEncryptor::associatedData('job-1', 'enrollment', 'Purpose');
        $sealed = PayloadEncryptor::seal(['requester_name' => self::NAME], $aad, $keys);

        $this->assertSame(['requester_name' => self::NAME], PayloadEncryptor::open($sealed, $aad, $keys));
        $this->assertNull(PayloadEncryptor::open($sealed, PayloadEncryptor::associatedData('job-2', 'enrollment', 'Purpose'), $keys));
        // Two encryptions of the same data look different (random nonce).
        $this->assertNotSame($sealed['ciphertext'], PayloadEncryptor::seal(['requester_name' => self::NAME], $aad, $keys)['ciphertext']);
        // Keys are hidden from debug output.
        $this->assertStringNotContainsString($keys->encryptionKey, print_r($keys, true));
        $this->assertStringNotContainsString($keys->signingKey, print_r($keys, true));
    }
}
