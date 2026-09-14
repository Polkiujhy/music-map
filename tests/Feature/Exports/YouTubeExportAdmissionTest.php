<?php

namespace Tests\Feature\Exports;

use App\Actions\Exports\RunExportOperation;
use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\Data\ManagedExportAccess;
use App\Integrations\PlaylistExport\Providers\YouTubePlaylistWriter;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExportLink;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class YouTubeExportAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_limit_denial_and_admission_failure_cause_zero_provider_mutations(): void
    {
        $this->app->instance(ManagedExportAccessBroker::class, new AdmissionManagedBrokerFake);

        foreach ([YouTubeWriteAdmissionStatus::LimitReached, null] as $outcome) {
            Http::fake();
            $operation = $this->operation(StreamingProvider::YouTube);
            $this->app->instance(AdmitYouTubeWrite::class, new AdmissionFake($outcome));

            try {
                app(RunExportOperation::class)->handle($operation->operation_id);
            } catch (RuntimeException) {
                // Unavailable admission is retryable; the persisted state is asserted below.
            }

            $operation->refresh();
            $this->assertSame(ExportOperationStatus::Failed, $operation->status);
            $this->assertSame(
                $outcome === YouTubeWriteAdmissionStatus::LimitReached
                    ? ExportOperationFailure::AdmissionLimit
                    : ExportOperationFailure::AdmissionUnavailable,
                $operation->failure_code,
            );
            $this->assertNull($operation->provider_mutation_started_at);
            Http::assertNothingSent();
        }
    }

    public function test_ambiguous_create_retry_reuses_admission_and_only_scans_for_the_same_operation(): void
    {
        $this->app->instance(ManagedExportAccessBroker::class, new AdmissionManagedBrokerFake);
        $admission = new AdmissionFake(YouTubeWriteAdmissionStatus::AdmittedNew);
        $this->app->instance(AdmitYouTubeWrite::class, $admission);
        $operation = $this->operation(StreamingProvider::YouTube);
        $createCalls = 0;
        $mutationCalls = 0;
        $items = [];
        Http::fake(function (Request $request) use ($operation, &$createCalls, &$mutationCalls, &$items) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlists?')) {
                $createCalls++;
                $mutationCalls++;
                throw new ConnectionException('create response was lost');
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlists?')) {
                return Http::response([
                    'items' => [[
                        'id' => 'PLabcdefghijklm',
                        'snippet' => [
                            'channelId' => 'managed-channel',
                            'description' => "Frozen description\n\n[music-map-export:{$operation->operation_id}]",
                        ],
                        'status' => ['privacyStatus' => 'unlisted'],
                    ]],
                    'pageInfo' => ['totalResults' => 1],
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlistItems')) {
                return Http::response([
                    'items' => array_map(
                        fn (array $item, int $position): array => [
                            'id' => $item['id'],
                            'snippet' => [
                                'position' => $position,
                                'resourceId' => ['videoId' => $item['videoId']],
                            ],
                        ],
                        $items,
                        array_keys($items),
                    ),
                    'pageInfo' => ['totalResults' => count($items)],
                ], 200);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/playlists?')) {
                $mutationCalls++;

                return Http::response(['etag' => 'updated'], 200);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlistItems?')) {
                $mutationCalls++;
                $items[] = ['id' => 'occurrence-1', 'videoId' => 'abcdefghijk'];

                return Http::response(['id' => 'occurrence-1'], 201);
            }

            return Http::response([], 500);
        });

        try {
            app(RunExportOperation::class)->handle($operation->operation_id);
            $this->fail('An ambiguous create must request a retry.');
        } catch (RuntimeException) {
            $this->assertSame(ExportOperationFailure::AmbiguousCreate, $operation->fresh()->failure_code);
        }

        app(RunExportOperation::class)->handle($operation->operation_id);

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Transferred, $operation->status);
        $this->assertSame(1, $createCalls);
        $this->assertSame(3, $mutationCalls);
        $this->assertSame([$operation->operation_id, $operation->operation_id], $admission->operationIds);
        $this->assertSame('PLabcdefghijklm', $operation->playlistExportLink->targetPlaylist->source_playlist_id);
    }

    public function test_spotify_never_calls_youtube_admission(): void
    {
        $this->app->instance(ManagedExportAccessBroker::class, new AdmissionManagedBrokerFake);
        $admission = new AdmissionFake(YouTubeWriteAdmissionStatus::AdmittedNew);
        $this->app->instance(AdmitYouTubeWrite::class, $admission);
        $operation = $this->operation(StreamingProvider::Spotify);
        Http::fake([
            '*api.spotify.com/v1/me/playlists' => Http::response([
                'id' => 'ABCDEFGHIJKLMNOPQRSTUV',
                'snapshot_id' => 'created',
            ], 201),
            '*api.spotify.com/v1/playlists/ABCDEFGHIJKLMNOPQRSTUV/items' => Http::response([
                'snapshot_id' => 'updated',
            ], 200),
            '*api.spotify.com/v1/playlists/ABCDEFGHIJKLMNOPQRSTUV' => Http::response([], 200),
        ]);

        app(RunExportOperation::class)->handle($operation->operation_id);

        $this->assertSame(ExportOperationStatus::Transferred, $operation->fresh()->status);
        $this->assertSame(0, $admission->calls);
    }

    public function test_unsupported_youtube_duplicates_fail_before_any_provider_mutation(): void
    {
        $this->app->instance(ManagedExportAccessBroker::class, new AdmissionManagedBrokerFake);
        $this->app->instance(AdmitYouTubeWrite::class, new AdmissionFake(YouTubeWriteAdmissionStatus::AdmittedNew));
        $this->app->instance(YouTubePlaylistWriter::class, new YouTubePlaylistWriter(false));
        $operation = $this->operation(StreamingProvider::YouTube);
        ExportReviewItem::factory()->for($operation->exportReview)->create([
            'position' => 1,
            'match_status' => ExportMatchStatus::Matched,
            'decision' => ExportReviewDecision::Keep,
            'target_catalog_id' => 'abcdefghijk',
            'target_catalog_uri' => 'youtube:video:abcdefghijk',
        ]);
        $target = Playlist::factory()->for($operation->user)->create([
            'role' => PlaylistRole::ExportTarget,
            'source_provider' => StreamingProvider::YouTube,
            'source_playlist_id' => 'PLabcdefghijklm',
            'source_account_id' => $operation->target_account_id,
        ]);
        $link = PlaylistExportLink::factory()->create([
            'user_id' => $operation->user_id,
            'source_playlist_id' => $operation->source_playlist_id,
            'target_playlist_id' => $target->id,
            'provider' => StreamingProvider::YouTube,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => $operation->target_account_id,
        ]);
        $operation->playlistExportLink()->associate($link);
        $operation->save();
        Http::fakeSequence()
            ->push(['items' => [[
                'id' => 'PLabcdefghijklm',
                'etag' => 'inspect-revision',
                'snippet' => [
                    'channelId' => 'managed-channel',
                    'description' => 'Provider metadata may have changed',
                ],
                'status' => ['privacyStatus' => 'unlisted'],
            ]]])
            ->push(['items' => [], 'pageInfo' => ['totalResults' => 0]]);

        app(RunExportOperation::class)->handle($operation->operation_id);

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Failed, $operation->status);
        $this->assertSame(ExportOperationFailure::UnsupportedDuplicate, $operation->failure_code);
        $this->assertNull($operation->active_key);
        $this->assertNull($operation->provider_mutation_started_at);
        $this->assertNotNull($link->fresh()->active_key);
        $this->assertNull($link->fresh()->retired_at);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    private function operation(StreamingProvider $provider): ExportOperation
    {
        $review = ExportReview::factory()->create([
            'target_provider' => $provider,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => $provider === StreamingProvider::Spotify ? 'managed-spotify' : 'managed-channel',
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'match_status' => ExportMatchStatus::Matched,
            'decision' => ExportReviewDecision::Keep,
            'target_catalog_id' => $provider === StreamingProvider::Spotify
                ? 'ABCDEFGHIJKLMNOPQRSTUV'
                : 'abcdefghijk',
            'target_catalog_uri' => $provider === StreamingProvider::Spotify
                ? 'spotify:track:ABCDEFGHIJKLMNOPQRSTUV'
                : 'youtube:video:abcdefghijk',
        ]);

        return ExportOperation::factory()->for($review)->create([
            'target_provider' => $provider,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => $provider === StreamingProvider::Spotify ? 'managed-spotify' : 'managed-channel',
            'playlist_name' => 'Frozen export',
            'playlist_description' => 'Frozen description',
        ]);
    }
}

