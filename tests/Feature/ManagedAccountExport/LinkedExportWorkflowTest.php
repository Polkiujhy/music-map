<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\ManagedAccountExport\RunManagedExport;
use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Contracts\ManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedMarkerLookup;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistItem;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReconciliationResult;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistSnapshot;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedPlaylistGatewayRegistry;
use App\Integrations\ManagedAccountExport\WithLinkedAccountExportAccess;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\StreamingAccount;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LinkedExportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public static function providers(): array
    {
        return [[StreamingProvider::Spotify], [StreamingProvider::YouTube]];
    }

    #[DataProvider('providers')]
    public function test_linked_export_uses_the_durable_operation_and_materializes_exact_target(StreamingProvider $provider): void
    {
        [$operation, $account] = $this->operation($provider);
        $this->bindAccess();
        $gateway = Mockery::mock(ManagedPlaylistGateway::class);
        $gateway->shouldReceive('provider')->andReturn($provider);
        $gateway->shouldReceive('findByMarker')->once()->andReturn(ManagedMarkerLookup::none());
        $reference = new ManagedPlaylistReference($provider, 'target-id',
            $provider === StreamingProvider::Spotify ? 'https://open.spotify.com/playlist/target-id'
                : 'https://www.youtube.com/playlist?list=target-id', $account->provider_account_id);
        $gateway->shouldReceive('create')->once()->andReturnUsing(function (ManagedAccessContext $context) use ($reference) {
            $this->assertNull($context->mutationFailure());

            return $reference;
        });
        $gateway->shouldReceive('reconcile')->once()->andReturnUsing(
            function (ManagedAccessContext $context, ManagedPlaylistReference $reference, ManagedPlaylistMetadata $metadata, array $items) use ($provider) {
                $this->assertNull($context->mutationFailure());

                return ManagedPlaylistReconciliationResult::exact(new ManagedPlaylistSnapshot(
                    $reference, $metadata, $provider === StreamingProvider::Spotify ? 'private' : 'unlisted',
                    [new ManagedPlaylistItem('track-one', $provider === StreamingProvider::Spotify
                        ? 'spotify:track:track-one' : 'https://www.youtube.com/watch?v=track-one', 0, 'occurrence-one')],
                ));
            });
        $this->app->instance(ManagedPlaylistGatewayRegistry::class, new ManagedPlaylistGatewayRegistry($gateway));
        if ($provider === StreamingProvider::YouTube) {
            $admission = Mockery::mock(AdmitYouTubeWrite::class);
            $admission->shouldReceive('admit')->once()->with(YouTubeWriteOperationType::LinkedExport, $operation->id)
                ->andReturn(YouTubeWriteAdmissionResult::admittedNew(1, '2026-09-14', new \DateTimeImmutable('2026-09-15 00:00:00', new \DateTimeZone('America/Los_Angeles'))));
            $this->app->instance(AdmitYouTubeWrite::class, $admission);
        }

        app(RunManagedExport::class)->handle($operation->id, 0);
        app(RunManagedExport::class)->handle($operation->id, 0);

        $this->assertSame(ExportOperationStatus::Succeeded, $operation->fresh()->status);
        $target = $operation->playlistExport->fresh()->targetPlaylist;
        $this->assertSame($account->provider_account_id, $target->source_account_id);
        $this->assertSame(['track-one'], $target->items->pluck('catalog_id')->all());
        $this->assertSame(1, $operation->fresh()->automatic_claim_count);
        $this->assertSame(1, ExportOperation::query()->count());
    }

    public function test_unlink_after_access_blocks_later_mutations(): void
    {
        [$operation, $account] = $this->operation(StreamingProvider::Spotify);
        $this->bindAccess();
        $result = app(WithLinkedAccountExportAccess::class)->handle($operation,
            function (ManagedAccessContext $context) use ($account): ?ManagedExportFailureCode {
                $this->assertNull($context->mutationFailure());
                $account->forceFill(['refresh_token' => null, 'credential_version' => 2])->save();

                return $context->mutationFailure();
            });
        $this->assertSame(ManagedExportFailureCode::AuthenticationRequired, $result->failure);
    }

    #[DataProvider('providers')]
    public function test_import_winning_before_create_checkpoint_is_never_reconciled_as_a_target(StreamingProvider $provider): void
    {
        [$operation, $account] = $this->operation($provider);
        $this->bindAccess();
        $gateway = Mockery::mock(ManagedPlaylistGateway::class);
        $gateway->shouldReceive('provider')->andReturn($provider);
        $gateway->shouldReceive('findByMarker')->once()->andReturn(ManagedMarkerLookup::none());
        $gateway->shouldReceive('create')->once()->andReturnUsing(function () use ($operation, $account, $provider) {
            Playlist::factory()->for($operation->user)->create([
                'source_provider' => $provider,
                'source_playlist_id' => 'raced-target',
                'source_account_id' => $account->provider_account_id,
                'name' => 'Independent source',
            ]);

            return new ManagedPlaylistReference($provider, 'raced-target',
                $provider === StreamingProvider::Spotify ? 'https://open.spotify.com/playlist/raced-target'
                    : 'https://www.youtube.com/playlist?list=raced-target', $account->provider_account_id);
        });
        $gateway->shouldNotReceive('reconcile');
        $this->app->instance(ManagedPlaylistGatewayRegistry::class, new ManagedPlaylistGatewayRegistry($gateway));
        if ($provider === StreamingProvider::YouTube) {
            $admission = Mockery::mock(AdmitYouTubeWrite::class);
            $admission->shouldReceive('admit')->once()->with(YouTubeWriteOperationType::LinkedExport, $operation->id)
                ->andReturn(YouTubeWriteAdmissionResult::admittedExisting(1, '2026-09-14', new \DateTimeImmutable('2026-09-15 00:00:00', new \DateTimeZone('America/Los_Angeles'))));
            $this->app->instance(AdmitYouTubeWrite::class, $admission);
        }

        app(RunManagedExport::class)->handle($operation->id, 0);

        $this->assertSame(ExportOperationStatus::PartialFailed, $operation->fresh()->status);
        $this->assertSame(ManagedExportFailureCode::PersistenceFailure, $operation->fresh()->failure_code);
        $this->assertSame('raced-target', $operation->playlistExport->targetAttempts()->firstOrFail()->provider_playlist_id);
        $this->assertSame('Independent source', Playlist::query()->where('source_playlist_id', 'raced-target')->firstOrFail()->name);
        $this->assertNull($operation->playlistExport->fresh()->target_playlist_id);
    }

    public function test_same_identity_relink_works_but_another_identity_never_receives_old_export(): void
    {
        [$operation, $account] = $this->operation(StreamingProvider::Spotify);
        $account->delete();
        StreamingAccount::factory()->for($operation->user)->create([
            'provider_account_id' => 'other-account', 'scopes' => StreamingProvider::Spotify->exportScopes(),
        ]);
        $this->bindAccess();
        $failure = app(WithLinkedAccountExportAccess::class)->handle($operation, fn () => $this->fail('Wrong account callback.'));
        $this->assertSame(ManagedExportFailureCode::AuthenticationRequired, $failure->failure);
        StreamingAccount::query()->where('user_id', $operation->user_id)->delete();
        StreamingAccount::factory()->for($operation->user)->create([
            'provider_account_id' => 'linked-owner', 'scopes' => StreamingProvider::Spotify->exportScopes(),
        ]);
        $this->assertTrue(app(WithLinkedAccountExportAccess::class)->handle($operation, fn () => null)->successful);
    }

    public function test_short_lived_token_is_rejected_before_callback(): void
    {
        [$operation] = $this->operation(StreamingProvider::Spotify);
        $this->bindAccess(120);
        $result = app(WithLinkedAccountExportAccess::class)->handle($operation, fn () => $this->fail('Expiring token used.'));
        $this->assertSame(ManagedExportFailureCode::AuthenticationRequired, $result->failure);
    }

    private function bindAccess(int $lifetime = 3600): void
    {
        $access = Mockery::mock(WithStreamingAccess::class);
        $access->shouldReceive('handle')->andReturnUsing(function ($owner, StreamingAccount $account, array $scopes, Closure $callback) use ($lifetime) {
            $this->assertSame($account->provider->exportScopes(), $scopes);
            $callback(new StreamingAccessContext($account->provider, $account->provider_account_id,
                'secret-test-access', now()->addSeconds($lifetime)->toDateTimeImmutable(), $account->credential_version));

            return StreamingAccessResult::success();
        });
        $this->app->instance(WithStreamingAccess::class, $access);
    }

    private function operation(StreamingProvider $provider): array
    {
        $source = Playlist::factory()->create();
        $account = StreamingAccount::factory()->for($source->user)->create([
            'provider' => $provider, 'provider_account_id' => 'linked-owner', 'scopes' => $provider->exportScopes(),
        ]);
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed, 'confirmed_at' => now(),
            'target_provider' => $provider, 'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => 'linked-owner', 'streaming_account_id' => $account->id,
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0, 'target_catalog_id' => 'track-one',
            'target_catalog_uri' => $provider === StreamingProvider::Spotify
                ? 'spotify:track:track-one' : 'https://www.youtube.com/watch?v=track-one',
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_provider' => $provider, 'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => 'linked-owner', 'streaming_account_id' => $account->id,
        ]);
        PlaylistExportTargetAttempt::factory()->for($export, 'playlistExport')->create([
            'target_provider' => $provider, 'target_account_id' => 'linked-owner',
        ]);

        return [ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create(), $account];
    }
}
