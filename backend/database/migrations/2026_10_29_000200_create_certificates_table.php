<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            // UNIQUE: the database itself refuses a second certificate for the same request,
            // even if two workers race past the application-level check.
            $table->foreignId('certificate_request_id')->unique()->constrained('certificate_requests');
            $table->string('certificate_number', 30)->unique();
            $table->string('file_path');
            $table->char('sha256', 64);
            $table->timestamp('generated_at');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
