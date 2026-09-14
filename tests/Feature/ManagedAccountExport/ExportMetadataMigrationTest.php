<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Models\ExportOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExportMetadataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_migration_backfills_existing_operations_without_changing_their_identity(): void
    {
        $operation = ExportOperation::factory()->create();
        $operation->playlistExport->sourcePlaylist->forceFill([
            'name' => 'Existing export title',
            'description' => 'Existing export description',
        ])->save();
        $migration = require database_path('migrations/2026_09_14_020000_freeze_export_operation_metadata.php');

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('export_operations', 'playlist_name'));
        } finally {
            $migration->up();
        }

        $operation->refresh();
        $this->assertSame('Existing export title', $operation->playlist_name);
        $this->assertSame('Existing export description', $operation->playlist_description);
        $this->assertDatabaseCount('export_operations', 1);
        $operation->playlistExport->sourcePlaylist->forceFill(['name' => 'Later source title'])->save();
        $this->assertSame('Existing export title', $operation->fresh()->playlist_name);
    }
}
