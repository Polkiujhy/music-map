<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ManagedExportPostgresTest extends TestCase
{
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
        $barrier = sys_get_temp_dir().'/music-map-managed-export-'.$purpose.'-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();
        $pids = [];

        try {
            foreach ($rows as $index => $row) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork managed-export contender.');
                }

                if ($pid === 0) {
                    try {
                        DB::purge();
                        touch("{$barrier}/ready-{$index}");
                        $this->waitForFile("{$barrier}/go");

                        try {
                            DB::table($table)->insert($row);
                            file_put_contents("{$barrier}/result-{$index}", 'saved');
                        } catch (QueryException $exception) {
                            if ($exception->getCode() !== '23505') {
                                throw $exception;
                            }

                            file_put_contents("{$barrier}/result-{$index}", 'duplicate');
                        }

                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents("{$barrier}/error-{$index}", $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }

                $pids[$index] = $pid;
            }

            $deadline = microtime(true) + 10;

            while (count(glob("{$barrier}/ready-*")) !== count($rows)) {
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
                array_keys($rows),
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
