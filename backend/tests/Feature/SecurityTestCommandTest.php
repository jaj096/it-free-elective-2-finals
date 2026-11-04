<?php

namespace Tests\Feature;

use Tests\Support\GeneratorProcess;
use Tests\TestCase;

/**
 * Week 14: the failure-injection command, run against two real generator processes:
 * a normal one (crypto checks) and a strict one that demands HTTPS (plain-HTTP case).
 */
class SecurityTestCommandTest extends TestCase
{
    private static ?GeneratorProcess $receiver = null;

    private static ?GeneratorProcess $strict = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$receiver ??= new GeneratorProcess(SecureExchangeTest::receiverEnv());
        self::$strict ??= new GeneratorProcess(SecureExchangeTest::receiverEnv(['GENERATOR_REQUIRE_HTTPS' => 'true']));
        config([
            'certificates.generator.url' => self::$receiver->url,
            'certificates.generator.plain_http_url' => self::$strict->url,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$receiver?->stop();
        self::$strict?->stop();
        self::$receiver = self::$strict = null;
        parent::tearDownAfterClass();
    }

    public function test_all_cases_are_rejected_as_expected_and_saved_as_json(): void
    {
        // phpunit has no TLS, so the test receivers are http://. The sender guard is tested separately below.
        $json = tempnam(sys_get_temp_dir(), 'sec');

        $this->artisan('generator:security-test', ['--json' => $json])->assertSuccessful();

        $results = collect(json_decode(file_get_contents($json), true)['results'])->keyBy('case');
        $this->assertSame([
            'valid' => '201',
            'altered-ciphertext' => '400 DECRYPTION_FAILED',
            'bad-signature' => '401 BAD_SIGNATURE',
            'expired-timestamp' => '401 REQUEST_EXPIRED',
            'replayed-nonce' => '409 REPLAY_DETECTED',
            'wrong-key-id' => '401 UNKNOWN_KEY_ID',
            'plain-http' => '403 HTTPS_REQUIRED',
        ], $results->map(fn ($r) => $r['actual'])->all());
        $this->assertTrue($results->every(fn ($r) => $r['result'] === 'PASS'));

        $reasons = collect(self::$receiver->logLines())->where('event', 'security.rejected')->pluck('reason')->all();
        foreach (['DECRYPTION_FAILED', 'BAD_SIGNATURE', 'REQUEST_EXPIRED', 'REPLAY_DETECTED', 'UNKNOWN_KEY_ID'] as $code) {
            $this->assertContains($code, $reasons);
        }
        $this->assertSame('HTTPS_REQUIRED', collect(self::$strict->logLines())->last()['reason']);
    }

    public function test_plain_http_case_reports_that_the_sender_guard_blocked_it(): void
    {
        config(['certificates.generator.require_https' => true]);

        $this->artisan('generator:security-test', ['--case' => 'plain-http'])
            ->expectsOutputToContain('sender guard: blocked before sending')
            ->assertSuccessful();
    }

    public function test_command_fails_when_receiver_does_not_reject(): void
    {
        // A receiver that accepts plain HTTP must make the plain-http case FAIL.
        config(['certificates.generator.plain_http_url' => self::$receiver->url]);

        $this->artisan('generator:security-test', ['--case' => 'plain-http'])->assertFailed();
    }

    public function test_unknown_case_is_refused(): void
    {
        $this->artisan('generator:security-test', ['--case' => 'nope'])->assertExitCode(2);
    }
}
