<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\ManagedAccountExport\RunManagedExport;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
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
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunManagedExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_claims_creates_checkpoints_reconciles_and_materializes_exact_target(): void
    {
        $operation = $this->operation();
        $gateway = new RecordingManagedPlaylistGateway;
        $this->app->instance(ManagedPlaylistGatewayRegistry::class, new ManagedPlaylistGatewayRegistry($gateway));
        $this->app->instance(WithManagedAccountAccess::class, new ImmediateManagedAccess);

        app(RunManagedExport::class)->handle($operation->id);

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Succeeded, $operation->status);
        $this->assertSame(1, $operation->attempt_generation);
        $this->assertSame(1, $operation->automatic_claim_count);
        $this->assertSame(['find', 'create', 'reconcile'], $gateway->calls);
        $this->assertNotNull($operation->playlistExport->fresh()->target_playlist_id);
        $this->assertSame('provider-target', $operation->playlistExport->targetAttempts()->firstOrFail()->provider_playlist_id);
    }

    public function test_known_target_404_requires_recreate_and_never_scans_or_creates(): void
    {
        $operation = $this->operation();
        $attempt = $operation->playlistExport->targetAttempts()->firstOrFail();
        $attempt->forceFill([
            'status' => PlaylistExportTargetAttempt::STATUS_RESOLVED,
            'provider_playlist_id' => 'missing-target',
            'canonical_url' => 'https://open.spotify.com/playlist/missing-target',
        ])->save();
        $gateway = new RecordingManagedPlaylistGateway(missing: true);
        $this->app->instance(ManagedPlaylistGatewayRegistry::class, new ManagedPlaylistGatewayRegistry($gateway));
        $this->app->instance(WithManagedAccountAccess::class, new ImmediateManagedAccess);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame(ExportOperationStatus::RecreateRequired, $operation->fresh()->status);
        $this->assertSame(['inspect'], $gateway->calls);
    }

    public function test_durable_claim_budget_is_not_reset_by_new_deliveries(): void
    {
        $operation = $this->operation();
        $gateway = new RecordingManagedPlaylistGateway(inconclusive: true);
        $this->app->instance(ManagedPlaylistGatewayRegistry::class, new ManagedPlaylistGatewayRegistry($gateway));
        $this->app->instance(WithManagedAccountAccess::class, new ImmediateManagedAccess);

        $action = app(RunManagedExport::class);
        $action->handle($operation->id);
        $this->travel(301)->seconds();
        $action->handle($operation->id);
        $this->travel(301)->seconds();
        $action->handle($operation->id);

        $operation->refresh();
        $this->assertSame(3, $operation->automatic_claim_count);
        $this->assertSame(ExportOperationStatus::Failed, $operation->status);
    }

    public function test_youtube_admission_happens_before_access_and_refusal_performs_no_provider_work(): void
    {
        $events = new ManagedExportEventLog;
        $operation = $this->operation(StreamingProvider::YouTube);
        $gateway = new RecordingManagedPlaylistGateway(StreamingProvider::YouTube, events: $events);
        $access = new ImmediateManagedAccess($events);
        $admission = new RecordingYouTubeAdmission($events, limitReached: true);
        $this->bindFakes($gateway, $access, $admission);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame(['admission'], $events->entries);
        $this->assertSame([$operation->id], $admission->operationIds);
        $this->assertSame(0, $access->calls);
        $this->assertSame([], $gateway->calls);
        $this->assertSame(ExportOperationStatus::Failed, $operation->fresh()->status);
        $this->assertSame(ManagedExportFailureCode::QuotaExceeded, $operation->fresh()->failure_code);
    }

    public function test_failure_before_write_never_calls_the_gateway_and_is_failed_not_partial(): void
    {
        $operation = $this->operation();
        $gateway = new RecordingManagedPlaylistGateway;
        $access = new ImmediateManagedAccess(failure: ManagedExportFailureCode::AuthenticationRequired);
        $this->bindFakes($gateway, $access);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame([], $gateway->calls);
        $this->assertNull($operation->fresh()->possible_mutation_at);
        $this->assertSame(ExportOperationStatus::Failed, $operation->fresh()->status);
    }

    public function test_ambiguous_create_is_never_repeated_and_exhaustion_requires_manual_recovery(): void
    {
        $operation = $this->operation();
        $gateway = new RecordingManagedPlaylistGateway;
        $gateway->createFailure = new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation);
        $this->bindFakes($gateway);
        $action = app(RunManagedExport::class);

        $action->handle($operation->id);
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_UNKNOWN, $operation->playlistExport->targetAttempts()->firstOrFail()->status);
        $this->assertNull($operation->playlistExport->targetAttempts()->firstOrFail()->provider_playlist_id);
        $this->travel(301)->seconds();
        $action->handle($operation->id);
        $this->travel(301)->seconds();
        $action->handle($operation->id);

        $this->assertSame(1, count(array_filter($gateway->calls, fn (string $call): bool => $call === 'create')));
        $this->assertSame(ExportOperationStatus::ManualRecoveryRequired, $operation->fresh()->status);
    }

    public function test_inconclusive_scan_never_creates_a_target(): void
    {
        $operation = $this->operation();
        $gateway = new RecordingManagedPlaylistGateway(inconclusive: true);
        $this->bindFakes($gateway);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame(['find'], $gateway->calls);
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_PENDING, $operation->playlistExport->targetAttempts()->firstOrFail()->status);
        $this->assertNull($operation->playlistExport->targetAttempts()->firstOrFail()->create_started_at);
    }

    public function test_multiple_marker_matches_stop_without_inspect_or_create(): void
    {
        $operation = $this->operation();
        $gateway = new RecordingManagedPlaylistGateway(multipleMarkers: true);
        $this->bindFakes($gateway);

        app(RunManagedExport::class)->handle($operation->id);

        $this->assertSame(['find'], $gateway->calls);
        $this->assertSame(ExportOperationStatus::ManualRecoveryRequired, $operation->fresh()->status);
    }

    public function test_post_create_partial_write_checkpoints_locator_and_exact_retry_reuses_same_target(): void
    {
        $events = new ManagedExportEventLog;
        $operation = $this->operation(StreamingProvider::YouTube);
        $gateway = new RecordingManagedPlaylistGateway(StreamingProvider::YouTube, events: $events);
        $gateway->reconcileFailure = new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation, true, 60);
        $admission = new RecordingYouTubeAdmission($events);
        $this->bindFakes($gateway, new ImmediateManagedAccess($events), $admission);
        $action = app(RunManagedExport::class);

        $action->handle($operation->id);
        $attempt = $operation->playlistExport->targetAttempts()->firstOrFail();
        $this->assertSame('provider-target', $attempt->provider_playlist_id);
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_RESOLVED, $attempt->status);
        $this->assertSame(ExportOperationStatus::Queued, $operation->fresh()->status);

        $gateway->reconcileFailure = null;
        $this->travel(61)->seconds();
        $action->handle($operation->id);

        $this->assertSame(1, count(array_filter($gateway->calls, fn (string $call): bool => $call === 'create')));
        $this->assertSame(2, count(array_filter($gateway->calls, fn (string $call): bool => $call === 'reconcile')));
        $this->assertSame([$operation->id, $operation->id], $admission->operationIds);
        $this->assertSame([
            YouTubeWriteOperationType::ManagedExport,
            YouTubeWriteOperationType::ManagedExport,
        ], $admission->operationTypes);
        $this->assertSame('provider-target', $operation->playlistExport->targetAttempts()->firstOrFail()->provider_playlist_id);
        $this->assertSame(ExportOperationStatus::Succeeded, $operation->fresh()->status);
    }

    private function operation(StreamingProvider $provider = StreamingProvider::Spotify): ExportOperation
    {
        $source = Playlist::factory()->create([
            'source_provider' => $provider === StreamingProvider::Spotify
                ? StreamingProvider::YouTube
                : StreamingProvider::Spotify,
        ]);
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
            'target_provider' => $provider,
            'target_account_id' => 'managed-owner',
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'target_catalog_id' => 'target-one',
            'target_catalog_uri' => $provider === StreamingProvider::Spotify
                ? 'spotify:track:one'
                : 'https://www.youtube.com/watch?v=target-one',
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_provider' => $provider,
            'target_account_id' => 'managed-owner',
        ]);
        PlaylistExportTargetAttempt::factory()->for($export, 'playlistExport')->create([
            'target_provider' => $provider,
            'target_account_id' => 'managed-owner',
        ]);

        return ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create();
    }

    private function bindFakes(
        RecordingManagedPlaylistGateway $gateway,
        ?ImmediateManagedAccess $access = null,
        ?RecordingYouTubeAdmission $admission = null,
    ): void {
        $this->app->instance(ManagedPlaylistGatewayRegistry::class, new ManagedPlaylistGatewayRegistry($gateway));
        $this->app->instance(WithManagedAccountAccess::class, $access ?? new ImmediateManagedAccess);
        if ($admission !== null) {
            $this->app->instance(AdmitYouTubeWrite::class, $admission);
        }
    }
}

