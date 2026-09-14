<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('export_review_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->string('source_occurrence_id')->nullable();
            $table->string('source_catalog_id')->nullable();
            $table->string('source_catalog_uri')->nullable();
            $table->string('source_title')->nullable();
            $table->json('source_creators');
            $table->string('source_album')->nullable();
            $table->integer('source_duration_milliseconds')->nullable();
            $table->string('source_isrc', 32)->nullable();
            $table->boolean('source_is_available');
            $table->string('match_status', 16);
            $table->string('target_catalog_id')->nullable();
            $table->string('target_catalog_uri')->nullable();
            $table->string('target_title')->nullable();
            $table->json('target_creators')->nullable();
            $table->string('target_album')->nullable();
            $table->integer('target_duration_milliseconds')->nullable();
            $table->string('decision', 16)->nullable();

            $table->unique(['export_review_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_review_items');
    }
};
