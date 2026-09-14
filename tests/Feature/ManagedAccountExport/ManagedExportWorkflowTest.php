<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\ManagedAccountExport\RequestManagedTargetRecreation;
use App\Actions\ManagedAccountExport\RunManagedExport;
use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\Playlists\ImportPlaylist;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\PlaylistOrigin;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Contracts\ManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\Contracts\WithManagedAccountAccess;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessResult;
use App\Integrations\ManagedAccountExport\Data\ManagedMarkerLookup;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistItem;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReconciliationResult;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistSnapshot;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedPlaylistGatewayRegistry;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Jobs\RefreshYouTubePlaylistMetadata;
use App\Jobs\RunManagedExport as RunManagedExportJob;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\PlaylistItem;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagedExportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.managed_export.providers.spotify.account_id' => 'workflow-managed-owner',
            'services.managed_export.providers.spotify.market' => 'GB',
            'services.managed_export.providers.youtube.account_id' => 'workflow-managed-owner',
        ]);
    }

    #[DataProvider('providers')]
    public function test_ready_review_with_twenty_items_and_a_duplicate_completes_as_one_managed_snapshot(
        StreamingProvider $provider,
    ): void {
        $review = $this->readyReview($provider, 20, duplicateAt: 7);
        app(ConfirmExportReview::class)->handle($review->user, $review, []);
        $operation = ExportOperation::query()->where('export_review_id', $review->id)->firstOrFail();
        $gateway = new WorkflowManagedPlaylistGateway($provider);
        $admission = new WorkflowYouTubeAdmission;
        $this->bindWorkflow($gateway, admission: $admission);

        app(RunManagedExport::class)->handle($operation->id);

        $operation->refresh();
        $target = $operation->playlistExport->fresh()->targetPlaylist()->with('items')->firstOrFail();
        $this->assertSame(ExportOperationStatus::Succeeded, $operation->status);
        $this->assertSame(PlaylistOrigin::ManagedTarget, $target->origin);
        $this->assertCount(20, $target->items);
        $this->assertSame($target->items[6]->catalog_id, $target->items[7]->catalog_id);
        $this->assertSame(1, Playlist::query()->where('origin', PlaylistOrigin::ManagedTarget->value)->count());
        $this->assertSame($provider === StreamingProvider::Spotify ? 'private' : 'unlisted', $gateway->visibility);
        $this->assertSame($provider === StreamingProvider::YouTube ? [$operation->id] : [], $admission->operationIds);

        $import = app(ImportPlaylist::class)->handle($review->user, $target->canonical_source_url);
        $this->assertFalse($import->successful);
        $this->assertSame(ImportFailureCode::ExportTargetConflict, $import->failureCode);
        $this->assertSame(1, Playlist::query()->where('origin', PlaylistOrigin::ManagedTarget->value)->count());

        if ($provider === StreamingProvider::YouTube) {
            $target->forceFill(['provider_metadata_refreshed_at' => now()->subDays(29)])->save();
            $this->artisan('playlists:refresh-youtube-metadata')->assertSuccessful();
            Queue::assertNotPushed(RefreshYouTubePlaylistMetadata::class);
            $this->assertNotNull($target->fresh()->name);

            $target->forceFill(['provider_metadata_refreshed_at' => now()->subDays(31)])->save();
            $this->artisan('playlists:refresh-youtube-metadata')->assertSuccessful();
            Queue::assertNotPushed(RefreshYouTubePlaylistMetadata::class);
            $this->assertNotNull($target->fresh()->name);
            $this->assertCount(20, $target->fresh()->items);
        }
    }

    public function test_youtube_quota_refusal_stops_before_access_or_provider_work(): void
    {
        $operation = $this->operation(StreamingProvider::YouTube);
        $gateway = new WorkflowManagedPlaylistGateway(StreamingProvider::YouTube);
        $access = new WorkflowManagedAccess;
        $admission = new WorkflowYouTubeAdmission(refuse: true);
        $this->bindWorkflow($gateway, $access, $admission);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame(0, $access->calls);
        $this->assertSame([], $gateway->calls);
        $this->assertSame(ExportOperationStatus::Failed, $operation->fresh()->status);
        $this->assertSame(ManagedExportFailureCode::QuotaExceeded, $operation->fresh()->failure_code);
    }

    #[DataProvider('providers')]
    public function test_access_failure_before_write_never_mutates_or_materializes(StreamingProvider $provider): void
    {
        $operation = $this->operation($provider);
        $gateway = new WorkflowManagedPlaylistGateway($provider);
        $this->bindWorkflow(
            $gateway,
            new WorkflowManagedAccess(ManagedExportFailureCode::AuthenticationRequired),
            new WorkflowYouTubeAdmission,
        );

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame([], $gateway->calls);
        $this->assertNull($operation->fresh()->possible_mutation_at);
        $this->assertSame(ExportOperationStatus::Failed, $operation->fresh()->status);
        $this->assertNull($operation->playlistExport->fresh()->target_playlist_id);
    }

    #[DataProvider('providers')]
    public function test_ambiguous_create_is_scan_only_on_retry_and_never_materializes(StreamingProvider $provider): void
    {
        $operation = $this->operation($provider);
        $gateway = new WorkflowManagedPlaylistGateway($provider, ambiguousCreate: true);
        $this->bindWorkflow($gateway, admission: new WorkflowYouTubeAdmission);
        $action = app(RunManagedExport::class);

        $action->handle($operation->id);
        $this->travel(301)->seconds();
        $action->handle($operation->id);

        $attempt = $operation->playlistExport->targetAttempts()->firstOrFail();
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_UNKNOWN, $attempt->status);
        $this->assertSame(1, count(array_filter($gateway->calls, fn (string $call): bool => $call === 'create')));
        $this->assertSame(['find', 'create', 'find'], $gateway->calls);
        $this->assertNull($operation->playlistExport->fresh()->target_playlist_id);
    }

    #[DataProvider('markerScanFailures')]
    public function test_inconclusive_or_multiple_marker_scan_never_creates_or_materializes(
        StreamingProvider $provider,
        string $lookupMode,
        ExportOperationStatus $expectedStatus,
    ): void {
        $operation = $this->operation($provider);
        $gateway = new WorkflowManagedPlaylistGateway($provider, lookupMode: $lookupMode);
        $this->bindWorkflow($gateway, admission: new WorkflowYouTubeAdmission);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame(['find'], $gateway->calls);
        $this->assertSame($expectedStatus, $operation->fresh()->status);
        $this->assertNull($operation->playlistExport->fresh()->target_playlist_id);
        $this->assertNull($operation->playlistExport->targetAttempts()->firstOrFail()->create_started_at);
    }

    #[DataProvider('partialWriteCases')]
    public function test_partial_item_write_retry_reuses_the_same_target_and_exact_reservation(
        StreamingProvider $provider,
        int $failAfterPosition,
    ): void {
        $operation = $this->operation($provider, 3);
        $gateway = new WorkflowManagedPlaylistGateway($provider, failAfterPosition: $failAfterPosition);
        $admission = new WorkflowYouTubeAdmission;
        $this->bindWorkflow($gateway, admission: $admission);
        $action = app(RunManagedExport::class);

        $action->handle($operation->id);
        $attempt = $operation->playlistExport->targetAttempts()->firstOrFail();
        $providerId = $attempt->provider_playlist_id;
        $this->assertNotNull($providerId);
        $this->assertSame(ExportOperationStatus::Queued, $operation->fresh()->status);
        $this->assertNull($operation->playlistExport->fresh()->target_playlist_id);
        $this->assertCount($failAfterPosition + 1, $gateway->remoteCatalogItems);

        $this->travel(61)->seconds();
        $action->handle($operation->id);

        $this->assertSame($providerId, $operation->playlistExport->targetAttempts()->firstOrFail()->provider_playlist_id);
        $this->assertSame(1, count(array_filter($gateway->calls, fn (string $call): bool => $call === 'create')));
        $this->assertSame(ExportOperationStatus::Succeeded, $operation->fresh()->status);
        $this->assertCount(3, $gateway->remoteCatalogItems);
        $this->assertSame(
            $provider === StreamingProvider::YouTube ? [$operation->id, $operation->id] : [],
            $admission->operationIds,
        );
        $this->assertLessThanOrEqual(1, count(array_unique($admission->reservationIds)));
    }

    #[DataProvider('providers')]
    public function test_stale_delivery_cannot_claim_a_newer_retry_generation(StreamingProvider $provider): void
    {
        $operation = $this->operation($provider);
        $operation->forceFill(['retry_generation' => 2])->save();
        $gateway = new WorkflowManagedPlaylistGateway($provider);
        $access = new WorkflowManagedAccess;
        $this->bindWorkflow($gateway, $access, new WorkflowYouTubeAdmission);

        app(RunManagedExport::class)->handle($operation->id, 1);

        $this->assertSame(ExportOperationStatus::Queued, $operation->fresh()->status);
        $this->assertSame(0, $operation->fresh()->automatic_claim_count);
        $this->assertSame(0, $access->calls);
        $this->assertSame([], $gateway->calls);
    }

    public function test_lost_dispatch_is_recovered_for_the_same_committed_intent(): void
    {
        $operation = $this->operation(StreamingProvider::Spotify);
        DB::table('export_operations')->where('id', $operation->id)->update([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $before = [
            ExportOperation::query()->count(),
            PlaylistExport::query()->count(),
            Playlist::query()->count(),
        ];

        $this->artisan('managed-exports:recover')->assertSuccessful();

        Queue::assertPushed(RunManagedExportJob::class, fn (RunManagedExportJob $job): bool => $job->exportOperationId === $operation->id);
        $this->assertSame($before, [
            ExportOperation::query()->count(),
            PlaylistExport::query()->count(),
            Playlist::query()->count(),
        ]);
    }

    #[DataProvider('providers')]
    public function test_provider_deletion_requires_recreation_without_a_second_create(StreamingProvider $provider): void
    {
        $operation = $this->operation($provider);
        $this->resolveAttempt($operation);
        // A settled target is outside the initial YouTube visibility grace period.
        $this->travel(6)->minutes();
        $gateway = new WorkflowManagedPlaylistGateway($provider, missing: true);
        $this->bindWorkflow($gateway, admission: new WorkflowYouTubeAdmission);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame(ExportOperationStatus::RecreateRequired, $operation->fresh()->status);
        $this->assertSame(['inspect'], $gateway->calls);
        $this->assertNull($operation->playlistExport->fresh()->target_playlist_id);
    }

    #[DataProvider('providers')]
    public function test_recreate_after_ambiguous_create_preserves_the_previous_marker_and_locator(
        StreamingProvider $provider,
    ): void {
        $operation = $this->operation($provider);
        $old = $operation->playlistExport->targetAttempts()->firstOrFail();
        $old->forceFill([
            'status' => PlaylistExportTargetAttempt::STATUS_UNKNOWN,
            'create_started_at' => now()->subMinute(),
            'provider_playlist_id' => WorkflowManagedPlaylistGateway::providerId($provider),
            'canonical_url' => WorkflowManagedPlaylistGateway::canonicalUrl($provider),
        ])->save();
        $operation->forceFill([
            'status' => ExportOperationStatus::ManualRecoveryRequired,
            'possible_mutation_at' => now()->subMinute(),
            'completed_at' => now(),
            'retry_available_at' => now(),
        ])->save();
        $oldMarker = $old->marker;
        $oldProviderId = $old->provider_playlist_id;

        app(RequestManagedTargetRecreation::class)->handle($operation->user, $operation);

        $attempts = $operation->playlistExport->fresh()->targetAttempts()->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_ABANDONED, $attempts[0]->status);
        $this->assertSame($oldMarker, $attempts[0]->marker);
        $this->assertSame($oldProviderId, $attempts[0]->provider_playlist_id);
        $this->assertSame(2, $attempts[1]->generation);
        $this->assertNotSame($oldMarker, $attempts[1]->marker);
        $this->assertSame(ExportOperationStatus::Queued, $operation->fresh()->status);
    }

    public static function providers(): array
    {
        return [
            'Spotify' => [StreamingProvider::Spotify],
            'YouTube' => [StreamingProvider::YouTube],
        ];
    }

    public static function partialWriteCases(): array
    {
        $cases = [];
        foreach ([StreamingProvider::Spotify, StreamingProvider::YouTube] as $provider) {
            foreach ([0, 1, 2] as $position) {
                $cases[$provider->value.' after item '.($position + 1)] = [$provider, $position];
            }
        }

        return $cases;
    }

    public static function markerScanFailures(): array
    {
        $cases = [];
        foreach ([StreamingProvider::Spotify, StreamingProvider::YouTube] as $provider) {
            $cases[$provider->value.' inconclusive scan'] = [
                $provider,
                'inconclusive',
                ExportOperationStatus::Queued,
            ];
            $cases[$provider->value.' multiple markers'] = [
                $provider,
                'multiple',
                ExportOperationStatus::ManualRecoveryRequired,
            ];
        }

        return $cases;
    }

    private function readyReview(StreamingProvider $provider, int $itemCount, int $duplicateAt): ExportReview
    {
        $source = Playlist::factory()->create([
            'source_provider' => $provider === StreamingProvider::Spotify
                ? StreamingProvider::YouTube
                : StreamingProvider::Spotify,
            'source_playlist_id' => 'workflow-source',
        ]);
        for ($position = 0; $position < $itemCount; $position++) {
            PlaylistItem::factory()->for($source)->create([
                'position' => $position,
                'occurrence_id' => 'source-occurrence-'.$position,
                'catalog_id' => 'source-'.$position,
                'catalog_uri' => 'source:item:'.$position,
            ]);
        }
        $source->load(['user', 'items']);
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Ready,
            'target_provider' => $provider,
            'target_account_id' => 'workflow-managed-owner',
            'target_market' => $provider === StreamingProvider::Spotify ? 'GB' : null,
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($source),
        ]);
        for ($position = 0; $position < $itemCount; $position++) {
            $catalogPosition = $position === $duplicateAt ? $position - 1 : $position;
            ExportReviewItem::factory()->for($review)->create($this->reviewItem($provider, $position, $catalogPosition));
        }

        return $review;
    }

    private function operation(StreamingProvider $provider, int $itemCount = 1): ExportOperation
    {
        $source = Playlist::factory()->create([
            'source_provider' => $provider === StreamingProvider::Spotify
                ? StreamingProvider::YouTube
                : StreamingProvider::Spotify,
            'source_playlist_id' => 'workflow-source',
        ]);
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
            'target_provider' => $provider,
            'target_account_id' => 'workflow-managed-owner',
            'target_market' => $provider === StreamingProvider::Spotify ? 'GB' : null,
        ]);
        for ($position = 0; $position < $itemCount; $position++) {
            ExportReviewItem::factory()->for($review)->create($this->reviewItem($provider, $position, $position));
        }
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_provider' => $provider,
            'target_account_id' => 'workflow-managed-owner',
            'target_market' => $provider === StreamingProvider::Spotify ? 'GB' : null,
        ]);
        PlaylistExportTargetAttempt::factory()->for($export, 'playlistExport')->create();

        return ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create();
    }

    private function reviewItem(StreamingProvider $provider, int $position, int $catalogPosition): array
    {
        return [
            'position' => $position,
            'source_occurrence_id' => 'source-occurrence-'.$position,
            'source_catalog_id' => 'source-'.$position,
            'source_catalog_uri' => 'source:item:'.$position,
            'target_catalog_id' => 'target-'.$catalogPosition,
            'target_catalog_uri' => $provider === StreamingProvider::Spotify
                ? 'spotify:track:'.$catalogPosition
                : 'https://www.youtube.com/watch?v=target-'.$catalogPosition,
        ];
    }

    private function bindWorkflow(
        WorkflowManagedPlaylistGateway $gateway,
        ?WorkflowManagedAccess $access = null,
        ?WorkflowYouTubeAdmission $admission = null,
    ): void {
        $this->app->instance(ManagedPlaylistGatewayRegistry::class, new ManagedPlaylistGatewayRegistry($gateway));
        $this->app->instance(WithManagedAccountAccess::class, $access ?? new WorkflowManagedAccess);
        $this->app->instance(AdmitYouTubeWrite::class, $admission ?? new WorkflowYouTubeAdmission);
    }

    private function resolveAttempt(ExportOperation $operation): void
    {
        $provider = $operation->playlistExport->target_provider;
        $operation->playlistExport->targetAttempts()->firstOrFail()->forceFill([
            'status' => PlaylistExportTargetAttempt::STATUS_RESOLVED,
            'create_started_at' => now()->subSecond(),
            'create_completed_at' => now(),
            'provider_playlist_id' => WorkflowManagedPlaylistGateway::providerId($provider),
            'canonical_url' => WorkflowManagedPlaylistGateway::canonicalUrl($provider),
        ])->save();
    }
}

