<?php

namespace Tests\Feature\PlaylistSync;

use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlaylistSynchronizationMigrationTest extends TestCase
{
    public function test_schema_applies_enforces_relations_and_rolls_back_on_sqlite(): void
    {
        $original = app(ConnectionResolverInterface::class)->getDefaultConnection();
        config()->set('database.connections.playlist_sync_migration_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('playlist_sync_migration_test');
        DB::setDefaultConnection('playlist_sync_migration_test');
        DB::statement('PRAGMA foreign_keys = ON');

        Schema::create('playlists', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('streaming_accounts', function (Blueprint $table): void {
            $table->id();
        });

        $synchronizations = require database_path('migrations/2026_09_14_000400_create_playlist_synchronizations_table.php');
        $runs = require database_path('migrations/2026_09_14_000500_create_playlist_sync_runs_table.php');

        try {
            $synchronizations->up();
            $runs->up();

            $this->assertTrue(Schema::hasColumns('playlist_synchronizations', [
                'playlist_id', 'streaming_account_id', 'status', 'automatic_enabled',
                'baseline_bank_fingerprint', 'baseline_source_fingerprint',
                'baseline_provider_revision', 'last_checked_at', 'last_succeeded_at',
                'next_check_at', 'last_outcome', 'last_failure_code', 'created_at', 'updated_at',
            ]));
            $this->assertTrue(Schema::hasColumns('playlist_sync_runs', [
                'playlist_synchronization_id', 'operation_id', 'trigger', 'direction',
                'state', 'input_bank_fingerprint', 'input_source_fingerprint',
                'input_provider_revision', 'bank_snapshot', 'source_snapshot',
                'desired_fingerprint', 'checkpoint',
            ]));

            $syncIndexes = collect(Schema::getIndexes('playlist_synchronizations'))
                ->pluck('name');
            $runIndexes = collect(Schema::getIndexes('playlist_sync_runs'))
                ->pluck('name');
            $this->assertContains('playlist_sync_due_index', $syncIndexes);
            $this->assertContains('playlist_sync_active_run_index', $runIndexes);

            DB::table('playlists')->insert([['id' => 1], ['id' => 2]]);
            DB::table('streaming_accounts')->insert(['id' => 1]);
            $syncId = DB::table('playlist_synchronizations')->insertGetId([
                'playlist_id' => 1,
                'streaming_account_id' => 1,
                'status' => 'enabled',
                'automatic_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->expectConstraintViolation(fn () => DB::table('playlist_synchronizations')->insert([
                'playlist_id' => 1,
                'status' => 'disabled',
                'automatic_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            DB::table('playlist_sync_runs')->insert([
                'playlist_synchronization_id' => $syncId,
                'operation_id' => 'canary-operation',
                'trigger' => 'manual',
                'direction' => 'no-op',
                'state' => 'pending',
                'input_bank_fingerprint' => str_repeat('a', 64),
                'input_source_fingerprint' => str_repeat('b', 64),
                'bank_snapshot' => '[]',
                'source_snapshot' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('streaming_accounts')->where('id', 1)->delete();
            $this->assertNull(DB::table('playlist_synchronizations')->value('streaming_account_id'));

            DB::table('playlists')->where('id', 1)->delete();
            $this->assertSame(0, DB::table('playlist_synchronizations')->count());
            $this->assertSame(0, DB::table('playlist_sync_runs')->count());

            $runs->down();
            $synchronizations->down();
            $this->assertFalse(Schema::hasTable('playlist_sync_runs'));
            $this->assertFalse(Schema::hasTable('playlist_synchronizations'));
        } finally {
            Schema::dropIfExists('playlist_sync_runs');
            Schema::dropIfExists('playlist_synchronizations');
            Schema::dropIfExists('streaming_accounts');
            Schema::dropIfExists('playlists');
            DB::disconnect('playlist_sync_migration_test');
            DB::setDefaultConnection($original);
            DB::purge('playlist_sync_migration_test');
        }
    }

    private function expectConstraintViolation(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the database constraint to reject the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
