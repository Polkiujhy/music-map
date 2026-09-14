<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\ManagedAccountExport\ReconcileManagedExport;
use App\Actions\ManagedAccountExport\RunManagedExport;
use App\Enums\ExportDestinationType;
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
use App\Integrations\YouTubeWriteAdmission\Actions\ReserveYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\User;
use App\Models\YouTubeWriteAdmission;
use App\Models\YouTubeWriteQuotaState;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ManagedExportPostgresTest extends TestCase
{
    public function test_concurrent_workers_grant_one_claim_and_one_managed_target_mutation_right(): void
    {
        $this->requirePostgresConcurrency();
        $operation = $this->managedOperation(StreamingProvider::Spotify);

        try {
            $outcomes = $this->runConcurrently(2, 'worker-claim', function () use ($operation): string {
                $gateway = new PostgresManagedPlaylistGateway(StreamingProvider::Spotify);
                $action = new RunManagedExport(
                    app(AdmitYouTubeWrite::class),
                    new PostgresManagedAccess,
                    new ManagedPlaylistGatewayRegistry($gateway),
                    app(ReconcileManagedExport::class),
                );
                $action->handle($operation->id);

                return 'delivered';
            });

            $this->assertSame(['delivered', 'delivered'], $outcomes);
            DB::purge();
            $operation->refresh();
            $this->assertSame(ExportOperationStatus::Succeeded, $operation->status);
            $this->assertSame(1, $operation->attempt_generation);
            $this->assertSame(1, $operation->automatic_claim_count);
            $this->assertSame(1, $operation->playlistExport->targetAttempts()->whereNotNull('provider_playlist_id')->count());
            $this->assertSame(1, Playlist::query()
                ->where('user_id', $operation->user_id)
                ->where('source_provider', StreamingProvider::Spotify->value)
                ->where('source_playlist_id', 'postgres-managed-target')
                ->count());
        } finally {
            $this->deleteOperationGraph($operation);
        }
    }

    public function test_concurrent_and_repeated_youtube_admission_uses_one_reservation_for_stable_operation_id(): void
    {
        $this->requirePostgresConcurrency();
        $operation = $this->managedOperation(StreamingProvider::YouTube);
        config(['services.youtube_write_admission.daily_limit' => 10]);
        DB::table('youtube_write_quota_states')->updateOrInsert(
            ['singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY],
            ['quota_day' => null, 'admitted_count' => 0, 'daily_limit' => 10],
        );

        try {
            $outcomes = $this->runConcurrently(2, 'stable-f02', fn (): string => (new ReserveYouTubeWrite)
                ->admit(YouTubeWriteOperationType::ManagedExport, $operation->id)
                ->status
                ->value);
            sort($outcomes);

            $this->assertSame(['admitted-existing', 'admitted-new'], $outcomes);
            DB::purge();
            $reservation = YouTubeWriteAdmission::query()
                ->where('operation_type', YouTubeWriteOperationType::ManagedExport->value)
                ->where('operation_id', $operation->id)
                ->firstOrFail();
            $repeated = (new ReserveYouTubeWrite)->admit(YouTubeWriteOperationType::ManagedExport, $operation->id);
            $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting, $repeated->status);
            $this->assertSame($reservation->id, $repeated->reservationId);
            $this->assertSame(1, YouTubeWriteAdmission::query()->where('operation_id', $operation->id)->count());
            $this->assertSame(1, YouTubeWriteQuotaState::query()->whereKey(YouTubeWriteQuotaState::GLOBAL_KEY)->value('admitted_count'));
        } finally {
            DB::purge();
            YouTubeWriteAdmission::query()->where('operation_id', $operation->id)->delete();
            DB::table('youtube_write_quota_states')
                ->where('singleton_key', YouTubeWriteQuotaState::GLOBAL_KEY)
                ->update(['quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null]);
            $this->deleteOperationGraph($operation);
        }
    }

    public function test_durable_claim_budget_uses_compare_and_swap_generation_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This managed-export race test requires PostgreSQL.');
        }

        $operation = ExportOperation::factory()->create([
            'status' => ExportOperationStatus::Processing,
            'attempt_generation' => 4,
            'automatic_claim_count' => 3,
            'heartbeat_at' => now()->subSeconds(511),
        ]);

        // A new delivery cannot reset the durable generation budget.
        app(RunManagedExport::class)->handle($operation->id);

        $operation->refresh();
        $this->assertSame(4, $operation->attempt_generation);
        $this->assertSame(3, $operation->automatic_claim_count);
        $this->assertContains($operation->status, [
            ExportOperationStatus::Failed,
            ExportOperationStatus::PartialFailed,
            ExportOperationStatus::ManualRecoveryRequired,
        ]);
    }

    public function test_concurrent_inserts_create_one_canonical_copy_and_one_operation_per_review(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This managed-export race test requires PostgreSQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This managed-export race test requires the pcntl extension.');
        }

        $user = User::factory()->create();
        $source = Playlist::factory()->for($user)->create();
        $review = ExportReview::factory()->for($user)->for($source)->create([
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'postgres-race-owner-'.Str::uuid(),
            'status' => ExportReviewStatus::Confirmed,
        ]);
        $now = now();
        $exportRows = array_map(fn (int $id): array => [
            'id' => $id,
            'source_playlist_id' => $source->id,
            'target_provider' => 'spotify',
            'destination_type' => 'managed',
            'target_account_id' => $review->target_account_id,
            'target_generation' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], [900000001, 900000002]);

        try {
            $exportOutcomes = $this->runConcurrentInserts('playlist_exports', $exportRows, 'canonical-copy');
            sort($exportOutcomes);
            $this->assertSame(['duplicate', 'saved'], $exportOutcomes);

            DB::purge();
            $playlistExport = PlaylistExport::query()
                ->where('source_playlist_id', $source->id)
                ->where('target_provider', StreamingProvider::Spotify)
                ->where('target_account_id', $review->target_account_id)
                ->firstOrFail();
            $operationRows = [
                $this->operationRow((string) Str::uuid(), $user->id, $review->id, $playlistExport->id, $now),
                $this->operationRow((string) Str::uuid(), $user->id, $review->id, $playlistExport->id, $now),
            ];
            $operationOutcomes = $this->runConcurrentInserts('export_operations', $operationRows, 'review-operation');
            sort($operationOutcomes);
            $this->assertSame(['duplicate', 'saved'], $operationOutcomes);
            $this->assertSame(1, DB::table('export_operations')->where('export_review_id', $review->id)->count());
        } finally {
            DB::purge();
            DB::table('export_operations')->where('export_review_id', $review->id)->delete();
            DB::table('playlist_exports')->where('source_playlist_id', $source->id)->delete();
            DB::table('export_reviews')->where('id', $review->id)->delete();
            DB::table('playlists')->where('id', $source->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }

    /** @return array<string, mixed> */
    private function operationRow(string $id, int $userId, int $reviewId, int $playlistExportId, mixed $now): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'export_review_id' => $reviewId,
            'playlist_export_id' => $playlistExportId,
            'status' => 'queued',
            'attempt_generation' => 0,
            'retry_generation' => 0,
            'automatic_claim_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function runConcurrentInserts(string $table, array $rows, string $purpose): array
    {
        return $this->runConcurrently(count($rows), $purpose, function (int $index) use ($table, $rows): string {
            try {
                DB::table($table)->insert($rows[$index]);

                return 'saved';
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23505') {
                    throw $exception;
                }

                return 'duplicate';
            }
        });
    }

    /**
     * @param  Closure(int): string  $contender
     * @return list<string>
     */
    private function runConcurrently(int $count, string $purpose, Closure $contender): array
    {
        $barrier = sys_get_temp_dir().'/music-map-managed-export-'.$purpose.'-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();
        $pids = [];

        try {
            for ($index = 0; $index < $count; $index++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork managed-export contender.');
                }

                if ($pid === 0) {
                    try {
                        DB::purge();
                        touch("{$barrier}/ready-{$index}");
                        $this->waitForFile("{$barrier}/go");

                        file_put_contents("{$barrier}/result-{$index}", $contender($index));

                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents("{$barrier}/error-{$index}", $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }

                $pids[$index] = $pid;
            }

            $deadline = microtime(true) + 10;

            while (count(glob("{$barrier}/ready-*")) !== $count) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Managed-export contenders did not reach the race barrier.');
                }

                usleep(1000);
            }

            touch("{$barrier}/go");

            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $error = is_file("{$barrier}/error-{$index}")
                    ? file_get_contents("{$barrier}/error-{$index}")
                    : 'No child error was recorded.';
                $this->assertTrue(pcntl_wifexited($status), $error);
                $this->assertSame(0, pcntl_wexitstatus($status), $error);
            }

            DB::purge();

            return array_map(
                static fn (int $index): string => file_get_contents("{$barrier}/result-{$index}"),
                range(0, $count - 1),
            );
        } finally {
            touch("{$barrier}/go");

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }

            DB::purge();
            File::deleteDirectory($barrier);
        }
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This managed-export race test requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This managed-export race test requires the pcntl extension.');
        }
    }

    private function managedOperation(StreamingProvider $provider): ExportOperation
    {
        $user = User::factory()->create();
        $source = Playlist::factory()->for($user)->create([
            'source_playlist_id' => 'postgres-source-'.Str::uuid(),
            'source_provider' => $provider === StreamingProvider::Spotify
                ? StreamingProvider::YouTube
                : StreamingProvider::Spotify,
        ]);
        $review = ExportReview::factory()->for($user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
            'target_provider' => $provider,
            'target_account_id' => 'postgres-managed-owner',
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'target_catalog_id' => 'postgres-target-item',
            'target_catalog_uri' => $provider === StreamingProvider::Spotify
                ? 'spotify:track:postgres-target-item'
                : 'https://www.youtube.com/watch?v=postgres-target-item',
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_provider' => $provider,
            'target_account_id' => 'postgres-managed-owner',
        ]);
        PlaylistExportTargetAttempt::factory()->for($export, 'playlistExport')->create([
            'target_provider' => $provider,
            'target_account_id' => 'postgres-managed-owner',
        ]);

        return ExportOperation::factory()->for($user)->for($review)->for($export, 'playlistExport')->create();
    }

    private function deleteOperationGraph(ExportOperation $operation): void
    {
        DB::purge();
        $operation = ExportOperation::query()->with(['exportReview', 'playlistExport.targetPlaylist', 'playlistExport.sourcePlaylist'])->find($operation->id);
        if (! $operation instanceof ExportOperation) {
            return;
        }
        $userId = $operation->user_id;
        $review = $operation->exportReview;
        $export = $operation->playlistExport;
        $target = $export->targetPlaylist;
        $source = $export->sourcePlaylist;
        $operation->delete();
        $export->targetAttempts()->delete();
        $export->delete();
        $review->delete();
        $target?->delete();
        $source->delete();
        User::query()->whereKey($userId)->delete();
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;

        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Managed-export race barrier timed out.');
            }

            usleep(1000);
        }
    }
}

