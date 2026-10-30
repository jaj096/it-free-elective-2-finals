<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\CertificateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Liveness plus a few readiness signals. Public, so it returns counts only:
 * no names, keys or stack traces.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (Throwable) {
            $checks['database'] = 'down';
        }

        try {
            $generator = Http::timeout(2)->get(rtrim((string) config('certificates.generator.url'), '/').'/health');
            $checks['generator'] = $generator->successful() ? 'ok' : 'degraded';
        } catch (Throwable) {
            $checks['generator'] = 'down';
        }

        $metrics = [];
        if ($checks['database'] === 'ok') {
            $metrics = [
                'queue_depth' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'needs_review' => CertificateRequest::where('status', RequestStatus::NeedsReview)->count(),
            ];
        }

        $healthy = $checks['database'] === 'ok';

        return response()->json([
            'status' => $healthy ? ($checks['generator'] === 'ok' ? 'ok' : 'degraded') : 'down',
            'checks' => $checks,
            'metrics' => $metrics,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
