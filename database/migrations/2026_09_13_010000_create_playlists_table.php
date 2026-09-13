<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_provider', 32);
            $table->string('source_playlist_id');
            $table->string('source_account_id')->nullable();
            $table->string('canonical_source_url', 512);
            $table->string('provider_revision')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('provider_metadata_refreshed_at');
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique(['user_id', 'source_provider', 'source_playlist_id']);
            $table->index(['user_id', 'imported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};
