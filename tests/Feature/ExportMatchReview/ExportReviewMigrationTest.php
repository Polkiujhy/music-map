<?php

namespace Tests\Feature\ExportMatchReview;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExportReviewMigrationTest extends TestCase
{
    public function test_review_schema_applies_constraints_deletion_semantics_and_rolls_back_on_sqlite(): void
    {
        $original = app(ConnectionResolverInterface::class)->getDefaultConnection();
        config()->set('database.connections.export_review_migration_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('export_review_migration_test');
        DB::setDefaultConnection('export_review_migration_test');
        DB::statement('PRAGMA foreign_keys = ON');

        $migrations = array_map(static fn (string $path) => require database_path("migrations/{$path}"), [
            '0001_01_01_000000_create_users_table.php',
            '2026_09_13_000000_create_streaming_accounts_table.php',
            '2026_09_13_010000_create_playlists_table.php',
            '2026_09_13_010200_link_playlists_to_streaming_accounts.php',
            '2026_09_14_000000_add_market_to_streaming_accounts_table.php',
            '2026_09_14_000100_create_export_reviews_table.php',
            '2026_09_14_000200_create_export_review_items_table.php',
        ]);

        try {
            foreach ($migrations as $migration) {
                $migration->up();
            }

            $this->assertTrue(Schema::hasColumns('export_reviews', [
                'user_id', 'playlist_id', 'streaming_account_id', 'target_provider', 'destination_type',
                'target_account_id', 'target_market', 'source_fingerprint', 'status', 'failure_code',
                'correlation_id', 'started_at', 'completed_at', 'expires_at', 'confirmed_at', 'notification_sent_at',
            ]));
            $this->assertTrue(Schema::hasColumns('export_review_items', [
                'export_review_id', 'position', 'source_title', 'source_creators', 'match_status',
                'target_catalog_id', 'target_catalog_uri', 'decision',
            ]));
            $this->assertFalse(Schema::hasColumn('export_reviews', 'access_token'));
            $this->assertFalse(Schema::hasColumn('export_reviews', 'payload'));

            DB::table('users')->insert(['id' => 1, 'name' => 'Canary', 'email' => 'canary@example.test', 'password' => 'canary-password-hash', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('streaming_accounts')->insert(['id' => 1, 'user_id' => 1, 'provider' => 'spotify', 'provider_account_id' => 'account', 'scopes' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('playlists')->insert(['id' => 1, 'user_id' => 1, 'streaming_account_id' => 1, 'source_provider' => 'youtube', 'source_playlist_id' => 'playlist', 'canonical_source_url' => 'https://www.youtube.com/playlist?list=playlist', 'provider_metadata_refreshed_at' => now(), 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('export_reviews')->insert(['id' => 1, 'user_id' => 1, 'playlist_id' => 1, 'streaming_account_id' => 1, 'target_provider' => 'spotify', 'destination_type' => 'linked', 'target_account_id' => 'account', 'source_fingerprint' => str_repeat('a', 64), 'status' => 'queued', 'correlation_id' => '00000000-0000-4000-8000-000000000001', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('export_review_items')->insert(['export_review_id' => 1, 'position' => 0, 'source_creators' => '[]', 'source_is_available' => true, 'match_status' => 'unavailable']);

            DB::table('streaming_accounts')->delete(1);
            $this->assertNull(DB::table('export_reviews')->value('streaming_account_id'));
            $this->assertSame('account', DB::table('export_reviews')->value('target_account_id'));
            DB::table('playlists')->delete(1);
            $this->assertSame(0, DB::table('export_reviews')->count());
            $this->assertSame(0, DB::table('export_review_items')->count());

            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            $this->assertFalse(Schema::hasTable('export_reviews'));
            $this->assertFalse(Schema::hasTable('export_review_items'));
        } finally {
            DB::disconnect('export_review_migration_test');
            DB::setDefaultConnection($original);
            DB::purge('export_review_migration_test');
        }
    }
}
