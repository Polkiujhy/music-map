<?php

namespace Tests\Feature\PlaylistSync;

use App\Enums\PlaylistSyncOutcome;
use App\Enums\PlaylistSyncStatus;
use App\Enums\StreamingProvider;
use App\Jobs\RunPlaylistSynchronization;
use App\Models\Playlist;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlaylistSynchronizationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ineligible_import_explains_recovery_without_contacting_the_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $playlist = Playlist::factory()->create(['source_provider' => StreamingProvider::Spotify]);

        $this->actingAs($playlist->user)
            ->get(route('bank.playlists.edit', $playlist))
            ->assertOk()
            ->assertSee('Synchronizacja źródła')
            ->assertSee('Przejdź do połączeń kont')
            ->assertSee('Nieaktywna');

        $this->actingAs($playlist->user)
            ->post(route('playlist-synchronizations.prepare', $playlist))
            ->assertRedirect(route('bank.playlists.edit', $playlist))
            ->assertSessionHasErrors('account');

        $this->assertDatabaseCount('playlist_synchronizations', 0);
        Http::assertNothingSent();
    }

    public function test_preview_is_explicit_about_direction_item_counts_and_confirmation(): void
    {
        $playlist = Playlist::factory()->create(['source_provider' => StreamingProvider::YouTube]);
        StreamingAccount::factory()->for($playlist->user)->youtube()->create([
            'provider_account_id' => 'account-not-for-display',
            'scopes' => StreamingProvider::YouTube->requiredScopes(),
        ]);
        $preview = [
            'preview_token' => Str::random(64),
            'direction' => 'pull',
            'bank_item_count' => 2,
            'source_item_count' => 3,
            'change_count' => 3,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ];

        $this->actingAs($playlist->user)
            ->withSession(['playlist_sync_preview' => $preview])
            ->get(route('bank.playlists.edit', $playlist))
            ->assertOk()
            ->assertSee('Podgląd pierwszego uzgodnienia')
            ->assertSee('Bank zostanie zastąpiony aktualną zawartością źródła.')
            ->assertSee('Zmiana obejmie 3 pozycji')
            ->assertSee('Potwierdź pierwszą synchronizację')
            ->assertSee('name="_token"', false)
            ->assertDontSee('account-not-for-display');
    }

    public function test_stale_confirmation_returns_to_the_panel_without_dispatching(): void
    {
        Queue::fake();
        $playlist = Playlist::factory()->create();

        $this->actingAs($playlist->user)
            ->post(route('playlist-synchronizations.confirm', $playlist), [
                'preview_token' => Str::random(64),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('preview_token');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('playlist_sync_runs', 0);
    }

    public function test_manual_start_only_dispatches_a_queue_job_and_returns_immediately(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        [$playlist, $synchronization] = $this->enabledSynchronization();

        $this->actingAs($playlist->user)
            ->post(route('playlist-synchronizations.run', $playlist))
            ->assertRedirect(route('bank.playlists.edit', $playlist))
            ->assertSessionHas('status', 'Ręczna synchronizacja oczekuje w kolejce.');

        Queue::assertPushed(RunPlaylistSynchronization::class, fn ($job): bool => $job->runId > 0);
        $this->assertDatabaseHas('playlist_sync_runs', [
            'playlist_synchronization_id' => $synchronization->id,
            'trigger' => 'manual',
            'state' => 'pending',
        ]);
        Http::assertNothingSent();
    }

    public function test_automatic_mode_is_an_independent_reversible_choice(): void
    {
        Queue::fake();
        [$playlist, $synchronization] = $this->enabledSynchronization();

        $this->actingAs($playlist->user)
            ->patch(route('playlist-synchronizations.update', $playlist), ['automatic_enabled' => true])
            ->assertRedirect(route('bank.playlists.edit', $playlist));

        $this->assertTrue($synchronization->refresh()->automatic_enabled);
        $this->assertNotNull($synchronization->next_check_at);
        $this->assertDatabaseCount('playlist_sync_runs', 0);

        $this->actingAs($playlist->user)
            ->patch(route('playlist-synchronizations.update', $playlist), ['automatic_enabled' => false])
            ->assertRedirect(route('bank.playlists.edit', $playlist));

        $this->assertFalse($synchronization->refresh()->automatic_enabled);
        $this->assertNull($synchronization->next_check_at);
        Queue::assertNothingPushed();
    }

    public function test_attention_codes_have_stable_safe_messages_and_keep_the_bank_visible(): void
    {
        $playlist = Playlist::factory()->create([
            'name' => 'Bank pozostaje bez zmian',
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => 'playlist-technical-canary',
        ]);
        $account = StreamingAccount::factory()->for($playlist->user)->spotify()->create([
            'provider_account_id' => 'account-technical-canary',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
        $synchronization = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Attention,
            'automatic_enabled' => false,
            'last_outcome' => PlaylistSyncOutcome::Failed,
            'last_failure_code' => 'over-limit',
        ]);

        $messages = [
            'over-limit' => 'Playlista źródłowa przekracza limit 20 pozycji.',
            'not-found' => 'Nie można odnaleźć playlisty źródłowej.',
            'unauthorized' => 'Połącz konto ponownie',
            'forbidden' => 'Połącz konto ponownie',
            'owner-mismatch' => 'Połącz konto ponownie',
            'missing-access' => 'Połącz konto ponownie',
            'reconnect-required' => 'Połącz konto ponownie',
            'rate-limited' => 'Platforma jest chwilowo niedostępna.',
            'provider-unavailable' => 'Platforma jest chwilowo niedostępna.',
            'invalid-response' => 'Synchronizacja wymaga ponownego przygotowania',
            'job-failed' => 'Synchronizacja wymaga ponownego przygotowania',
        ];

        foreach ($messages as $code => $message) {
            $synchronization->update(['last_failure_code' => $code]);

            $this->actingAs($playlist->user)
                ->get(route('bank.playlists.edit', $playlist))
                ->assertOk()
                ->assertSee('Bank pozostaje bez zmian')
                ->assertSee($message)
                ->assertDontSee('playlist-technical-canary')
                ->assertDontSee('account-technical-canary');
        }
    }

    public function test_pending_state_is_announced_as_pending_not_success(): void
    {
        [$playlist, $synchronization] = $this->enabledSynchronization();
        PlaylistSyncRun::factory()->for($synchronization, 'synchronization')->create(['state' => 'pending']);

        $this->actingAs($playlist->user)
            ->get(route('bank.playlists.edit', $playlist))
            ->assertOk()
            ->assertSee('Oczekuje')
            ->assertSee('Synchronizacja oczekuje w kolejce.')
            ->assertDontSee('Ostatnia synchronizacja zakończyła się powodzeniem.')
            ->assertSee('aria-labelledby="playlist-synchronization-heading"', false)
            ->assertSee('aria-pressed="false"', false)
            ->assertDontSee('data-testid');
    }

    public function test_a_foreign_user_cannot_read_start_confirm_or_change_synchronization(): void
    {
        Queue::fake();
        [$playlist] = $this->enabledSynchronization();
        $foreigner = User::factory()->create();

        $this->actingAs($foreigner)->getJson(route('playlist-synchronizations.show', $playlist))->assertNotFound();
        $this->actingAs($foreigner)->postJson(route('playlist-synchronizations.prepare', $playlist))->assertNotFound();
        $this->actingAs($foreigner)->postJson(route('playlist-synchronizations.run', $playlist))->assertNotFound();
        $this->actingAs($foreigner)->postJson(route('playlist-synchronizations.confirm', $playlist), [
            'preview_token' => Str::random(64),
        ])->assertNotFound();
        $this->actingAs($foreigner)->patchJson(route('playlist-synchronizations.update', $playlist), [
            'automatic_enabled' => true,
        ])->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_status_response_exposes_only_the_public_ui_contract(): void
    {
        [$playlist, $synchronization] = $this->enabledSynchronization();
        $synchronization->update([
            'last_checked_at' => now()->startOfSecond(),
            'last_outcome' => PlaylistSyncOutcome::Pulled,
        ]);

        $this->actingAs($playlist->user)
            ->getJson(route('playlist-synchronizations.show', $playlist))
            ->assertOk()
            ->assertJsonPath('provider', $playlist->source_provider->value)
            ->assertJsonPath('state', 'success')
            ->assertJsonPath('last_result', 'pulled')
            ->assertJsonMissingPath('synchronization_id')
            ->assertJsonMissingPath('playlist_id')
            ->assertJsonMissingPath('failure_code');
    }

    /** @return array{Playlist, PlaylistSynchronization} */
    private function enabledSynchronization(): array
    {
        $playlist = Playlist::factory()->create(['source_provider' => StreamingProvider::Spotify]);
        $account = StreamingAccount::factory()->for($playlist->user)->spotify()->create([
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
        $synchronization = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => hash('sha256', 'bank'),
            'baseline_source_fingerprint' => hash('sha256', 'source'),
        ]);

        return [$playlist, $synchronization];
    }
}
