<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CertificateRequestController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReviewQueueController;
use Illuminate\Support\Facades\Route;

// All routes are prefixed with /api/v1 (see bootstrap/app.php).

Route::get('/health', HealthController::class);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Requests: listing and detail are filtered by ownership inside the controller.
    Route::get('/requests', [CertificateRequestController::class, 'index']);
    Route::get('/requests/{certificateRequest}', [CertificateRequestController::class, 'show']);
    Route::post('/requests', [CertificateRequestController::class, 'store'])->middleware('role:requester');

    Route::middleware('role:approver')->group(function () {
        Route::post('/requests/{certificateRequest}/approve', [CertificateRequestController::class, 'approve']);
        Route::post('/requests/{certificateRequest}/reject', [CertificateRequestController::class, 'reject']);
        Route::get('/review-queue', [ReviewQueueController::class, 'index']);
        Route::post('/requests/{certificateRequest}/requeue', [ReviewQueueController::class, 'requeue']);
        Route::post('/requests/{certificateRequest}/cancel', [ReviewQueueController::class, 'cancel']);
    });

    Route::middleware('role:custodian')->group(function () {
        Route::post('/certificates/{certificate}/verify', [CertificateController::class, 'verify']);
        Route::post('/certificates/{certificate}/release', [CertificateController::class, 'release']);
        Route::post('/certificates/{certificate}/archive', [CertificateController::class, 'archive']);
    });

    // Download and acknowledge check ownership/role inside the controller.
    Route::get('/certificates/{certificate}/download', [CertificateController::class, 'download']);
    Route::post('/certificates/{certificate}/acknowledge', [CertificateController::class, 'acknowledge'])->middleware('role:requester');

    Route::get('/automation/jobs', [AutomationController::class, 'index'])->middleware('role:approver,custodian');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead']);
});
