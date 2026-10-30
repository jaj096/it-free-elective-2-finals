<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CertificateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Read-only view of the automation for staff: recent job events and simple metrics. */
class AutomationController extends Controller
{
    public function index(): JsonResponse
    {
        $events = AuditLog::query()
            ->where('actor_type', 'system')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'request_id' => $log->certificate_request_id,
                'action' => $log->action,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'attempt' => $log->attempt,
                'reason' => $log->reason,
                'correlation_id' => $log->correlation_id,
                'at' => $log->created_at?->toIso8601String(),
            ]);

        $byStatus = CertificateRequest::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $attemptsFailed = AuditLog::where('action', 'generation_retry_scheduled')->count();
        $succeeded = AuditLog::where('action', 'generation_succeeded')->count();
        $sentToReview = AuditLog::where('action', 'sent_to_review')->count();
        $duplicates = AuditLog::where('action', 'duplicate_skipped')->count();

        return response()->json([
            'data' => [
                'metrics' => [
                    'queue_depth' => DB::table('jobs')->count(),
                    'failed_jobs' => DB::table('failed_jobs')->count(),
                    'needs_review' => (int) ($byStatus[RequestStatus::NeedsReview->value] ?? 0),
                    'generated_total' => $succeeded,
                    'retries_total' => $attemptsFailed,
                    'sent_to_review_total' => $sentToReview,
                    'duplicates_skipped_total' => $duplicates,
                    'by_status' => $byStatus,
                ],
                'events' => $events,
            ],
        ]);
    }
}
