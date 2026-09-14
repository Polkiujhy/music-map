<?php

namespace Tests\Feature\Exports;

use App\Actions\Exports\RunExportOperation;
use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\Data\ManagedExportAccess;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\StreamingAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ExecuteExportOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_spotify_create_and_fresh_update_converge_on_one_persisted_target_id(): void
    {
        $this->assertSpotifyCreateAndFreshUpdate(ExportDestinationType::Managed);
    }

    public function test_linked_spotify_create_and_fresh_update_converge_on_one_persisted_target_id(): void
    {
        $this->assertSpotifyCreateAndFreshUpdate(ExportDestinationType::Linked);
    }

    public function test_managed_youtube_create_and_fresh_update_converge_on_one_persisted_target_id(): void
    {
        $this->assertYouTubeCreateAndFreshUpdate(ExportDestinationType::Managed);
    }

    public function test_linked_youtube_create_and_fresh_update_converge_on_one_persisted_target_id(): void
    {
        $this->assertYouTubeCreateAndFreshUpdate(ExportDestinationType::Linked);
    }

    private function assertSpotifyCreateAndFreshUpdate(ExportDestinationType $destinationType): void
    {
        $account = $destinationType === ExportDestinationType::Linked
            ? StreamingAccount::factory()->spotify()->create([
                'provider_account_id' => 'linked-spotify',
                'scopes' => StreamingProvider::Spotify->requiredScopes(),
            ])
            : null;
        $targetAccountId = $account?->provider_account_id ?? 'managed-spotify';
        $state = $this->fakeSpotify($targetAccountId);
        $this->app->instance(ManagedExportAccessBroker::class, new OperationManagedBrokerFake);
        $this->app->instance(WithStreamingAccess::class, new OperationStreamingAccessFake);
        $admission = new OperationAdmissionFake;
        $this->app->instance(AdmitYouTubeWrite::class, $admission);

        $first = $this->operation(
            StreamingProvider::Spotify,
            $destinationType,
            account: $account,
        );
        app(RunExportOperation::class)->handle($first->operation_id);

        $first->refresh();
        $this->assertSame(ExportOperationStatus::Transferred, $first->status);
        $this->assertNotNull($first->playlist_export_link_id);
        $targetId = $first->playlistExportLink->targetPlaylist->source_playlist_id;
        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUV', $targetId);
        $this->assertSame(['spotify:track:ABCDEFGHIJKLMNOPQRSTUV'], $state->items);

        $second = $this->operation(
            StreamingProvider::Spotify,
            $destinationType,
            $first->sourcePlaylist,
            'spotify:track:ZYXWVUTSRQPONMLKJIHGFE',
            'Updated frozen name',
            $account,
        );
        app(RunExportOperation::class)->handle($second->operation_id);

        $second->refresh();
        $this->assertSame(ExportOperationStatus::Transferred, $second->status);
        $this->assertSame($first->playlist_export_link_id, $second->playlist_export_link_id);
        $this->assertSame($targetId, $second->playlistExportLink->targetPlaylist->source_playlist_id);
        $this->assertSame(['spotify:track:ZYXWVUTSRQPONMLKJIHGFE'], $state->items);
        $this->assertDatabaseCount('playlist_export_links', 1);
        $this->assertDatabaseCount('playlists', 2);
        $this->assertSame(0, $admission->calls);
    }

    private function assertYouTubeCreateAndFreshUpdate(ExportDestinationType $destinationType): void
    {
        $account = $destinationType === ExportDestinationType::Linked
            ? StreamingAccount::factory()->youtube()->create([
                'provider_account_id' => 'linked-channel',
                'scopes' => StreamingProvider::YouTube->requiredScopes(),
            ])
            : null;
        $targetAccountId = $account?->provider_account_id ?? 'managed-channel';
        $state = $this->fakeYouTube($targetAccountId);
        $this->app->instance(ManagedExportAccessBroker::class, new OperationManagedBrokerFake);
        $this->app->instance(WithStreamingAccess::class, new OperationStreamingAccessFake);
        $admission = new OperationAdmissionFake;
        $this->app->instance(AdmitYouTubeWrite::class, $admission);

        $first = $this->operation(
            StreamingProvider::YouTube,
            $destinationType,
            item: 'abcdefghijk',
            account: $account,
        );
        app(RunExportOperation::class)->handle($first->operation_id);

        $first->refresh();
        $this->assertSame(ExportOperationStatus::Transferred, $first->status);
        $this->assertNotNull($first->playlist_export_link_id);
        $targetId = $first->playlistExportLink->targetPlaylist->source_playlist_id;
        $this->assertSame('PLabcdefghijklm', $targetId);
        $this->assertSame(['abcdefghijk'], $state->items);

        $second = $this->operation(
            StreamingProvider::YouTube,
            $destinationType,
            $first->sourcePlaylist,
            'zyxwvutsrqp',
            'Updated frozen name',
            $account,
        );
        app(RunExportOperation::class)->handle($second->operation_id);

        $second->refresh();
        $this->assertSame(ExportOperationStatus::Transferred, $second->status);
        $this->assertSame($first->playlist_export_link_id, $second->playlist_export_link_id);
        $this->assertSame($targetId, $second->playlistExportLink->targetPlaylist->source_playlist_id);
        $this->assertSame(['zyxwvutsrqp'], $state->items);
        $this->assertDatabaseCount('playlist_export_links', 1);
        $this->assertDatabaseCount('playlists', 2);
        $this->assertSame([$first->operation_id, $second->operation_id], $admission->operationIds);
    }

    public function test_linked_replacement_rotation_is_snapshotted_before_the_first_mutation(): void
    {
        $this->fakeSpotify();
        $account = StreamingAccount::factory()->spotify()->create([
            'provider_account_id' => 'linked-spotify',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
            'credential_version' => 1,
        ]);
        $this->app->instance(WithStreamingAccess::class, new OperationStreamingAccessFake(
            function (StreamingAccount $snapshot): void {
                $snapshot->forceFill(['credential_version' => 2])->save();
            },
        ));
        $operation = $this->operation(
            StreamingProvider::Spotify,
            ExportDestinationType::Linked,
            account: $account,
        );

        app(RunExportOperation::class)->handle($operation->operation_id);

        $this->assertSame(ExportOperationStatus::Transferred, $operation->fresh()->status);
        $this->assertSame(2, $account->fresh()->credential_version);
    }

    public function test_spotify_retry_converges_after_manual_metadata_drift_and_partial_replacement(): void
    {
        $state = $this->fakeSpotify();
        $this->app->instance(ManagedExportAccessBroker::class, new OperationManagedBrokerFake);
        $first = $this->operation(StreamingProvider::Spotify, ExportDestinationType::Managed);
        app(RunExportOperation::class)->handle($first->operation_id);
        $state->description = 'Manually edited description';

        $second = $this->operation(
            StreamingProvider::Spotify,
            ExportDestinationType::Managed,
            $first->sourcePlaylist,
            'spotify:track:ZYXWVUTSRQPONMLKJIHGFE',
            'Updated frozen name',
        );
        $state->failNextItemMutation = true;

        try {
            app(RunExportOperation::class)->handle($second->operation_id);
            $this->fail('A temporary item failure must request a safe retry.');
        } catch (RuntimeException) {
            $this->assertSame(ExportOperationStatus::Incomplete, $second->fresh()->status);
        }

        app(RunExportOperation::class)->handle($second->operation_id);

        $this->assertSame(ExportOperationStatus::Transferred, $second->fresh()->status);
        $this->assertSame(['spotify:track:ZYXWVUTSRQPONMLKJIHGFE'], $state->items);
        $this->assertStringContainsString($first->operation_id, $state->description);
    }

    public function test_youtube_retry_converges_after_manual_metadata_drift_and_partial_replacement(): void
    {
        $state = $this->fakeYouTube('managed-channel');
        $this->app->instance(ManagedExportAccessBroker::class, new OperationManagedBrokerFake);
        $this->app->instance(AdmitYouTubeWrite::class, new OperationAdmissionFake);
        $first = $this->operation(StreamingProvider::YouTube, ExportDestinationType::Managed);
        app(RunExportOperation::class)->handle($first->operation_id);
        $state->description = 'Manually edited description';

        $second = $this->operation(
            StreamingProvider::YouTube,
            ExportDestinationType::Managed,
            $first->sourcePlaylist,
            'zyxwvutsrqp',
            'Updated frozen name',
        );
        $state->failNextItemMutation = true;

        try {
            app(RunExportOperation::class)->handle($second->operation_id);
            $this->fail('A temporary item failure must request a safe retry.');
        } catch (RuntimeException) {
            $this->assertSame(ExportOperationStatus::Incomplete, $second->fresh()->status);
        }

        app(RunExportOperation::class)->handle($second->operation_id);

        $this->assertSame(ExportOperationStatus::Transferred, $second->fresh()->status);
        $this->assertSame(['zyxwvutsrqp'], $state->items);
        $this->assertStringContainsString($first->operation_id, $state->description);
    }

    public function test_unlink_between_youtube_mutations_blocks_every_later_write_and_leaves_incomplete(): void
    {
        $account = StreamingAccount::factory()->youtube()->create([
            'provider_account_id' => 'linked-channel',
            'scopes' => StreamingProvider::YouTube->requiredScopes(),
        ]);
        $this->app->instance(WithStreamingAccess::class, new OperationStreamingAccessFake);
        $this->app->instance(AdmitYouTubeWrite::class, new OperationAdmissionFake);
        $mutations = 0;
        Http::fake(function (Request $request) use ($account, &$mutations) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlists?')) {
                $mutations++;
                $account->delete();

                return Http::response(['id' => 'PLabcdefghijklm', 'etag' => 'created'], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlistItems')) {
                return Http::response(['items' => [], 'pageInfo' => ['totalResults' => 0]], 200);
            }

            $mutations++;

            return Http::response([], 500);
        });
        $operation = $this->operation(
            StreamingProvider::YouTube,
            ExportDestinationType::Linked,
            item: 'abcdefghijk',
            account: $account,
        );

        app(RunExportOperation::class)->handle($operation->operation_id);

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Incomplete, $operation->status);
        $this->assertSame(ExportOperationFailure::StaleCredential, $operation->failure_code);
        $this->assertSame(1, $mutations);
        $this->assertNotNull($operation->provider_mutation_started_at);
        $this->assertNotNull($operation->playlist_export_link_id);
    }

    public function test_persisted_target_404_retires_the_link_and_terminal_replay_is_a_noop(): void
    {
        $state = $this->fakeSpotify();
        $this->app->instance(ManagedExportAccessBroker::class, new OperationManagedBrokerFake);
        $first = $this->operation(StreamingProvider::Spotify, ExportDestinationType::Managed);
        app(RunExportOperation::class)->handle($first->operation_id);

        $second = $this->operation(
            StreamingProvider::Spotify,
            ExportDestinationType::Managed,
            $first->sourcePlaylist,
        );
        Http::fake(static fn () => null);
        $state->targetDeleted = true;
        app(RunExportOperation::class)->handle($second->operation_id);

        $second->refresh();
        $this->assertSame(ExportOperationStatus::Failed, $second->status);
        $this->assertSame(ExportOperationFailure::TargetDeleted, $second->failure_code);
        $this->assertNull($second->active_key);
        $this->assertNotNull($second->playlistExportLink->fresh()->retired_at);
        $this->assertNull($second->playlistExportLink->fresh()->active_key);
        Http::assertSentCount(1);

        app(RunExportOperation::class)->handle($second->operation_id);
        Http::assertSentCount(1);
    }

    public function test_stale_processing_is_classified_by_checkpoint_and_does_not_overwrite_late_success(): void
    {
        Queue::fake();
        $before = $this->operation(StreamingProvider::Spotify, ExportDestinationType::Managed);
        $before->forceFill([
            'status' => ExportOperationStatus::Processing,
            'attempt_count' => 1,
            'updated_at' => now()->subMinutes(10),
        ])->save();
        $before->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

        $after = $this->operation(StreamingProvider::YouTube, ExportDestinationType::Managed);
        $after->forceFill([
            'status' => ExportOperationStatus::Processing,
            'attempt_count' => 1,
            'provider_mutation_started_at' => now()->subMinutes(11),
        ])->save();
        $after->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

        $lateSuccess = $this->operation(StreamingProvider::Spotify, ExportDestinationType::Managed);
        $lateSuccess->forceFill([
            'status' => ExportOperationStatus::Transferred,
            'active_key' => null,
            'completed_at' => now(),
        ])->save();
        $lateSuccess->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

        Artisan::call('exports:reconcile-stale');

        $this->assertSame(ExportOperationStatus::Failed, $before->fresh()->status);
        $this->assertSame(ExportOperationStatus::Incomplete, $after->fresh()->status);
        $this->assertSame(ExportOperationStatus::Transferred, $lateSuccess->fresh()->status);
        Queue::assertPushed(ExecuteExportOperation::class, 2);
        Queue::assertPushed(ExecuteExportOperation::class, fn ($job): bool => $job->operationId === $before->operation_id);
        Queue::assertPushed(ExecuteExportOperation::class, fn ($job): bool => $job->operationId === $after->operation_id);
    }

    public function test_unexpected_worker_exception_is_reported_without_its_secret_message(): void
    {
        $secret = 'managed-token-that-must-not-be-logged';
        $operation = $this->operation(StreamingProvider::YouTube, ExportDestinationType::Managed);
        $this->app->instance(ManagedExportAccessBroker::class, new OperationManagedBrokerFake);
        $this->app->instance(AdmitYouTubeWrite::class, new ThrowingOperationAdmissionFake($secret));
        Log::spy();

        try {
            app(RunExportOperation::class)->handle($operation->operation_id);
            $this->fail('An unexpected failure must request a safe retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString($secret, $exception->getMessage());
        }

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Failed, $operation->status);
        $this->assertSame(ExportOperationFailure::TemporaryFailure, $operation->failure_code);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($operation, $secret): bool {
                $this->assertSame('export_operation_unexpected_exception', $message);
                $this->assertSame([
                    'operation_id',
                    'exception_class',
                    'exception_location',
                ], array_keys($context));
                $this->assertSame($operation->operation_id, $context['operation_id']);
                $this->assertSame(RuntimeException::class, $context['exception_class']);
                $this->assertStringNotContainsString($secret, json_encode($context, JSON_THROW_ON_ERROR));

                return true;
            });
    }

    /** @return object{items: list<string>, name: string, description: string, marker: string, targetDeleted: bool, failNextItemMutation: bool} */
    private function fakeSpotify(string $targetAccountId = 'managed-spotify'): object
    {
        $state = (object) [
            'items' => [],
            'name' => '',
            'description' => '',
            'marker' => '',
            'targetDeleted' => false,
            'failNextItemMutation' => false,
        ];
        Http::fake(function (Request $request) use ($state, $targetAccountId) {
            if ($state->targetDeleted) {
                return Http::response([
                    'error' => ['errors' => [['reason' => 'playlistNotFound']]],
                ], 404);
            }

            $data = $request->data();
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/me/playlists')) {
                $state->name = $data['name'];
                $state->description = $data['description'];

                return Http::response(['id' => 'ABCDEFGHIJKLMNOPQRSTUV', 'snapshot_id' => 'create-revision'], 201);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlists/ABCDEFGHIJKLMNOPQRSTUV/items')) {
                return Http::response([
                    'items' => array_map(fn (string $uri): array => ['item' => ['uri' => $uri]], $state->items),
                    'total' => count($state->items),
                    'next' => null,
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlists/ABCDEFGHIJKLMNOPQRSTUV')) {
                return Http::response([
                    'id' => 'ABCDEFGHIJKLMNOPQRSTUV',
                    'owner' => ['id' => $targetAccountId],
                    'description' => $state->description,
                    'public' => false,
                    'collaborative' => false,
                    'snapshot_id' => 'inspect-revision',
                ], 200);
            }
            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/playlists/ABCDEFGHIJKLMNOPQRSTUV')) {
                $state->name = $data['name'];
                $state->description = $data['description'];

                return Http::response([], 200);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/playlists/ABCDEFGHIJKLMNOPQRSTUV/items')) {
                if ($state->failNextItemMutation) {
                    $state->failNextItemMutation = false;

                    return Http::response([], 503);
                }
                $state->items = $data['uris'];

                return Http::response(['snapshot_id' => 'replace-revision'], 200);
            }

            return Http::response([], 500);
        });

        return $state;
    }

    /** @return object{items: list<string>, name: string, description: string, occurrence: int, failNextItemMutation: bool} */
    private function fakeYouTube(string $targetAccountId): object
    {
        $state = (object) [
            'items' => [],
            'name' => '',
            'description' => '',
            'occurrence' => 0,
            'failNextItemMutation' => false,
        ];
        Http::fake(function (Request $request) use ($state, $targetAccountId) {
            $data = $request->data();
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlists?')) {
                $state->name = $data['snippet']['title'];
                $state->description = $data['snippet']['description'];

                return Http::response(['id' => 'PLabcdefghijklm', 'etag' => 'create-revision'], 201);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlists?')) {
                return Http::response(['items' => [[
                    'id' => 'PLabcdefghijklm',
                    'etag' => 'inspect-revision',
                    'snippet' => [
                        'channelId' => $targetAccountId,
                        'description' => $state->description,
                    ],
                    'status' => ['privacyStatus' => 'unlisted'],
                ]]], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlistItems')) {
                return Http::response([
                    'items' => array_map(
                        fn (string $videoId, int $position): array => [
                            'id' => 'occurrence-'.$position.'-'.$videoId,
                            'snippet' => [
                                'position' => $position,
                                'resourceId' => ['videoId' => $videoId],
                            ],
                        ],
                        $state->items,
                        array_keys($state->items),
                    ),
                    'pageInfo' => ['totalResults' => count($state->items)],
                ], 200);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/playlists?')) {
                $state->name = $data['snippet']['title'];
                $state->description = $data['snippet']['description'];

                return Http::response(['etag' => 'update-revision'], 200);
            }
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/playlistItems?')) {
                if ($state->failNextItemMutation) {
                    $state->failNextItemMutation = false;

                    return Http::response([], 503);
                }
                $state->items = [];

                return Http::response([], 204);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlistItems?')) {
                if ($state->failNextItemMutation) {
                    $state->failNextItemMutation = false;

                    return Http::response([], 503);
                }
                $state->items[] = $data['snippet']['resourceId']['videoId'];
                $state->occurrence++;

                return Http::response(['id' => 'occurrence-'.$state->occurrence], 201);
            }

            return Http::response([], 500);
        });

        return $state;
    }

    private function operation(
        StreamingProvider $provider,
        ExportDestinationType $type,
        ?Playlist $source = null,
        ?string $item = null,
        string $name = 'Frozen export',
        ?StreamingAccount $account = null,
    ): ExportOperation {
        if ($source === null) {
            $source = $account instanceof StreamingAccount
                ? Playlist::factory()->for($account->user)->create()
                : Playlist::factory()->create();
        }
        $accountId = $account?->provider_account_id ?? match ($provider) {
            StreamingProvider::Spotify => 'managed-spotify',
            StreamingProvider::YouTube => 'managed-channel',
        };
        $item ??= $provider === StreamingProvider::Spotify
            ? 'spotify:track:ABCDEFGHIJKLMNOPQRSTUV'
            : 'abcdefghijk';
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'streaming_account_id' => $account?->id,
            'target_provider' => $provider,
            'destination_type' => $type,
            'target_account_id' => $accountId,
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'match_status' => ExportMatchStatus::Matched,
            'decision' => ExportReviewDecision::Keep,
            'target_catalog_id' => $provider === StreamingProvider::Spotify ? substr($item, -22) : $item,
            'target_catalog_uri' => $provider === StreamingProvider::Spotify ? $item : 'youtube:video:'.$item,
        ]);

        return ExportOperation::factory()->for($review)->create([
            'streaming_account_id' => $account?->id,
            'target_provider' => $provider,
            'destination_type' => $type,
            'target_account_id' => $accountId,
            'playlist_name' => $name,
        ]);
    }
}

