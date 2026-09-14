<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playlist_synchronization_id')->constrained()->cascadeOnDelete();
            $table->string('operation_id', 255)->unique();
            $table->string('trigger', 32);
            $table->string('direction', 16);
            $table->string('state', 32);
            $table->string('input_bank_fingerprint', 64);
            $table->string('input_source_fingerprint', 64);
            $table->string('input_provider_revision')->nullable();
            $table->json('bank_snapshot');
            $table->json('source_snapshot');
            $table->string('desired_fingerprint', 64)->nullable();
            $table->json('checkpoint')->nullable();
            $table->timestamps();

            $table->index(
                ['playlist_synchronization_id', 'state'],
                'playlist_sync_active_run_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_sync_runs');
    }
};
