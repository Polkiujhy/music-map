<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Livewire\ExportReviewPanel;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ExportReviewDecisionConcurrencyTest extends TestCase
{
    public function test_late_decision_cannot_change_a_confirmed_review_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This race test requires PostgreSQL and pcntl.');
        }

        $review = $this->readyReview();
        $item = $review->items()->firstOrFail();
        $barrier = sys_get_temp_dir().'/music-map-review-decision-race-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        $pid = null;

        try {
            DB::beginTransaction();
            $locked = ExportReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork decision contender.');
            }

            if ($pid === 0) {
                try {
                    $defaultConnection = DB::getDefaultConnection();
                    // Keep the inherited parent PDO alive until the lock is released;
                    // purging it in the child terminates the parent's PostgreSQL session.
                    config([
                        'database.connections.export_review_contender' => config("database.connections.{$defaultConnection}"),
                    ]);
                    DB::setDefaultConnection('export_review_contender');
                    $user = User::query()->findOrFail($review->user_id);
                    $component = Livewire::actingAs($user)
                        ->test(ExportReviewPanel::class, ['exportReview' => ExportReview::query()->findOrFail($review->id)]);

                    touch("{$barrier}/ready");

                    $component
                        ->call('choose', $item->id, ExportReviewDecision::Remove->value)
                        ->assertHasErrors('review');

                    touch("{$barrier}/done");
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents("{$barrier}/error", $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }

            $deadline = microtime(true) + 10;
            while (! is_file("{$barrier}/ready")) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Decision contender did not reach the barrier.');
                }

                usleep(1000);
            }

            usleep(250000);
            $locked->forceFill([
                'status' => ExportReviewStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();
            DB::commit();

            pcntl_waitpid($pid, $status);
            $error = is_file("{$barrier}/error")
                ? file_get_contents("{$barrier}/error")
                : 'No child error was recorded.';

            $this->assertTrue(pcntl_wifexited($status), $error);
            $this->assertSame(0, pcntl_wexitstatus($status), $error);

            DB::purge();
            $this->assertNull(ExportReviewItem::query()->findOrFail($item->id)->decision);
            $this->assertSame(ExportReviewStatus::Confirmed, ExportReview::query()->findOrFail($review->id)->status);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            touch("{$barrier}/ready");

            if (is_int($pid) && $pid > 0) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }

            DB::purge();
            User::query()->whereKey($review->user_id)->delete();
            File::deleteDirectory($barrier);
        }
    }

    private function readyReview(): ExportReview
    {
        config([
            'services.platform_access.spotify.technical.account_id' => 'managed-spotify',
            'services.platform_access.spotify.technical.market' => 'GB',
        ]);

        $playlist = Playlist::factory()->create();
        $source = PlaylistItem::factory()->for($playlist)->create(['position' => 0]);
        $playlist->load(['user', 'items']);
        $review = ExportReview::factory()->for($playlist->user)->for($playlist)->create([
            'status' => ExportReviewStatus::Ready,
            'target_account_id' => 'managed-spotify',
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($playlist),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'source_occurrence_id' => $source->occurrence_id,
            'match_status' => ExportMatchStatus::Suspicious,
            'target_catalog_id' => 'target-0',
            'target_catalog_uri' => 'spotify:track:0',
        ]);

        return $review->load(['user', 'items']);
    }
}
