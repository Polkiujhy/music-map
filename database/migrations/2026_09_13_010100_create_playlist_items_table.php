<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->string('occurrence_id')->nullable();
            $table->string('catalog_id')->nullable();
            $table->string('catalog_uri')->nullable();
            $table->string('title')->nullable();
            $table->json('creators');
            $table->string('album')->nullable();
            $table->integer('duration_milliseconds')->nullable();
            $table->string('isrc', 32)->nullable();
            $table->boolean('is_available');

            $table->unique(['playlist_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
    }
};
