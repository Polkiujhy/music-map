<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('export_review_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('playlist_export_id')->constrained()->restrictOnDelete();
            $table->enum('status', [
                'queued',
                'processing',
                'succeeded',
                'failed',
                'partial_failed',
                'manual_recovery_required',
                'recreate_required',
            ])->default('queued');
            $table->string('failure_code', 64)->nullable();
            $table->unsignedInteger('attempt_generation')->default(0);
            $table->unsignedInteger('retry_generation')->default(0);
            $table->unsignedTinyInteger('automatic_claim_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('job_publication_lease_until')->nullable();
            $table->timestamp('job_published_at')->nullable();
            $table->timestamp('possible_mutation_at')->nullable();
            $table->timestamp('retry_available_at')->nullable();
            $table->timestamp('manual_recovery_requested_at')->nullable();
            $table->unsignedInteger('notification_generation')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'job_publication_lease_until']);
            $table->index(['status', 'heartbeat_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_operations');
    }
};
