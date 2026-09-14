<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Jobs\RunManagedExport;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LinkedExportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('recoveryActions')]
    public function test_same_identity_relink_allows_recovery_without_redirecting_the_target(bool $recreate): void
    {
        Queue::fake();
        [$operation, $account, $attempt] = $this->operation($recreate);
        $originalAccountId = $account->id;
        $ownerId = $account->provider_account_id;
        $account->delete();
        $replacement = StreamingAccount::factory()->for($operation->user)->create([
            'provider_account_id' => $ownerId,
            'scopes' => StreamingProvider::Spotify->exportScopes(),
        ]);
        $this->assertNotSame($originalAccountId, $replacement->id);

        $this->actingAs($operation->user)->post($this->url($operation, $recreate), [
            'confirm_recreation' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Queued, $operation->status);
        $this->assertSame(1, $operation->retry_generation);
        $this->assertSame($ownerId, $operation->playlistExport->target_account_id);
        $this->assertSame($attempt->marker, $attempt->fresh()->marker);
        $this->assertSame($attempt->provider_playlist_id, $attempt->fresh()->provider_playlist_id);
        $this->assertSame($recreate ? 2 : 1, $operation->playlistExport->targetAttempts()->count());
        if ($recreate) {
            $this->assertSame(PlaylistExportTargetAttempt::STATUS_ABANDONED, $attempt->fresh()->status);
        }
        Queue::assertPushed(RunManagedExport::class, fn ($job): bool => $job->exportOperationId === $operation->id);
    }

    #[DataProvider('unavailableAccounts')]
    public function test_recovery_rejects_unavailable_or_different_identity_without_changing_history(bool $recreate, string $problem): void
    {
        Queue::fake();
        [$operation, $account, $attempt] = $this->operation($recreate);
        $account->forceFill(match ($problem) {
            'different_identity' => ['provider_account_id' => 'different-owner'],
            'different_user' => ['user_id' => User::factory()->create()->id],
            'different_provider' => ['provider' => StreamingProvider::YouTube],
            'disconnected' => ['refresh_token' => null],
            'missing_scope' => ['scopes' => ['playlist-read-private']],
        })->save();

        $this->actingAs($operation->user)->post($this->url($operation, $recreate), [
            'confirm_recreation' => '1',
        ])->assertSessionHasErrors('operation');

        $this->assertSame($operation->status, $operation->fresh()->status);
        $this->assertSame(0, $operation->fresh()->retry_generation);
        $this->assertSame(1, $operation->playlistExport->fresh()->target_generation);
        $this->assertSame(1, $operation->playlistExport->targetAttempts()->count());
        $this->assertSame($attempt->status, $attempt->fresh()->status);
        Queue::assertNothingPushed();
    }

    public static function recoveryActions(): array
    {
        return ['retry' => [false], 'recreate' => [true]];
    }

    public static function unavailableAccounts(): array
    {
        $cases = [];
        foreach (['retry' => false, 'recreate' => true] as $action => $recreate) {
            foreach (['different_identity', 'different_user', 'different_provider', 'disconnected', 'missing_scope'] as $problem) {
                $cases[$action.'_'.$problem] = [$recreate, $problem];
            }
        }

        return $cases;
    }

    private function url(ExportOperation $operation, bool $recreate): string
    {
        return route($recreate ? 'managed-exports.recreate' : 'managed-exports.retry', [
            $operation->playlistExport->source_playlist_id,
            $operation,
        ]);
    }

    /** @return array{ExportOperation, StreamingAccount, PlaylistExportTargetAttempt} */
    private function operation(bool $recreate): array
    {
        $source = Playlist::factory()->create();
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
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create($destination);
        $attempt = PlaylistExportTargetAttempt::factory()->resolved()->for($export, 'playlistExport')->create([
            'target_account_id' => $account->provider_account_id,
        ]);
        $operation = ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create([
            'status' => $recreate ? ExportOperationStatus::RecreateRequired : ExportOperationStatus::PartialFailed,
            'retry_available_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        return [$operation, $account, $attempt];
    }
}
