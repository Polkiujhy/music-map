<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_export_target_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('playlist_export_id');
            $table->string('target_provider', 32);
            $table->string('target_account_id');
            $table->unsignedInteger('generation');
            $table->uuid('marker')->unique();
            $table->enum('status', ['pending', 'unknown', 'resolved', 'abandoned'])->default('pending');
            $table->timestamp('create_started_at')->nullable();
            $table->timestamp('create_completed_at')->nullable();
            $table->string('provider_playlist_id')->nullable();
            $table->string('canonical_url', 512)->nullable();
            $table->timestamps();

            $table->foreign(
                ['playlist_export_id', 'target_provider', 'target_account_id'],
                'playlist_export_attempts_parent_foreign',
            )->references(['id', 'target_provider', 'target_account_id'])
                ->on('playlist_exports')
                ->restrictOnDelete();
            $table->unique(
                ['playlist_export_id', 'generation'],
                'playlist_export_attempts_generation_unique',
            );
            $table->unique(
                ['target_provider', 'target_account_id', 'provider_playlist_id'],
                'playlist_export_attempts_locator_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_export_target_attempts');
    }
};
