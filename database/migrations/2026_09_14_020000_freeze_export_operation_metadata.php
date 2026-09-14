<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_operations', function (Blueprint $table): void {
            $table->text('playlist_name')->nullable();
            $table->text('playlist_description')->nullable();
        });

        // Existing operations get one migration-time snapshot; retries never reread source metadata.
        DB::table('export_operations')->orderBy('id')->chunkById(100, function ($operations): void {
            foreach ($operations as $operation) {
                $source = DB::table('playlist_exports')
                    ->join('playlists', 'playlists.id', '=', 'playlist_exports.source_playlist_id')
                    ->where('playlist_exports.id', $operation->playlist_export_id)
                    ->select('playlists.name', 'playlists.description')->first();
                DB::table('export_operations')->where('id', $operation->id)->update([
                    'playlist_name' => $source?->name,
                    'playlist_description' => $source?->description,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('export_operations', function (Blueprint $table): void {
            $table->dropColumn(['playlist_name', 'playlist_description']);
        });
    }
};