final class ImmediateManagedAccess implements WithManagedAccountAccess
{
    public int $calls = 0;

    public function __construct(
        private ?ManagedExportEventLog $events = null,
        private ?ManagedExportFailureCode $failure = null,
    ) {}

    public function handle(StreamingProvider $provider, string $operationId, string $expectedAccountId, array $requiredScopes, Closure $callback): ManagedAccessResult
    {
        $this->calls++;
        if ($this->events !== null) {
            $this->events->entries[] = 'access';
        }
        if ($this->failure !== null) {
            return ManagedAccessResult::failure($this->failure);
        }

        $result = $callback(new ManagedAccessContext(
            $provider,
            $expectedAccountId,
            'secret-test-token',
            new DateTimeImmutable('+1 hour'),
            $operationId,
        ));

        return $result instanceof ManagedExportFailureCode
            ? ManagedAccessResult::failure($result)
            : ManagedAccessResult::success();
    }
}

final class RecordingManagedPlaylistGateway implements ManagedPlaylistGateway
{
    /** @var list<string> */
    public array $calls = [];

    private ?ManagedPlaylistReference $target = null;

    private ?ManagedPlaylistMetadata $lastMetadata = null;

    public ?ManagedProviderFailure $createFailure = null;

    public ?ManagedProviderFailure $reconcileFailure = null;

