<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCertificate;
use App\Models\CertificateRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Failure-injection helper for the duplicate-delivery test (evidence run 5).
 * Pushes the same generation job several times; the idempotency controls must
 * make every extra copy log "duplicate_skipped" and leave exactly one certificate.
 */
class DispatchDuplicateJobs extends Command
{
    protected $signature = 'certificates:dispatch-duplicate {request : Request ID} {--times=2 : How many copies to dispatch}';

    protected $description = 'Dispatch the same generation job several times to prove idempotency';

    public function handle(): int
    {
        $request = CertificateRequest::findOrFail($this->argument('request'));
        $times = max(1, (int) $this->option('times'));
        Context::add('correlation_id', 'dup-test-'.Str::lower(Str::random(6)));

        for ($i = 0; $i < $times; $i++) {
            GenerateCertificate::dispatch($request->id);
        }

        $this->info("Dispatched {$times} copies of the job for request #{$request->id} (status: {$request->status->value}).");
        $this->line('Run the worker, then check: certificates for this request must be exactly 1.');

        return self::SUCCESS;
    }
}
