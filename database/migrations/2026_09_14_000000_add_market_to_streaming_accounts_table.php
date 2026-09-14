<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streaming_accounts', function (Blueprint $table): void {
            $table->string('market', 2)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('streaming_accounts', function (Blueprint $table): void {
            $table->dropColumn('market');
        });
    }
};