final class PostgresManagedAccess implements WithManagedAccountAccess
{
    public function handle(
        StreamingProvider $provider,
        string $operationId,
        string $expectedAccountId,
        array $requiredScopes,
        Closure $callback,
    ): ManagedAccessResult {
        $result = $callback(new ManagedAccessContext(
            $provider,
            $expectedAccountId,
            'postgres-race-token',
            new DateTimeImmutable('+1 hour'),
            $operationId,
        ));

        return $result instanceof ManagedExportFailureCode
            ? ManagedAccessResult::failure($result)
            : ManagedAccessResult::success();
    }
}

final class PostgresManagedPlaylistGateway implements ManagedPlaylistGateway
{
    private ?ManagedPlaylistMetadata $metadata = null;

    public function __construct(private StreamingProvider $streamingProvider) {}

    public function provider(): StreamingProvider
    {
        return $this->streamingProvider;
    }

    public function findByMarker(ManagedAccessContext $access, string $marker): ManagedMarkerLookup|ManagedProviderFailure
    {
        return ManagedMarkerLookup::none();
    }

    public function create(ManagedAccessContext $access, ManagedPlaylistMetadata $metadata): ManagedPlaylistReference|ManagedProviderFailure
    {
        $this->metadata = $metadata;

        return $this->reference($access->providerAccountId);
    }

