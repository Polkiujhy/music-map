<?php

namespace Tests\Feature\Playlists;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlaylistMigrationTest extends TestCase
{
    public function test_playlist_migrations_apply_constraints_and_roll_back_on_explicit_memory_database(): void
    {
        $originalDefault = app(ConnectionResolverInterface::class)->getDefaultConnection();
        config()->set('database.connections.playlist_migration_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('playlist_migration_test');
        DB::setDefaultConnection('playlist_migration_test');

        $users = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $playlists = require database_path('migrations/2026_09_13_010000_create_playlists_table.php');
        $items = require database_path('migrations/2026_09_13_010100_create_playlist_items_table.php');

        try {
            $users->up();
            $playlists->up();
            $items->up();

            $this->assertTrue(Schema::hasColumns('playlists', [
                'user_id',
                'source_provider',
                'source_playlist_id',
                'source_account_id',
                'canonical_source_url',
                'provider_revision',
                'name',
                'description',
                'provider_metadata_refreshed_at',
                'imported_at',
            ]));
            $this->assertTrue(Schema::hasColumns('playlist_items', [
                'playlist_id',
                'position',
                'occurrence_id',
                'catalog_id',
                'catalog_uri',
                'creators',
                'is_available',
            ]));

            $items->down();
            $playlists->down();

            $this->assertFalse(Schema::hasTable('playlist_items'));
            $this->assertFalse(Schema::hasTable('playlists'));
            $this->assertTrue(Schema::hasTable('users'));
        } finally {
            Schema::dropIfExists('playlist_items');
            Schema::dropIfExists('playlists');
            Schema::dropIfExists('users');
            DB::disconnect('playlist_migration_test');
            DB::setDefaultConnection($originalDefault);
            DB::purge('playlist_migration_test');
        }
    }
}
