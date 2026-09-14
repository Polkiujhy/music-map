<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_export_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_playlist_id')->constrained('playlists')->cascadeOnDelete();
            $table->foreignId('target_playlist_id')->unique()->constrained('playlists')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('destination_type', 16);
            $table->string('target_account_id');
            $table->string('active_key', 64)->nullable()->unique();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'source_playlist_id']);
            $table->index(['provider', 'target_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_export_links');
    }
};