    public function __construct(
        private StreamingProvider $streamingProvider = StreamingProvider::Spotify,
        private bool $missing = false,
        private bool $inconclusive = false,
        private bool $multipleMarkers = false,
        private ?ManagedExportEventLog $events = null,
    ) {}

    public function provider(): StreamingProvider
    {
        return $this->streamingProvider;
    }

    public function findByMarker(ManagedAccessContext $access, string $marker): ManagedMarkerLookup|ManagedProviderFailure
    {
        $this->calls[] = 'find';
        if ($this->events !== null) {
            $this->events->entries[] = 'find';
        }

        if ($this->multipleMarkers) {
            return ManagedMarkerLookup::ambiguous([
                $this->reference('marker-one', $access->providerAccountId),
                $this->reference('marker-two', $access->providerAccountId),
            ]);
        }

        return $this->inconclusive ? ManagedMarkerLookup::inconclusive() : ManagedMarkerLookup::none();
    }

    public function create(ManagedAccessContext $access, ManagedPlaylistMetadata $metadata): ManagedPlaylistReference|ManagedProviderFailure
    {
        $this->calls[] = 'create';
        if ($this->events !== null) {
            $this->events->entries[] = 'create';
        }
        $this->lastMetadata = $metadata;
        if ($this->createFailure !== null) {
            return $this->createFailure;
        }

        return $this->target = $this->reference('provider-target', $access->providerAccountId);
    }

