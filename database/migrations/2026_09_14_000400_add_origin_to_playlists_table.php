<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->enum('origin', ['imported', 'managed_target'])
                ->default('imported')
                ->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }
};