final readonly class AdmissionManagedBrokerFake implements ManagedExportAccessBroker
{
    public function acquire(string $provider, string $operationId): ManagedExportAccess
    {
        return new ManagedExportAccess($provider, 'managed-access', new DateTimeImmutable('+10 minutes'), $operationId);
    }
}

final class AdmissionFake implements AdmitYouTubeWrite
{
    public int $calls = 0;

    /** @var list<string> */
    public array $operationIds = [];

    public function __construct(private readonly ?YouTubeWriteAdmissionStatus $outcome) {}

    public function admit(YouTubeWriteOperationType $operationType, string $operationId): YouTubeWriteAdmissionResult
    {
        $this->calls++;
        $this->operationIds[] = $operationId;
        if ($this->outcome === null) {
            throw new YouTubeWriteAdmissionUnavailable;
        }

        $day = CarbonImmutable::now(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        $reset = new DateTimeImmutable($day.' 00:00:00', new DateTimeZone('America/Los_Angeles'));

        return match ($this->outcome) {
            YouTubeWriteAdmissionStatus::LimitReached => YouTubeWriteAdmissionResult::limitReached($day, $reset->modify('+1 day')),
            YouTubeWriteAdmissionStatus::AdmittedExisting => YouTubeWriteAdmissionResult::admittedExisting(1, $day, $reset->modify('+1 day')),
            YouTubeWriteAdmissionStatus::AdmittedNew => YouTubeWriteAdmissionResult::admittedNew(1, $day, $reset->modify('+1 day')),
        };
    }
}