    public function inspect(ManagedAccessContext $access, ManagedPlaylistReference $reference): ManagedPlaylistSnapshot|ManagedProviderFailure
    {
        $this->calls[] = 'inspect';
        if ($this->events !== null) {
            $this->events->entries[] = 'inspect';
        }
        if ($this->missing) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetMissing);
        }

        return $this->snapshot($reference, $this->lastMetadata ?? new ManagedPlaylistMetadata(
            'Canary playlist — Music Map',
            "Kopia zarządzana przez Music Map. Edytuj źródło w Music Map.\nmusic-map-managed-export:v1:00000000-0000-0000-0000-000000000000",
            '00000000-0000-0000-0000-000000000000',
        ));
    }

    public function reconcile(ManagedAccessContext $access, ManagedPlaylistReference $reference, ManagedPlaylistMetadata $metadata, array $catalogItems): ManagedPlaylistReconciliationResult|ManagedProviderFailure
    {
        $this->calls[] = 'reconcile';
        if ($this->events !== null) {
            $this->events->entries[] = 'reconcile';
        }
        $this->lastMetadata = $metadata;
        if ($this->reconcileFailure !== null) {
            return $this->reconcileFailure;
        }

        $items = array_map(fn (string $catalog, int $position): ManagedPlaylistItem => $this->streamingProvider === StreamingProvider::Spotify
            ? new ManagedPlaylistItem('target-one', $catalog, $position)
            : new ManagedPlaylistItem($catalog, 'https://www.youtube.com/watch?v='.$catalog, $position, 'occurrence-'.$position), array_values($catalogItems), array_keys(array_values($catalogItems)));

        return ManagedPlaylistReconciliationResult::exact(new ManagedPlaylistSnapshot(
            $reference,
            $metadata,
            $this->streamingProvider === StreamingProvider::Spotify ? 'private' : 'unlisted',
            $items,
        ));
    }

    private function snapshot(ManagedPlaylistReference $reference, ManagedPlaylistMetadata $metadata): ManagedPlaylistSnapshot
    {
        return new ManagedPlaylistSnapshot($reference, $metadata, 'private', [
            new ManagedPlaylistItem('target-one', 'spotify:track:one', 0),
        ]);
    }

    private function reference(string $id, string $owner): ManagedPlaylistReference
    {
        return new ManagedPlaylistReference(
            $this->streamingProvider,
            $id,
            $this->streamingProvider === StreamingProvider::Spotify
                ? 'https://open.spotify.com/playlist/'.$id
                : 'https://www.youtube.com/playlist?list='.$id,
            $owner,
        );
    }
}

final class ManagedExportEventLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class RecordingYouTubeAdmission implements AdmitYouTubeWrite
{
    /** @var list<string> */
    public array $operationIds = [];

    /** @var list<YouTubeWriteOperationType> */
    public array $operationTypes = [];

    public function __construct(
        private ManagedExportEventLog $events,
        private bool $limitReached = false,
    ) {}

    public function admit(YouTubeWriteOperationType $operationType, string $operationId): YouTubeWriteAdmissionResult
    {
        $this->events->entries[] = 'admission';
        $this->operationIds[] = $operationId;
        $this->operationTypes[] = $operationType;
        $day = CarbonImmutable::now('America/Los_Angeles')->format('Y-m-d');
        $reset = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'America/Los_Angeles')->addDay();

        if ($this->limitReached) {
            return YouTubeWriteAdmissionResult::limitReached($day, $reset);
        }

        return count($this->operationIds) === 1
            ? YouTubeWriteAdmissionResult::admittedNew(1, $day, $reset)
            : YouTubeWriteAdmissionResult::admittedExisting(1, $day, $reset);
    }
}
