<?php

use App\Enums\PlaylistSyncStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_synchronizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playlist_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('streaming_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default(PlaylistSyncStatus::PendingConfirmation->value);
            $table->boolean('automatic_enabled')->default(false);
            $table->string('baseline_bank_fingerprint', 64)->nullable();
            $table->string('baseline_source_fingerprint', 64)->nullable();
            $table->string('baseline_provider_revision')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->string('last_outcome', 32)->nullable();
            $table->string('last_failure_code', 64)->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'automatic_enabled', 'next_check_at'],
                'playlist_sync_due_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_synchronizations');
    }
};
