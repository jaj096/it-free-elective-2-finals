<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Insert-only trail of every status change. No endpoint updates or deletes rows.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_request_id')->nullable()->constrained('certificate_requests');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('actor_type', 10); // user | system
            $table->string('action', 50);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->unsignedTinyInteger('attempt')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
