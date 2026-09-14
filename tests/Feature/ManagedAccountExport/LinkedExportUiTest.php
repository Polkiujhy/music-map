<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Jobs\SendManagedExportCompletedNotification;
use App\Livewire\ExportReviewPanel;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\StreamingAccount;
use App\Notifications\ManagedExportCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class LinkedExportUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_success_panel_and_notification_identify_the_users_account(): void
    {
        Notification::fake();
        $operation = $this->operation(ExportOperationStatus::Succeeded);

        Livewire::actingAs($operation->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $operation->exportReview])
            ->assertSee('Przeniesiona — na Twoje połączone konto')
            ->assertSee('Właściciel: Twoje połączone konto.')
            ->assertDontSee('Przeniesiona — zarządzana przez music-map');

        (new SendManagedExportCompletedNotification($operation->id, 0))->handle();
        Notification::assertSentTo($operation->user, ManagedExportCompleted::class, function ($notification) use ($operation): bool {
            $mail = $notification->toMail($operation->user);

            return $mail->viewData['linked'] === true
                && $mail->viewData['statusLabel'] === 'Przeniesiona — na Twoje połączone konto';
        });
    }

    public function test_linked_retry_is_available_only_while_the_same_owned_account_is_connected(): void
    {
        $operation = $this->operation(ExportOperationStatus::Failed);
        $operation->update(['failure_code' => ManagedExportFailureCode::AuthenticationRequired]);
        $component = Livewire::actingAs($operation->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $operation->exportReview])
            ->assertSee('Spróbuj ponownie')
            ->assertSee('Połącz ponownie to samo konto docelowe i nadaj wymagane uprawnienia.');

        $account = $operation->playlistExport->streamingAccount;
        $account->forceFill(['refresh_token' => null])->save();
        $component->call('$refresh')
            ->assertDontSee('Spróbuj ponownie')
            ->assertSee('Połącz ponownie to samo konto docelowe, aby ponowić eksport.');

        $account->forceFill(['refresh_token' => 'canary-token', 'provider_account_id' => 'different-owner'])->save();
        $component->call('$refresh')->assertDontSee('Spróbuj ponownie');
    }

    public function test_review_warns_about_source_deletion_only_when_automatic_synchronization_is_enabled(): void
    {
        $source = Playlist::factory()->create();
        $review = ExportReview::factory()->for($source)->for($source->user)->create(['status' => ExportReviewStatus::Ready]);
        $synchronization = PlaylistSynchronization::factory()->for($source)->create(['automatic_enabled' => true]);

        $component = Livewire::actingAs($source->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $review])
            ->assertSee('Pozycje usunięte z banku przy potwierdzeniu mogą zostać usunięte także z oryginalnej playlisty');

        $synchronization->update(['automatic_enabled' => false]);
        $component->call('$refresh')->assertDontSee('Automatyczna synchronizacja źródła jest włączona.');
    }

    public function test_retry_remains_visible_after_relinking_the_same_identity_with_a_new_local_row(): void
    {
        $operation = $this->operation(ExportOperationStatus::Failed);
        $account = $operation->playlistExport->streamingAccount;
        $ownerId = $account->provider_account_id;
        $account->delete();
        StreamingAccount::factory()->for($operation->user)->create([
            'provider_account_id' => $ownerId,
            'scopes' => StreamingProvider::Spotify->exportScopes(),
        ]);

        Livewire::actingAs($operation->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $operation->exportReview])
            ->assertSee('Spróbuj ponownie');
    }

    public function test_bank_keeps_both_linked_and_managed_history_and_blocks_only_the_current_destination(): void
    {
        config(['services.managed_export.providers.youtube.account_id' => '']);
        $linked = $this->operation(ExportOperationStatus::Succeeded);
        $source = $linked->exportReview->playlist;
        $managedReview = ExportReview::factory()->for($source)->for($source->user)->create(['status' => ExportReviewStatus::Confirmed]);
        $managedExport = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create();
        $managed = ExportOperation::factory()->for($source->user)->for($managedReview)->for($managedExport, 'playlistExport')->create(['status' => ExportOperationStatus::Succeeded]);

        $response = $this->actingAs($source->user)->get(route('bank.index'))->assertOk();
        $response->assertSee('Przeniesiona — na Twoje połączone konto')
            ->assertSee('Przeniesiona — zarządzana przez music-map')
            ->assertSee(route('export-reviews.show', [$source, $linked->export_review_id]), false)
            ->assertSee(route('export-reviews.show', [$source, $managed->export_review_id]), false);
        $this->assertSame(0, substr_count($response->getContent(), '>Rozpocznij przegląd</button>'));
    }

    private function operation(ExportOperationStatus $status): ExportOperation
    {
        $source = Playlist::factory()->create();
        PlaylistItem::factory()->for($source)->create(['position' => 0]);
        $account = StreamingAccount::factory()->for($source->user)->create([
            'scopes' => StreamingProvider::Spotify->exportScopes(),
        ]);
        $destination = [
            'destination_type' => ExportDestinationType::Linked,
            'streaming_account_id' => $account->id,
            'target_account_id' => $account->provider_account_id,
        ];
        $review = ExportReview::factory()->for($source)->for($source->user)->create([
            ...$destination,
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now()->subMinutes(2),
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create($destination);
        PlaylistExportTargetAttempt::factory()->resolved()->for($export, 'playlistExport')->create([
            'target_account_id' => $account->provider_account_id,
        ]);

        return ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create([
            'status' => $status,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'retry_available_at' => now()->subSecond(),
        ]);
    }
}
