<?php

namespace Tests\Feature\StreamingAccounts;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Actions\LinkStreamingAccount;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class StreamingAccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_creates_one_account_with_an_encrypted_refresh_token(): void
    {
        $user = User::factory()->create();

        $account = (new LinkStreamingAccount)->handle(
            $user,
            StreamingProvider::Spotify,
            $this->grant('refresh-link-canary'),
            $this->identity('spotify-account'),
        );

        $this->assertInstanceOf(StreamingAccount::class, $account);
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('refresh-link-canary', $account->refresh_token);
        $this->assertStringNotContainsString(
            'refresh-link-canary',
            DB::table('streaming_accounts')->where('id', $account->id)->value('refresh_token'),
        );
    }

    public function test_parallel_first_insert_collision_is_re_read_as_idempotent_success(): void
    {
        $user = User::factory()->create();
        $linker = new SimulatedStreamingAccountCollision(function () use ($user): void {
            StreamingAccount::factory()->for($user)->spotify()->create([
                'provider_account_id' => 'spotify-account',
                'refresh_token' => 'winning-refresh-canary',
            ]);
        });

        $result = $linker->handle(
            $user,
            StreamingProvider::Spotify,
            $this->grant('replacement-refresh-canary'),
            $this->identity('spotify-account'),
        );

        $this->assertInstanceOf(StreamingAccount::class, $result);
        $this->assertSame(2, $linker->attempts);
        $this->assertDatabaseCount('streaming_accounts', 1);
        $this->assertSame('replacement-refresh-canary', $result->fresh()->refresh_token);
        $this->assertSame(2, $result->fresh()->credential_version);
    }

    public function test_linking_a_different_account_for_the_same_provider_requires_unlink(): void
    {
        $user = User::factory()->create();
        StreamingAccount::factory()->for($user)->spotify()->create([
            'provider_account_id' => 'existing-account',
        ]);

        $result = (new LinkStreamingAccount)->handle(
            $user,
            StreamingProvider::Spotify,
            $this->grant('replacement-refresh-canary'),
            $this->identity('different-account'),
        );

        $this->assertSame(StreamingOAuthFailure::AccountConflict, $result);
        $this->assertDatabaseHas('streaming_accounts', [
            'user_id' => $user->id,
            'provider_account_id' => 'existing-account',
        ]);
    }

    public function test_provider_account_owned_by_another_user_is_refused_neutrally(): void
    {
        StreamingAccount::factory()->spotify()->create([
            'provider_account_id' => 'owned-account',
        ]);

        $result = (new LinkStreamingAccount)->handle(
            User::factory()->create(),
            StreamingProvider::Spotify,
            $this->grant('losing-refresh-canary'),
            $this->identity('owned-account'),
        );

        $this->assertSame(StreamingOAuthFailure::AccessUnavailable, $result);
        $this->assertDatabaseCount('streaming_accounts', 1);
    }

    private function grant(string $refreshToken): StreamingGrant
    {
        return new StreamingGrant(
            'access-canary',
            $refreshToken,
            StreamingProvider::Spotify->requiredScopes(),
        );
    }

    private function identity(string $accountId): StreamingIdentity
    {
        return new StreamingIdentity($accountId, 'Canary account');
    }
}

class SimulatedStreamingAccountCollision extends LinkStreamingAccount
{
    public int $attempts = 0;

    public function __construct(private readonly Closure $beforeCollision) {}

    protected function linkAttempt(
        User $user,
        StreamingProvider $provider,
        StreamingGrant $grant,
        StreamingIdentity $identity,
    ): StreamingAccount|StreamingOAuthFailure {
        $this->attempts++;

        if ($this->attempts === 1) {
            ($this->beforeCollision)();

            throw new UniqueConstraintViolationException(
                'test',
                'insert into streaming_accounts',
                [],
                new PDOException('simulated unique collision'),
            );
        }

        return parent::linkAttempt($user, $provider, $grant, $identity);
    }
}
