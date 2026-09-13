<?php

namespace Tests\Feature\StreamingAccounts;

use App\Enums\StreamingProvider;
use App\Models\StreamingAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StreamingAccountModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_streaming_provider_is_closed_to_supported_services(): void
    {
        $this->assertSame(
            ['spotify', 'youtube'],
            array_column(StreamingProvider::cases(), 'value'),
        );
    }

    public function test_account_belongs_to_its_user_and_is_deleted_with_that_user(): void
    {
        $account = StreamingAccount::factory()->spotify()->create();
        $user = $account->user;

        $this->assertTrue($user->streamingAccounts->contains($account));

        $user->delete();

        $this->assertDatabaseMissing('streaming_accounts', ['id' => $account->id]);
    }

    public function test_scopes_are_normalized_and_dates_are_immutable(): void
    {
        $account = StreamingAccount::factory()->youtube()->create([
            'scopes' => ['scope-z', 'scope-a', 'scope-z'],
            'reauthorization_due_at' => now()->addDay(),
        ]);

        $this->assertSame(['scope-a', 'scope-z'], $account->scopes);
        $this->assertInstanceOf(CarbonImmutable::class, $account->reauthorization_due_at);
        $this->assertInstanceOf(CarbonImmutable::class, $account->created_at);
        $this->assertSame(StreamingProvider::YouTube, $account->provider);
    }

    public function test_refresh_token_is_encrypted_readable_and_never_serialized_or_fillable(): void
    {
        $plaintext = 'canary-model-refresh-token';
        $account = StreamingAccount::factory()->spotify()->create([
            'refresh_token' => $plaintext,
        ]);

        $storedToken = DB::table('streaming_accounts')
            ->where('id', $account->id)
            ->value('refresh_token');

        $this->assertIsString($storedToken);
        $this->assertNotSame($plaintext, $storedToken);
        $this->assertStringNotContainsString($plaintext, $storedToken);
        $this->assertSame($plaintext, $account->fresh()->refresh_token);
        $this->assertNotContains('refresh_token', $account->getFillable());
        $this->assertArrayNotHasKey('refresh_token', $account->toArray());
        $this->assertStringNotContainsString('refresh_token', $account->toJson());
        $this->assertStringNotContainsString($plaintext, $account->toJson());
    }

    public function test_connection_state_tracks_refresh_token_presence(): void
    {
        $connected = StreamingAccount::factory()->spotify()->create();
        $reconnectRequired = StreamingAccount::factory()->youtube()->reconnectRequired()->create();

        $this->assertSame(StreamingAccount::STATE_CONNECTED, $connected->connectionState());
        $this->assertSame(
            StreamingAccount::STATE_RECONNECT_REQUIRED,
            $reconnectRequired->connectionState(),
        );
    }

    public function test_user_can_only_have_one_account_for_each_provider(): void
    {
        $user = User::factory()->create();

        StreamingAccount::factory()->for($user)->spotify()->create();

        $this->expectException(QueryException::class);

        StreamingAccount::factory()->for($user)->spotify()->create();
    }

    public function test_provider_account_can_only_belong_to_one_user(): void
    {
        StreamingAccount::factory()->spotify()->create([
            'provider_account_id' => 'shared-provider-account',
        ]);

        $this->expectException(QueryException::class);

        StreamingAccount::factory()->spotify()->create([
            'provider_account_id' => 'shared-provider-account',
        ]);
    }
}
