<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use App\Livewire\ExportOperationPanel;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExportLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ExportOperationPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_polls_only_queued_and_processing_operations(): void
    {
        $operation = $this->operation();
        $this->actingAs($operation->user);

        $component = Livewire::test(ExportOperationPanel::class, ['exportOperation' => $operation])
            ->assertSet('isPolling', true)
            ->assertSee('W trakcie przenoszenia')
            ->assertSee('wire:poll.3s="poll"', false);

        $operation->update([
            'status' => ExportOperationStatus::Failed,
            'failure_code' => ExportOperationFailure::TemporaryFailure,
            'completed_at' => now(),
        ]);

        $component->call('poll')
            ->assertSet('status', ExportOperationStatus::Failed->value)
            ->assertSet('isPolling', false)
            ->assertDontSee('wire:poll.3s="poll"', false)
            ->assertSee('Nie przeniesiono');
    }

    public function test_success_uses_exact_owner_copy_and_a_locally_built_https_link(): void
    {
        $providerId = '0123456789ABCDEFGHIJKL';
        $operation = $this->operation([
            'status' => ExportOperationStatus::Transferred,
            'destination_type' => ExportDestinationType::Linked,
            'active_key' => null,
            'started_at' => now()->subSeconds(10),
            'completed_at' => now(),
        ]);
        $target = Playlist::factory()->for($operation->user)->create([
            'role' => PlaylistRole::ExportTarget,
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => $providerId,
            'source_account_id' => $operation->target_account_id,
            'canonical_source_url' => 'javascript:alert(1)',
        ]);
        $link = PlaylistExportLink::factory()->create([
            'user_id' => $operation->user_id,
            'source_playlist_id' => $operation->source_playlist_id,
            'target_playlist_id' => $target->id,
            'provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => $operation->target_account_id,
        ]);
        $operation->playlistExportLink()->associate($link);
        $operation->save();
        $this->actingAs($operation->user);

        Livewire::test(ExportOperationPanel::class, ['exportOperation' => $operation->fresh()])
            ->assertSet('isPolling', false)
            ->assertSee('Przeniesiona — na Twoim koncie')
            ->assertSee('https://open.spotify.com/playlist/'.$providerId, false)
            ->assertSee('rel="noreferrer noopener"', false)
            ->assertDontSee('javascript:alert', false);
    }

    public function test_managed_success_and_incomplete_failure_use_the_fr_011_copy(): void
    {
        $managed = $this->operation([
            'status' => ExportOperationStatus::Transferred,
            'destination_type' => ExportDestinationType::Managed,
            'active_key' => null,
            'started_at' => now()->subSeconds(10),
            'completed_at' => now(),
        ]);
        $this->actingAs($managed->user);
        Livewire::test(ExportOperationPanel::class, ['exportOperation' => $managed])
            ->assertSee('Przeniesiona — zarządzana przez music-map');

        $incomplete = $this->operation([
            'status' => ExportOperationStatus::Incomplete,
            'failure_code' => ExportOperationFailure::TemporaryFailure,
            'provider_mutation_started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        $this->actingAs($incomplete->user);
        Livewire::test(ExportOperationPanel::class, ['exportOperation' => $incomplete])
            ->assertSee('Nie udało się dokończyć przenoszenia')
            ->assertSee('Platforma przerwała operację. Spróbuj ponownie za kilka minut — zaktualizujemy tę samą playlistę, bez tworzenia kolejnej kopii')
            ->assertSee('Ponów tę samą operację');
    }

    public function test_ambiguous_create_explains_scan_and_requires_explicit_abandonment(): void
    {
        Queue::fake();
        $operation = $this->operation([
            'status' => ExportOperationStatus::Incomplete,
            'failure_code' => ExportOperationFailure::AmbiguousCreate,
            'provider_mutation_started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        $this->actingAs($operation->user);

        $component = Livewire::test(ExportOperationPanel::class, ['exportOperation' => $operation])
            ->assertSee('Ponowienie wykona ono wyłącznie skan')
            ->assertSee('Sprawdziłem playlisty u providera')
            ->call('abandonRecovery')
            ->assertHasErrors('orphanCopiesChecked');

        $component->set('orphanCopiesChecked', true)
            ->call('abandonRecovery')
            ->assertRedirect(route('export-operations.show', [
                $operation->source_playlist_id,
                $operation->operation_id,
            ]));

        $this->assertSame(ExportOperationFailure::RecoveryAbandoned, $operation->fresh()->failure_code);
        Queue::assertNothingPushed();
    }

    /** @param array<string, mixed> $overrides */
    private function operation(array $overrides = []): ExportOperation
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'source_playlist_id' => fake()->unique()->regexify('PL-[A-Za-z0-9]{16}'),
        ]);
        $review = ExportReview::factory()->for($playlist)->create(['user_id' => $user->id]);

        return ExportOperation::factory()->create(array_merge([
            'export_review_id' => $review->id,
            'user_id' => $user->id,
            'source_playlist_id' => $playlist->id,
        ], $overrides))->load('user');
    }
}
