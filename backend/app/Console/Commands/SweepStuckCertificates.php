<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Jobs\GenerateCertificate;
use App\Models\CertificateRequest;
use App\Notifications\RequestUpdate;
use App\Services\CertificateWorkflow;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Safety net (scheduled every 10 minutes): finds requests stuck in queued, generating
 * or retrying, e.g. because the worker crashed. Each is re-dispatched once; if it is
 * still stuck on the next sweep it goes to needs_review. Nothing is silently lost.
 */
class SweepStuckCertificates extends Command
{
    protected $signature = 'certificates:sweep';

    protected $description = 'Re-dispatch or flag certificate requests stuck in the generation queue';

    public function handle(CertificateWorkflow $workflow, Notifier $notifier): int
    {
        Context::add('correlation_id', 'sweep-'.Str::lower(Str::random(8)));
        $cutoff = now()->subMinutes((int) config('certificates.sweeper.stuck_after_minutes'));

        $stuck = CertificateRequest::query()
            ->whereIn('status', [RequestStatus::Queued, RequestStatus::Generating, RequestStatus::Retrying])
            ->where('status_changed_at', '<', $cutoff)
            ->pluck('id');

        $redispatched = 0;
        $flagged = 0;

        foreach ($stuck as $id) {
            DB::transaction(function () use ($id, $cutoff, $workflow, $notifier, &$redispatched, &$flagged) {
                $request = CertificateRequest::whereKey($id)->lockForUpdate()->first();
                if (! $request || ! in_array($request->status, [RequestStatus::Queued, RequestStatus::Generating, RequestStatus::Retrying], true)
                    || $request->status_changed_at >= $cutoff) {
                    return;
                }

                if ($request->sweeper_redispatched_at === null) {
                    $request->sweeper_redispatched_at = now();
                    $request->save();
                    if ($request->status === RequestStatus::Queued) {
                        $request->status_changed_at = now();
                        $request->save();
                        $workflow->audit($request, 'sweeper_redispatched', reason: 'Stuck in queued; job dispatched again.');
                    } else {
                        $workflow->transition($request, RequestStatus::Queued, 'sweeper_redispatched',
                            reason: "Stuck in {$request->status->value}; job dispatched again.");
                    }
                    GenerateCertificate::dispatch($request->id)->afterCommit();
                    $redispatched++;

                    return;
                }

                $reason = 'Still stuck after an automatic re-dispatch. Check that the queue worker is running.';
                $request->review_reason = $reason;
                $workflow->transition($request, RequestStatus::NeedsReview, 'sent_to_review', reason: $reason);
                DB::afterCommit(fn () => $notifier->toRole(Role::Approver, $request, RequestUpdate::NEEDS_REVIEW,
                    "Request #{$request->id} needs review: {$reason}"));
                $flagged++;
            });
        }

        Log::channel('automation')->info('sweeper.completed', [
            'checked' => $stuck->count(), 'redispatched' => $redispatched, 'sent_to_review' => $flagged,
        ]);
        $this->info("Checked {$stuck->count()}, re-dispatched {$redispatched}, sent to review {$flagged}.");

        return self::SUCCESS;
    }
}
