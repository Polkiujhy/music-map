<?php

namespace Tests\Feature\Integrations\YouTubeWriteAdmission;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\TestCase;

class YouTubeWriteAdmissionMigrationTest extends TestCase
{
    public function test_schema_enforces_singleton_and_operation_identity_and_refuses_rollback(): void
    {
        $original = app(ConnectionResolverInterface::class)->getDefaultConnection();
        config()->set('database.connections.youtube_write_admission_migration_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('youtube_write_admission_migration_test');
        DB::setDefaultConnection('youtube_write_admission_migration_test');

        $migration = require database_path('migrations/2026_09_14_000300_create_youtube_write_admission_tables.php');

        try {
            $migration->up();

            $this->assertTrue(Schema::hasColumns('youtube_write_quota_states', [
                'singleton_key', 'quota_day', 'admitted_count', 'daily_limit',
            ]));
            $this->assertTrue(Schema::hasColumns('youtube_write_admissions', [
                'id', 'operation_type', 'operation_id', 'quota_day', 'admitted_at',
            ]));
            $this->assertDatabaseHas('youtube_write_quota_states', [
                'singleton_key' => 'global', 'quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null,
            ]);

            $this->expectDatabaseException(static function (): void {
                DB::table('youtube_write_quota_states')->insert([
                    'singleton_key' => 'other', 'admitted_count' => 0,
                ]);
            });

            DB::table('youtube_write_admissions')->insert([
                'operation_type' => 'managed-export',
                'operation_id' => 'operation-1',
                'quota_day' => '2026-09-14',
                'admitted_at' => '2026-09-14 12:00:00',
            ]);

            $this->expectDatabaseException(static function (): void {
                DB::table('youtube_write_admissions')->insert([
                    'operation_type' => 'managed-export',
                    'operation_id' => 'operation-1',
                    'quota_day' => '2026-09-15',
                    'admitted_at' => '2026-09-15 12:00:00',
                ]);
            });

            try {
                $migration->down();
                $this->fail('The immutable admission ledger must refuse rollback.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }

            $this->assertTrue(Schema::hasTable('youtube_write_quota_states'));
            $this->assertTrue(Schema::hasTable('youtube_write_admissions'));
            $this->assertSame(1, DB::table('youtube_write_quota_states')->count());
            $this->assertSame(1, DB::table('youtube_write_admissions')->count());
        } finally {
            DB::disconnect('youtube_write_admission_migration_test');
            DB::setDefaultConnection($original);
            DB::purge('youtube_write_admission_migration_test');
        }
    }

    private function expectDatabaseException(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the database constraint to reject the write.');
        } catch (\Throwable $exception) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $exception);
        }
    }
}