    public function inspect(ManagedAccessContext $access, ManagedPlaylistReference $reference): ManagedPlaylistSnapshot|ManagedProviderFailure
    {
        if (! $this->metadata instanceof ManagedPlaylistMetadata) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }

        return $this->snapshot($reference, $this->metadata);
    }

    public function reconcile(
        ManagedAccessContext $access,
        ManagedPlaylistReference $reference,
        ManagedPlaylistMetadata $metadata,
        array $catalogItems,
    ): ManagedPlaylistReconciliationResult|ManagedProviderFailure {
        return ManagedPlaylistReconciliationResult::exact($this->snapshot($reference, $metadata));
    }

    private function reference(string $owner): ManagedPlaylistReference
    {
        return new ManagedPlaylistReference(
            $this->streamingProvider,
            'postgres-managed-target',
            $this->streamingProvider === StreamingProvider::Spotify
                ? 'https://open.spotify.com/playlist/postgres-managed-target'
                : 'https://www.youtube.com/playlist?list=postgres-managed-target',
            $owner,
        );
    }

    private function snapshot(ManagedPlaylistReference $reference, ManagedPlaylistMetadata $metadata): ManagedPlaylistSnapshot
    {
        return new ManagedPlaylistSnapshot(
            $reference,
            $metadata,
            $this->streamingProvider === StreamingProvider::Spotify ? 'private' : 'unlisted',
            [new ManagedPlaylistItem(
                'postgres-target-item',
                $this->streamingProvider === StreamingProvider::Spotify
                    ? 'spotify:track:postgres-target-item'
                    : 'https://www.youtube.com/watch?v=postgres-target-item',
                0,
                $this->streamingProvider === StreamingProvider::YouTube ? 'postgres-occurrence' : null,
            )],
        );
    }
}