final readonly class OperationManagedBrokerFake implements ManagedExportAccessBroker
{
    public function acquire(string $provider, string $operationId): ManagedExportAccess
    {
        return new ManagedExportAccess($provider, 'managed-access', new DateTimeImmutable('+10 minutes'), $operationId);
    }
}

final readonly class OperationStreamingAccessFake implements WithStreamingAccess
{
    public function __construct(private ?Closure $rotate = null) {}

    public function handle(
        User $owner,
        StreamingAccount $account,
        array $requiredScopes,
        Closure $callback,
    ): StreamingAccessResult {
        if ($this->rotate instanceof Closure) {
            ($this->rotate)($account);
        }
        $callback(new StreamingAccessContext($account->provider, $account->provider_account_id, 'linked-access'));

        return StreamingAccessResult::success();
    }
}

final class OperationAdmissionFake implements AdmitYouTubeWrite
{
    public int $calls = 0;

    /** @var list<string> */
    public array $operationIds = [];

    public function admit(YouTubeWriteOperationType $operationType, string $operationId): YouTubeWriteAdmissionResult
    {
        $this->calls++;
        $this->operationIds[] = $operationId;
        $day = CarbonImmutable::now(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        $reset = new DateTimeImmutable($day.' 00:00:00', new DateTimeZone('America/Los_Angeles'));

        return YouTubeWriteAdmissionResult::admittedNew($this->calls, $day, $reset->modify('+1 day'));
    }
}

final readonly class ThrowingOperationAdmissionFake implements AdmitYouTubeWrite
{
    public function __construct(private string $secret) {}

    public function admit(YouTubeWriteOperationType $operationType, string $operationId): YouTubeWriteAdmissionResult
    {
        throw new RuntimeException($this->secret);
    }
}
