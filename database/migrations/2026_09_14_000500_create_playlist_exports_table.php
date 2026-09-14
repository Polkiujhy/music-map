<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_playlist_id')->constrained('playlists')->restrictOnDelete();
            $table->string('target_provider', 32);
            $table->string('destination_type', 16);
            $table->foreignId('streaming_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_account_id');
            $table->string('target_market', 2)->nullable();
            $table->unsignedInteger('target_generation')->default(1);
            $table->foreignId('target_playlist_id')->nullable()->unique()->constrained('playlists')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['source_playlist_id', 'target_provider', 'target_account_id'],
                'playlist_exports_source_target_unique',
            );
            $table->unique(
                ['id', 'target_provider', 'target_account_id'],
                'playlist_exports_attempt_parent_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_exports');
    }
};
