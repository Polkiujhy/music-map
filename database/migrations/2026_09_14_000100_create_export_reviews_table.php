<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('streaming_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_provider', 32);
            $table->string('destination_type', 16);
            $table->string('target_account_id');
            $table->string('target_market', 2)->nullable();
            $table->string('source_fingerprint', 64);
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->uuid('correlation_id')->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['playlist_id', 'status']);
            $table->index(['target_provider', 'target_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_reviews');
    }
};
