<?php

namespace Tests\Feature\Exports;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\Exports\PersistExportTarget;
use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExportLink;
use App\Models\PlaylistItem;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ExportOperationPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This export concurrency proof requires PostgreSQL and pcntl.');
        }

        config([
            'services.platform_access.spotify.technical.account_id' => 'managed-spotify',
            'services.platform_access.spotify.technical.market' => 'GB',
        ]);
        Queue::fake();
    }

    public function test_parallel_confirmation_of_one_review_creates_exactly_one_operation_without_deadlock(): void
    {
        $review = $this->readyReview();

        try {
            $results = $this->race(2, function () use ($review): string {
                $current = ExportReview::query()->findOrFail($review->id);

                return app(ConfirmExportReview::class)->handle(
                    User::query()->findOrFail($review->user_id),
                    $current,
                    [],
                );
            });

            $this->assertCount(1, array_unique($results));
            $this->assertSame(
                1,
                ExportOperation::query()->where('export_review_id', $review->id)->count(),
            );
        } finally {
            DB::purge();
            User::query()->whereKey($review->user_id)->delete();
        }
    }

    public function test_parallel_fresh_reviews_for_one_destination_allow_only_one_active_operation(): void
    {
        $first = $this->readyReview();
        $second = $this->readyReview($first->playlist, $first->user);
        $reviewIds = [$first->id, $second->id];

        try {
            $results = $this->race(2, function (int $index) use ($first, $reviewIds): string {
                try {
                    $review = ExportReview::query()->findOrFail($reviewIds[$index]);
                    app(ConfirmExportReview::class)->handle(
                        User::query()->findOrFail($first->user_id),
                        $review,
                        [],
                    );

                    return 'started';
                } catch (ValidationException) {
                    return 'blocked';
                }
            });
            sort($results);

            $this->assertSame(['blocked', 'started'], $results);
            $this->assertSame(1, ExportOperation::query()->whereIn('export_review_id', $reviewIds)->count());
            $this->assertSame(1, ExportOperation::query()
                ->whereIn('export_review_id', $reviewIds)
                ->whereNotNull('active_key')
                ->count());
        } finally {
            DB::purge();
            User::query()->whereKey($first->user_id)->delete();
        }
    }

    public function test_overlapping_checkpoints_and_unlink_relink_keep_one_durable_target(): void
    {
        $account = StreamingAccount::factory()->spotify()->create([
            'provider_account_id' => 'linked-spotify-account',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
        $review = $this->confirmedLinkedReview($account);
        $operation = ExportOperation::factory()->for($review)->create([
            'streaming_account_id' => $account->id,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => $account->provider_account_id,
        ]);
        $providerId = 'ABCDEFGHIJKLMNOPQRSTUV';

        try {
            $links = $this->race(2, function () use ($operation, $providerId): string {
                return (string) app(PersistExportTarget::class)
                    ->checkpoint(ExportOperation::query()->findOrFail($operation->id), $providerId)
                    ->getKey();
            });

            $this->assertCount(1, array_unique($links));
            $this->assertSame(1, PlaylistExportLink::query()->where('source_playlist_id', $review->playlist_id)->count());
            $this->assertSame(1, Playlist::query()->where('source_playlist_id', $providerId)->count());

            $operation->refresh()->forceFill([
                'status' => ExportOperationStatus::Transferred,
                'active_key' => null,
                'completed_at' => now(),
            ])->save();
            $account->delete();
            $replacement = StreamingAccount::factory()->spotify()->for($review->user)->create([
                'provider_account_id' => 'linked-spotify-account',
                'scopes' => StreamingProvider::Spotify->requiredScopes(),
            ]);
            $nextReview = $this->confirmedLinkedReview($replacement, $review->playlist);
            $nextOperation = ExportOperation::factory()->for($nextReview)->create([
                'streaming_account_id' => $replacement->id,
                'target_provider' => StreamingProvider::Spotify,
                'destination_type' => ExportDestinationType::Linked,
                'target_account_id' => $replacement->provider_account_id,
            ]);

            $reused = app(PersistExportTarget::class)->checkpoint($nextOperation, $providerId);

            $this->assertSame((int) $links[0], (int) $reused->getKey());
            $this->assertSame(1, PlaylistExportLink::query()->where('source_playlist_id', $review->playlist_id)->count());
            $this->assertSame(1, Playlist::query()->where('source_playlist_id', $providerId)->count());
            $this->assertNull($operation->fresh()->streaming_account_id);
        } finally {
            DB::purge();
            User::query()->whereKey($review->user_id)->delete();
        }
    }

    private function readyReview(?Playlist $playlist = null, ?User $user = null): ExportReview
    {
        $user ??= User::factory()->create();
        $playlist ??= Playlist::factory()->for($user)->create([
            'source_playlist_id' => 'PL'.Str::lower(Str::random(20)),
        ]);
        $source = $playlist->items()->first() ?? PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'occurrence_id' => 'source-'.Str::uuid(),
        ]);
        $playlist->load('items');
        $review = ExportReview::factory()->for($user)->for($playlist)->create([
            'status' => ExportReviewStatus::Ready,
            'target_account_id' => 'managed-spotify',
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($playlist),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'source_occurrence_id' => $source->occurrence_id,
            'source_catalog_id' => $source->catalog_id,
            'source_catalog_uri' => $source->catalog_uri,
            'source_title' => $source->title,
            'source_creators' => $source->creators,
            'source_is_available' => true,
            'match_status' => ExportMatchStatus::Matched,
            'target_catalog_id' => 'ABCDEFGHIJKLMNOPQRSTUV',
            'target_catalog_uri' => 'spotify:track:ABCDEFGHIJKLMNOPQRSTUV',
        ]);

        return $review->load(['user', 'playlist']);
    }

    private function confirmedLinkedReview(StreamingAccount $account, ?Playlist $playlist = null): ExportReview
    {
        $playlist ??= Playlist::factory()->for($account->user)->create([
            'source_playlist_id' => 'PL'.Str::lower(Str::random(20)),
        ]);

        return ExportReview::factory()->for($account->user)->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => $account->provider_account_id,
            'target_market' => $account->market,
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
        ])->load(['user', 'playlist']);
    }

    /**
     * @param  callable(int): string  $contender
     * @return list<string>
     */
    private function race(int $count, callable $contender): array
    {
        $barrier = sys_get_temp_dir().'/music-map-export-race-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();
        $pids = [];

        try {
            for ($index = 0; $index < $count; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork export contender.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        touch("{$barrier}/ready-{$index}");
                        $this->await("{$barrier}/go", 'Export contender barrier timed out.');
                        file_put_contents("{$barrier}/result-{$index}", $contender($index));
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents("{$barrier}/error-{$index}", $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $pids[$index] = $pid;
            }

            $this->awaitCount("{$barrier}/ready-*", $count, 'Export contenders did not reach the barrier.');
            touch("{$barrier}/go");
            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $error = is_file("{$barrier}/error-{$index}")
                    ? file_get_contents("{$barrier}/error-{$index}")
                    : 'No child error was recorded.';
                $this->assertTrue(pcntl_wifexited($status), $error);
                $this->assertSame(0, pcntl_wexitstatus($status), $error);
            }

            return array_map(
                static fn (int $index): string => file_get_contents("{$barrier}/result-{$index}"),
                array_keys($pids),
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

    private function awaitCount(string $pattern, int $count, string $message): void
    {
        $deadline = microtime(true) + 10;
        while (count(glob($pattern)) !== $count) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }
            usleep(1000);
        }
    }
}
