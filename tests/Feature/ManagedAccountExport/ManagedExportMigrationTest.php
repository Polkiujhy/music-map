<?php

namespace Tests\Feature\ManagedAccountExport;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagedExportMigrationTest extends TestCase
{
    public function test_schema_preserves_managed_export_identity_history_and_restrictive_ownership_on_sqlite(): void
    {
        $original = app(ConnectionResolverInterface::class)->getDefaultConnection();
        config()->set('database.connections.managed_export_migration_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('managed_export_migration_test');
        DB::setDefaultConnection('managed_export_migration_test');
        DB::statement('PRAGMA foreign_keys = ON');

        $paths = [
            '0001_01_01_000000_create_users_table.php',
            '2026_09_13_000000_create_streaming_accounts_table.php',
            '2026_09_13_010000_create_playlists_table.php',
            '2026_09_13_010200_link_playlists_to_streaming_accounts.php',
            '2026_09_14_000000_add_market_to_streaming_accounts_table.php',
            '2026_09_14_000100_create_export_reviews_table.php',
            '2026_09_14_000400_add_origin_to_playlists_table.php',
            '2026_09_14_000500_create_playlist_exports_table.php',
            '2026_09_14_000600_create_playlist_export_target_attempts_table.php',
            '2026_09_14_000700_create_export_operations_table.php',
        ];
        $migrations = array_map(static fn (string $path) => require database_path("migrations/{$path}"), $paths);

        try {
            foreach ($migrations as $migration) {
                $migration->up();
            }

            $this->assertTrue(Schema::hasColumns('playlist_exports', [
                'source_playlist_id', 'target_provider', 'destination_type', 'streaming_account_id',
                'target_account_id', 'target_market', 'target_generation', 'target_playlist_id',
            ]));
            $this->assertTrue(Schema::hasColumns('playlist_export_target_attempts', [
                'playlist_export_id', 'target_provider', 'target_account_id', 'generation', 'marker',
                'status', 'create_started_at', 'create_completed_at', 'provider_playlist_id', 'canonical_url',
            ]));
            $this->assertTrue(Schema::hasColumns('export_operations', [
                'id', 'user_id', 'export_review_id', 'playlist_export_id', 'status', 'failure_code',
                'attempt_generation', 'retry_generation', 'automatic_claim_count', 'heartbeat_at',
                'job_publication_lease_until', 'job_published_at', 'possible_mutation_at',
                'retry_available_at', 'manual_recovery_requested_at', 'notification_generation',
                'notification_sent_at',
            ]));
            $this->assertFalse(Schema::hasColumn('export_operations', 'access_token'));
            $this->assertFalse(Schema::hasColumn('export_operations', 'manifest'));
            $this->assertFalse(Schema::hasColumn('export_operations', 'payload'));

            $now = now();
            DB::table('users')->insert(['id' => 1, 'name' => 'Canary', 'email' => 'canary@example.test', 'password' => 'hash', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('streaming_accounts')->insert(['id' => 1, 'user_id' => 1, 'provider' => 'spotify', 'provider_account_id' => 'owner', 'scopes' => '[]', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('playlists')->insert([
                ['id' => 1, 'user_id' => 1, 'streaming_account_id' => 1, 'source_provider' => 'youtube', 'source_playlist_id' => 'source', 'canonical_source_url' => 'https://example.test/source', 'provider_metadata_refreshed_at' => $now, 'imported_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'user_id' => 1, 'streaming_account_id' => null, 'source_provider' => 'spotify', 'source_playlist_id' => 'target', 'canonical_source_url' => 'https://example.test/target', 'provider_metadata_refreshed_at' => $now, 'imported_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ]);
            $this->assertSame(['imported', 'imported'], DB::table('playlists')->orderBy('id')->pluck('origin')->all());
            DB::table('export_reviews')->insert(['id' => 1, 'user_id' => 1, 'playlist_id' => 1, 'target_provider' => 'spotify', 'destination_type' => 'managed', 'target_account_id' => 'owner', 'source_fingerprint' => str_repeat('a', 64), 'status' => 'confirmed', 'correlation_id' => '00000000-0000-4000-8000-000000000001', 'expires_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('playlist_exports')->insert(['id' => 1, 'source_playlist_id' => 1, 'target_provider' => 'spotify', 'destination_type' => 'managed', 'streaming_account_id' => 1, 'target_account_id' => 'owner', 'target_playlist_id' => 2, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('playlist_export_target_attempts')->insert(['playlist_export_id' => 1, 'target_provider' => 'spotify', 'target_account_id' => 'owner', 'generation' => 1, 'marker' => '00000000-0000-4000-8000-000000000002', 'status' => 'resolved', 'provider_playlist_id' => 'target', 'canonical_url' => 'https://example.test/target', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('export_operations')->insert(['id' => '00000000-0000-4000-8000-000000000003', 'user_id' => 1, 'export_review_id' => 1, 'playlist_export_id' => 1, 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now]);

            DB::table('streaming_accounts')->delete(1);
            $this->assertNull(DB::table('playlist_exports')->value('streaming_account_id'));
            $this->assertSame('owner', DB::table('playlist_exports')->value('target_account_id'));

            foreach ([
                fn () => DB::table('playlists')->delete(1),
                fn () => DB::table('playlists')->delete(2),
                fn () => DB::table('export_reviews')->delete(1),
                fn () => DB::table('playlist_exports')->delete(1),
                fn () => DB::table('users')->delete(1),
            ] as $delete) {
                try {
                    $delete();
                    $this->fail('Durable managed-export ownership must restrict deletion.');
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }

            DB::table('export_operations')->delete();
            DB::table('playlist_export_target_attempts')->delete();
            DB::table('playlist_exports')->delete();
            DB::table('export_reviews')->delete();
            DB::table('playlists')->delete();
            DB::table('users')->delete();

            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            $this->assertFalse(Schema::hasTable('export_operations'));
            $this->assertFalse(Schema::hasTable('playlist_export_target_attempts'));
            $this->assertFalse(Schema::hasTable('playlist_exports'));
            $this->assertFalse(Schema::hasColumn('playlists', 'origin'));
        } finally {
            DB::disconnect('managed_export_migration_test');
            DB::setDefaultConnection($original);
            DB::purge('managed_export_migration_test');
        }
    }
}
