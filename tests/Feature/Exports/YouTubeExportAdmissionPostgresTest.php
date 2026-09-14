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
use App\Integrations\YouTubeWriteAdmission\Actions\ReserveYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\User;
use App\Models\YouTubeWriteAdmission;
use App\Models\YouTubeWriteQuotaState;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class YouTubeExportAdmissionPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This consumer admission proof requires PostgreSQL and pcntl.');
        }

        config(['services.youtube_write_admission.daily_limit' => 10]);
        $this->app->instance(ManagedExportAccessBroker::class, new PostgresManagedExportBrokerFake);
    }

    public function test_parallel_workers_for_the_same_operation_consume_one_ledger_entry(): void
    {
        $operation = $this->operation();
        $this->resetQuota();
        $this->fakeSuccessfulProvider();

        try {
            $this->raceWorkers($operation, 2);

            DB::purge();
            $operation->refresh();
            $this->assertSame(ExportOperationStatus::Transferred, $operation->status);
            $this->assertNotNull($operation->provider_mutation_started_at);
            $this->assertSame(1, YouTubeWriteAdmission::query()
                ->where('operation_type', YouTubeWriteOperationType::ManagedExport->value)
                ->where('operation_id', $operation->operation_id)
                ->count());
            $this->assertSame(1, $operation->playlistExportLink()->count());
        } finally {
            $this->cleanOperation($operation);
        }
    }

    public function test_quota_denial_at_the_consumer_boundary_performs_zero_write_http(): void
    {
        $operation = $this->operation();
        $this->resetQuota(1, 1);
        Http::fake();

        try {
            app(RunExportOperation::class)->handle($operation->operation_id);

            $operation->refresh();
            $this->assertSame(ExportOperationStatus::Failed, $operation->status);
            $this->assertSame(ExportOperationFailure::AdmissionLimit, $operation->failure_code);
            $this->assertNull($operation->provider_mutation_started_at);
            $this->assertSame(0, YouTubeWriteAdmission::query()
                ->where('operation_id', $operation->operation_id)
                ->count());
            Http::assertNothingSent();
        } finally {
            $this->cleanOperation($operation);
        }
    }

    public function test_lost_admission_confirmation_is_recovered_as_existing_before_first_mutation(): void
    {
        $operation = $this->operation();
        $this->resetQuota();
        $reservation = app(ReserveYouTubeWrite::class)->admit(
            YouTubeWriteOperationType::ManagedExport,
            $operation->operation_id,
        );
        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew, $reservation->status);

        $recording = new RecordingAdmission(app(ReserveYouTubeWrite::class));
        $this->app->instance(AdmitYouTubeWrite::class, $recording);
        $firstMutationObservedAfterRecovery = false;
        $this->fakeSuccessfulProvider(function () use ($recording, &$firstMutationObservedAfterRecovery): void {
            $firstMutationObservedAfterRecovery = $recording->statuses === [
                YouTubeWriteAdmissionStatus::AdmittedExisting,
            ];
        });

        try {
            app(RunExportOperation::class)->handle($operation->operation_id);

            $operation->refresh();
            $this->assertTrue($firstMutationObservedAfterRecovery);
            $this->assertSame([YouTubeWriteAdmissionStatus::AdmittedExisting], $recording->statuses);
            $this->assertSame(ExportOperationStatus::Transferred, $operation->status);
            $this->assertSame(1, YouTubeWriteAdmission::query()
                ->where('operation_id', $operation->operation_id)
                ->count());
        } finally {
            $this->cleanOperation($operation);
        }
    }

    private function operation(): ExportOperation
    {
        $review = ExportReview::factory()->create([
            'target_provider' => StreamingProvider::YouTube,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'managed-youtube-channel',
            'target_market' => null,
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'match_status' => ExportMatchStatus::Matched,
            'decision' => ExportReviewDecision::Keep,
            'target_catalog_id' => 'abcdefghijk',
            'target_catalog_uri' => 'youtube:video:abcdefghijk',
        ]);

        return ExportOperation::factory()->for($review)->create([
            'target_provider' => StreamingProvider::YouTube,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'managed-youtube-channel',
            'target_market' => null,
            'playlist_name' => 'PostgreSQL export proof',
            'playlist_description' => 'Synthetic concurrency fixture.',
        ]);
    }

    private function resetQuota(int $admitted = 0, int $limit = 10): void
    {
        YouTubeWriteQuotaState::query()->whereKey(YouTubeWriteQuotaState::GLOBAL_KEY)->update([
            'quota_day' => now('America/Los_Angeles')->format('Y-m-d'),
            'admitted_count' => $admitted,
            'daily_limit' => $limit,
        ]);
    }

    private function fakeSuccessfulProvider(?callable $onFirstMutation = null): void
    {
        $items = [];
        $observedFirstMutation = false;
        Http::fake(function (Request $request) use (&$items, &$observedFirstMutation, $onFirstMutation) {
            if (! $observedFirstMutation && in_array($request->method(), ['POST', 'PUT', 'DELETE'], true)) {
                $observedFirstMutation = true;
                $onFirstMutation?->__invoke();
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlists?')) {
                return Http::response(['id' => 'PLabcdefghijklm', 'etag' => 'created'], 201);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/playlistItems')) {
                return Http::response([
                    'items' => array_map(
                        static fn (array $item, int $position): array => [
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
                return Http::response(['etag' => 'updated'], 200);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlistItems?')) {
                $items[] = ['id' => 'occurrence-1', 'videoId' => 'abcdefghijk'];

                return Http::response(['id' => 'occurrence-1'], 201);
            }

            return Http::response([], 500);
        });
    }

    private function raceWorkers(ExportOperation $operation, int $count): void
    {
        $barrier = sys_get_temp_dir().'/music-map-admission-consumer-race-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();
        $pids = [];

        try {
            for ($index = 0; $index < $count; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork export worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        touch("{$barrier}/ready-{$index}");
                        $this->await("{$barrier}/go", 'Export worker barrier timed out.');
                        app(RunExportOperation::class)->handle($operation->operation_id);
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
                    throw new RuntimeException('Export workers did not reach the barrier.');
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
        } finally {
            touch("{$barrier}/go");
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge();
            File::deleteDirectory($barrier);
        }
    }

    private function await(string $path, string $message): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }
            usleep(1000);
        }
    }

    private function cleanOperation(ExportOperation $operation): void
    {
        DB::purge();
        YouTubeWriteAdmission::query()->where('operation_id', $operation->operation_id)->delete();
        $this->resetQuota();
        User::query()->whereKey($operation->user_id)->delete();
    }
}

final readonly class PostgresManagedExportBrokerFake implements ManagedExportAccessBroker
{
    public function acquire(string $provider, string $operationId): ManagedExportAccess
    {
        return new ManagedExportAccess(
            $provider,
            'synthetic-managed-access',
            new DateTimeImmutable('+5 minutes'),
            $operationId,
        );
    }
}

final class RecordingAdmission implements AdmitYouTubeWrite
{
    /** @var list<YouTubeWriteAdmissionStatus> */
    public array $statuses = [];

    public function __construct(private readonly ReserveYouTubeWrite $inner) {}

    public function admit(
        YouTubeWriteOperationType $operationType,
        string $operationId,
    ): YouTubeWriteAdmissionResult {
        $result = $this->inner->admit($operationType, $operationId);
        $this->statuses[] = $result->status;

        return $result;
    }
}
