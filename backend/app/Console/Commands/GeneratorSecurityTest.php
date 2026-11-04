<?php

namespace App\Console\Commands;

use App\Exceptions\GeneratorException;
use App\Security\SecureRequestBuilder;
use App\Security\Tamper;
use App\Services\GeneratorClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Week 14 failure injection: sends deliberately broken requests to the LIVE generator
 * and checks that each one is rejected with the expected status and code.
 *
 *   php artisan generator:security-test                 all cases
 *   php artisan generator:security-test --case=replayed-nonce
 *   php artisan generator:security-test --json=../evidence/week14/security-test.json
 *
 * Uses fictional data only. Exit code 1 if any case does not behave as expected.
 */
class GeneratorSecurityTest extends Command
{
    protected $signature = 'generator:security-test
        {--case=all : valid|altered-ciphertext|bad-signature|expired-timestamp|replayed-nonce|wrong-key-id|plain-http|all}
        {--json= : Also write the results to this JSON file}';

    protected $description = 'Send tampered requests to the generator and check they are rejected';

    /** case => [expected HTTP status, expected error code] */
    private const CASES = [
        'valid' => [201, null],
        'altered-ciphertext' => [400, 'DECRYPTION_FAILED'],
        'bad-signature' => [401, 'BAD_SIGNATURE'],
        'expired-timestamp' => [401, 'REQUEST_EXPIRED'],
        'replayed-nonce' => [409, 'REPLAY_DETECTED'],
        'wrong-key-id' => [401, 'UNKNOWN_KEY_ID'],
        'plain-http' => [403, 'HTTPS_REQUIRED'],
    ];

    public function handle(SecureRequestBuilder $builder, GeneratorClient $client): int
    {
        $selected = (string) $this->option('case');
        $cases = $selected === 'all' ? array_keys(self::CASES) : [$selected];
        if (array_diff($cases, array_keys(self::CASES))) {
            $this->error('Unknown case. Use one of: '.implode(', ', array_keys(self::CASES)).', all');

            return self::INVALID;
        }

        Context::add('correlation_id', 'sec-test-'.Str::lower(Str::random(6)));
        $this->info('Target: '.config('certificates.generator.url').'  (correlation ID '.Context::get('correlation_id').')');

        $results = [];
        foreach ($cases as $case) {
            [$wantStatus, $wantCode] = self::CASES[$case];
            [$status, $code, $note] = $this->runCase($case, $builder, $client);
            $pass = $status === $wantStatus && $code === $wantCode;
            $results[] = [
                'case' => $case,
                'expected' => trim("{$wantStatus} {$wantCode}"),
                'actual' => trim(($status ?? 'no response').' '.$code),
                'result' => $pass ? 'PASS' : 'FAIL',
                'note' => $note,
            ];
            Log::channel('automation')->info('security_test.case', ['case' => $case, 'http_status' => $status, 'code' => $code, 'result' => $pass ? 'pass' : 'fail']);
        }

        $this->table(['Case', 'Expected', 'Actual', 'Result', 'Note'], $results);

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode([
                'run_at' => now()->toIso8601String(),
                'target' => config('certificates.generator.url'),
                'correlation_id' => Context::get('correlation_id'),
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->line("Saved {$path}");
        }

        return collect($results)->every(fn ($r) => $r['result'] === 'PASS') ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: ?int, 1: ?string, 2: string} status, error code, note */
    private function runCase(string $case, SecureRequestBuilder $builder, GeneratorClient $client): array
    {
        $fields = [
            'job_key' => 'sectest-'.Str::uuid(),
            'certificate_type' => 'enrollment',
            'purpose' => 'Week 14 security test',
            'requester_name' => 'Test Person (fictional)',
            'requester_id_number' => '0000-00000',
        ];

        try {
            switch ($case) {
                case 'replayed-nonce':
                    $request = $builder->build($fields);
                    $first = $client->send($request);
                    $second = $client->send($request); // exact same bytes, same nonce

                    return [$second->status(), $second->json('error.code'), "first send: {$first->status()}"];

                case 'plain-http':
                    // 1) The sender itself refuses plain HTTP when GENERATOR_REQUIRE_HTTPS=true.
                    $plainUrl = (string) config('certificates.generator.plain_http_url');
                    $request = $builder->build($fields);
                    $senderNote = 'sender guard: off';
                    if (config('certificates.generator.require_https')) {
                        try {
                            $client->send($request, $plainUrl);
                            $senderNote = 'sender guard: DID NOT BLOCK';
                        } catch (GeneratorException) {
                            $senderNote = 'sender guard: blocked before sending';
                        }
                    }
                    // 2) Bypass the guard on purpose: the receiver must refuse it too.
                    $response = $client->send($request, $plainUrl, allowPlainHttp: true);

                    return [$response->status(), $response->json('error.code'), "{$senderNote}; sent to {$plainUrl}"];

                default:
                    $tamper = $case === 'valid' ? null : Tamper::from($case);
                    $response = $client->send($builder->build($fields, $tamper));

                    return [$response->status(), $response->json('error.code'), $case === 'valid' ? 'control: must succeed' : ''];
            }
        } catch (GeneratorException $e) {
            return [null, null, $e->safeReason];
        } catch (ConnectionException $e) {
            // Includes TLS verification failures; message shows the reason without secrets.
            return [null, null, 'connection failed: '.Str::limit($e->getMessage(), 120)];
        }
    }
}
