<?php

namespace Tests\Feature\StreamingAccounts;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StreamingAccountMigrationTest extends TestCase
{
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = DB::getDefaultConnection();

        config()->set('database.connections.streaming_accounts_migration_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('streaming_accounts_migration_test');
        DB::setDefaultConnection('streaming_accounts_migration_test');
        DB::statement('PRAGMA foreign_keys = ON');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefaultConnection);
        DB::purge('streaming_accounts_migration_test');

        parent::tearDown();
    }

    public function test_migration_applies_enforces_uniqueness_and_rolls_back_on_isolated_sqlite(): void
    {
        $migration = require database_path('migrations/2026_09_13_000000_create_streaming_accounts_table.php');

        try {
            $migration->up();

            $this->assertTrue(Schema::hasTable('streaming_accounts'));
            $this->assertTrue(Schema::hasColumns('streaming_accounts', [
                'id',
                'user_id',
                'provider',
                'provider_account_id',
                'label',
                'scopes',
                'refresh_token',
                'reauthorization_due_at',
                'credential_version',
                'created_at',
                'updated_at',
            ]));

            DB::table('users')->insert([['id' => 1], ['id' => 2]]);
            $this->insertAccount(1, 'spotify', 'spotify-account');

            $this->assertSame(
                1,
                DB::table('streaming_accounts')->where('user_id', 1)->value('credential_version'),
            );

            $this->assertUniqueConstraint(fn () => $this->insertAccount(
                1,
                'spotify',
                'different-spotify-account',
            ));
            $this->assertUniqueConstraint(fn () => $this->insertAccount(
                2,
                'spotify',
                'spotify-account',
            ));

            $migration->down();

            $this->assertFalse(Schema::hasTable('streaming_accounts'));
            $this->assertTrue(Schema::hasTable('users'));
        } finally {
            if (Schema::hasTable('streaming_accounts')) {
                $migration->down();
            }
        }
    }

    private function insertAccount(int $userId, string $provider, string $providerAccountId): void
    {
        DB::table('streaming_accounts')->insert([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_account_id' => $providerAccountId,
            'scopes' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertUniqueConstraint(Closure $insert): void
    {
        try {
            $insert();
            $this->fail('Expected the database to reject a duplicate streaming account.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