final class WorkflowManagedAccess implements WithManagedAccountAccess
{
    public int $calls = 0;

    public function __construct(private ?ManagedExportFailureCode $failure = null) {}

    public function handle(
        StreamingProvider $provider,
        string $operationId,
        string $expectedAccountId,
        array $requiredScopes,
        Closure $callback,
    ): ManagedAccessResult {
        $this->calls++;
        if ($this->failure !== null) {
            return ManagedAccessResult::failure($this->failure);
        }

        $result = $callback(new ManagedAccessContext(
            $provider,
            $expectedAccountId,
            'workflow-ephemeral-token',
            new DateTimeImmutable('+1 hour'),
            $operationId,
        ));

        return $result instanceof ManagedExportFailureCode
            ? ManagedAccessResult::failure($result)
            : ManagedAccessResult::success();
    }
}

final class WorkflowManagedPlaylistGateway implements ManagedPlaylistGateway
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $remoteCatalogItems = [];

    public ?string $visibility = null;

    private ?ManagedPlaylistMetadata $metadata = null;

    private bool $partialFailureDelivered = false;

    public function __construct(
        private StreamingProvider $streamingProvider,
        private bool $ambiguousCreate = false,
        private ?int $failAfterPosition = null,
        private bool $missing = false,
        private string $lookupMode = 'none',
    ) {}

    public function provider(): StreamingProvider
    {
        return $this->streamingProvider;
    }

    public function findByMarker(ManagedAccessContext $access, string $marker): ManagedMarkerLookup|ManagedProviderFailure
    {
        $this->calls[] = 'find';

        if ($this->lookupMode === 'inconclusive') {
            return ManagedMarkerLookup::inconclusive();
        }
        if ($this->lookupMode === 'multiple') {
            return ManagedMarkerLookup::ambiguous([
                $this->reference($access->providerAccountId),
                new ManagedPlaylistReference(
                    $this->streamingProvider,
                    self::providerId($this->streamingProvider).'-duplicate',
                    self::canonicalUrl($this->streamingProvider).'-duplicate',
                    $access->providerAccountId,
                ),
            ]);
        }

        return ManagedMarkerLookup::none();
    }

    public function create(ManagedAccessContext $access, ManagedPlaylistMetadata $metadata): ManagedPlaylistReference|ManagedProviderFailure
    {
        $this->calls[] = 'create';
        $this->metadata = $metadata;

        return $this->ambiguousCreate
            ? new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation)
            : $this->reference($access->providerAccountId);
    }

    public function inspect(ManagedAccessContext $access, ManagedPlaylistReference $reference): ManagedPlaylistSnapshot|ManagedProviderFailure
    {
        $this->calls[] = 'inspect';
        if ($this->missing) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetMissing);
        }

        return $this->snapshot($reference, $this->metadata ?? $this->metadataForExistingTarget());
    }

    public function reconcile(
        ManagedAccessContext $access,
        ManagedPlaylistReference $reference,
        ManagedPlaylistMetadata $metadata,
        array $catalogItems,
    ): ManagedPlaylistReconciliationResult|ManagedProviderFailure {
        $this->calls[] = 'reconcile';
        $this->metadata = $metadata;
        if ($this->failAfterPosition !== null && ! $this->partialFailureDelivered) {
            $this->partialFailureDelivered = true;
            $this->remoteCatalogItems = array_slice(array_values($catalogItems), 0, $this->failAfterPosition + 1);

            return new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation, true, 60);
        }

        $this->remoteCatalogItems = array_values($catalogItems);

        return ManagedPlaylistReconciliationResult::exact($this->snapshot($reference, $metadata));
    }

    public static function providerId(StreamingProvider $provider): string
    {
        return $provider === StreamingProvider::Spotify
            ? '0123456789ABCDEFGHIJKL'
            : 'PL_workflow_managed_target';
    }

    public static function canonicalUrl(StreamingProvider $provider): string
    {
        return $provider === StreamingProvider::Spotify
            ? 'https://open.spotify.com/playlist/'.self::providerId($provider)
            : 'https://www.youtube.com/playlist?list='.self::providerId($provider);
    }

    private function reference(string $owner): ManagedPlaylistReference
    {
        return new ManagedPlaylistReference(
            $this->streamingProvider,
            self::providerId($this->streamingProvider),
            self::canonicalUrl($this->streamingProvider),
            $owner,
        );
    }

    private function snapshot(
        ManagedPlaylistReference $reference,
        ManagedPlaylistMetadata $metadata,
    ): ManagedPlaylistSnapshot {
        $this->visibility = $this->streamingProvider === StreamingProvider::Spotify ? 'private' : 'unlisted';
        $items = array_map(
            fn (string $catalog, int $position): ManagedPlaylistItem => $this->streamingProvider === StreamingProvider::Spotify
                ? new ManagedPlaylistItem('spotify-item-'.$position, $catalog, $position)
                : new ManagedPlaylistItem($catalog, 'https://www.youtube.com/watch?v='.$catalog, $position, 'occurrence-'.$position),
            $this->remoteCatalogItems,
            array_keys($this->remoteCatalogItems),
        );

        return new ManagedPlaylistSnapshot($reference, $metadata, $this->visibility, $items, 'workflow-revision');
    }

    private function metadataForExistingTarget(): ManagedPlaylistMetadata
    {
        return new ManagedPlaylistMetadata(
            'Existing managed target',
            "Managed by Music Map.\nmusic-map-managed-export:v1:00000000-0000-0000-0000-000000000000",
            '00000000-0000-0000-0000-000000000000',
        );
    }
}

final class WorkflowYouTubeAdmission implements AdmitYouTubeWrite
{
    /** @var list<string> */
    public array $operationIds = [];

    /** @var list<int> */
    public array $reservationIds = [];

    public function __construct(private bool $refuse = false) {}

    public function admit(YouTubeWriteOperationType $operationType, string $operationId): YouTubeWriteAdmissionResult
    {
        $this->operationIds[] = $operationId;
        $day = CarbonImmutable::now('America/Los_Angeles')->format('Y-m-d');
        $reset = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'America/Los_Angeles')->addDay();
        if ($this->refuse) {
            return YouTubeWriteAdmissionResult::limitReached($day, $reset);
        }

        $this->reservationIds[] = 1;

        return count($this->operationIds) === 1
            ? YouTubeWriteAdmissionResult::admittedNew(1, $day, $reset)
            : YouTubeWriteAdmissionResult::admittedExisting(1, $day, $reset);
    }
}
