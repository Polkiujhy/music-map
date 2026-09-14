<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Livewire\ExportReviewPanel;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManagedExportPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.managed_export.providers.spotify.account_id' => 'managed-owner']);
    }

    public function test_operation_polling_announces_only_a_change_and_stops_at_terminal_status(): void
    {
        $operation = $this->operation(ExportOperationStatus::Queued);
        $component = Livewire::actingAs($operation->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $operation->exportReview])
            ->assertSeeHtml('wire:poll.3s="poll"')
            ->assertSee('W trakcie przenoszenia')
            ->assertSet('statusAnnouncement', '');

        $component->call('poll')->assertSet('statusAnnouncement', '');
        $operation->update(['status' => ExportOperationStatus::Succeeded, 'completed_at' => now()]);
        $component->call('poll')
            ->assertSet('statusAnnouncement', 'Przeniesiona — zarządzana przez music-map')
            ->assertDontSeeHtml('wire:poll.3s="poll"');
    }

    public function test_success_shows_exact_status_owner_link_and_edit_location(): void
    {
        $operation = $this->operation(ExportOperationStatus::Succeeded);

        Livewire::actingAs($operation->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $operation->exportReview])
            ->assertSee('Przeniesiona — zarządzana przez music-map')
            ->assertSee('Właściciel: konto zarządzane przez music-map.')
            ->assertSee('Zmiany wykonuj w playliście źródłowej w Music Map.')
            ->assertSeeHtml('href="https://open.spotify.com/playlist/managed-target-'.$operation->exportReview->playlist_id.'"');
    }

    public function test_partial_failure_shows_exact_same_playlist_copy_safe_cause_and_cooldown(): void
    {
        $operation = $this->operation(ExportOperationStatus::PartialFailed, [
            'failure_code' => ManagedExportFailureCode::RateLimited,
            'retry_available_at' => now()->addMinutes(3),
        ]);

        Livewire::actingAs($operation->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $operation->exportReview])
            ->assertSee('Nie udało się dokończyć przenoszenia')
            ->assertSee('Platforma przerwała operację. Spróbuj ponownie za kilka minut — zaktualizujemy tę samą playlistę, bez tworzenia kolejnej kopii')
            ->assertSee('Przyczyna: Platforma ograniczyła liczbę żądań.')
            ->assertSee('Ponowienie będzie dostępne')
            ->assertDontSee('rate-limited');
    }

    public function test_manual_and_recreate_states_warn_that_the_link_can_change_before_the_confirmed_action(): void
    {
        foreach ([ExportOperationStatus::ManualRecoveryRequired, ExportOperationStatus::RecreateRequired] as $status) {
            $operation = $this->operation($status, ['failure_code' => ManagedExportFailureCode::AmbiguousMutation]);

            Livewire::actingAs($operation->user)
                ->test(ExportReviewPanel::class, ['exportReview' => $operation->exportReview])
                ->assertSee('Jawne odtworzenie może utworzyć nową playlistę i zmienić jej link.')
                ->assertSee('Rozumiem, że odtworzenie może utworzyć nowy link.')
                ->assertSeeHtml('name="confirm_recreation"')
                ->assertSee('Odtwórz cel z nowym linkiem');
        }
    }

    private function operation(ExportOperationStatus $status, array $attributes = []): ExportOperation
    {
        $source = Playlist::factory()->create();
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now()->subMinutes(2),
            'target_account_id' => 'managed-owner',
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_account_id' => 'managed-owner',
        ]);
        $providerPlaylistId = 'managed-target-'.$source->getKey();
        PlaylistExportTargetAttempt::factory()->resolved()->for($export, 'playlistExport')->create([
            'provider_playlist_id' => $providerPlaylistId,
            'canonical_url' => 'https://open.spotify.com/playlist/'.$providerPlaylistId,
        ]);

        return ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create(array_merge([
            'status' => $status,
            'started_at' => now()->subMinutes(2),
            'completed_at' => in_array($status, [ExportOperationStatus::Queued, ExportOperationStatus::Processing], true) ? null : now(),
            'retry_available_at' => now()->subSecond(),
        ], $attributes))->load(['user', 'exportReview']);
    }
}
