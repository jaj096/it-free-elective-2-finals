<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users');
            $table->string('certificate_type', 30);
            $table->string('purpose', 255);
            $table->string('status', 20)->default('submitted')->index();

            // Client-supplied Idempotency-Key header: stops double-submits creating two requests.
            $table->string('idempotency_key', 64)->nullable();
            $table->string('idempotency_hash', 64)->nullable();
            $table->unique(['requester_id', 'idempotency_key']);

            // Server-generated key for the generation job. UNIQUE so it can be sent to the
            // generator as job_key and so a re-queued item keeps the same key.
            $table->uuid('generation_key')->nullable()->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('review_reason', 255)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sweeper_redispatched_at')->nullable();
            $table->timestamp('status_changed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_requests');
    }
};
