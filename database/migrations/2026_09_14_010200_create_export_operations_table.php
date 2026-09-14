<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('export_review_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('source_playlist_id')->constrained('playlists')->cascadeOnDelete();
            $table->foreignId('playlist_export_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('streaming_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_provider', 32);
            $table->string('destination_type', 16);
            $table->string('target_account_id');
            $table->string('target_market', 2)->nullable();
            $table->string('playlist_name')->nullable();
            $table->text('playlist_description')->nullable();
            $table->string('source_fingerprint', 64);
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('active_key', 64)->nullable()->unique();
            $table->timestamp('provider_mutation_started_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['source_playlist_id', 'target_provider', 'target_account_id'], 'export_operations_destination_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_operations');
    }
};
