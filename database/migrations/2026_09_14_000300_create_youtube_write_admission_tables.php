<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_write_quota_states', function (Blueprint $table): void {
            $table->enum('singleton_key', ['global'])->primary();
            $table->date('quota_day')->nullable();
            $table->unsignedInteger('admitted_count')->default(0);
            $table->unsignedInteger('daily_limit')->nullable();
        });

        DB::table('youtube_write_quota_states')->insert([
            'singleton_key' => 'global',
            'quota_day' => null,
            'admitted_count' => 0,
            'daily_limit' => null,
        ]);

        Schema::create('youtube_write_admissions', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_type', 32);
            $table->string('operation_id', 255);
            $table->date('quota_day');
            $table->timestamp('admitted_at');

            $table->unique(['operation_type', 'operation_id']);
            $table->index('quota_day');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'YouTube write admission history is immutable; use a separate explicitly destructive migration.',
        );
    }
};
