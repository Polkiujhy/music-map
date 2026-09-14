<?php

namespace Tests\Feature\PlaylistEditing;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\Playlists\PlaylistEditConflict;
use App\Actions\Playlists\UpdateBankPlaylistItems;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class UpdateBankPlaylistItemsPostgresTest extends TestCase
{
    public function test_parallel_edits_do_not_lose_an_update_or_violate_the_position_constraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This race test requires PostgreSQL.');
        }

        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $items = collect(range(0, 2))->map(fn (int $position): PlaylistItem => PlaylistItem::factory()
            ->for($playlist)
            ->create([
                'position' => $position,
                'occurrence_id' => "race-occurrence-{$position}",
            ]));
        $playlist->load('items');
        $fingerprint = (new FingerprintPlaylistContent)->handle($playlist);
        $barrier = sys_get_temp_dir().'/music-map-playlist-edit-race-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();

        $selections = [
            [$items[2]->id, $items[1]->id, $items[0]->id],
            [$items[0]->id, $items[2]->id],
        ];
        $pids = [];

        try {
            foreach ($selections as $index => $selection) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork playlist edit contender.');
                }

                if ($pid === 0) {
                    try {
                        DB::purge();
                        touch("{$barrier}/ready-{$index}");
                        $deadline = microtime(true) + 10;

                        while (! is_file("{$barrier}/go")) {
                            if (microtime(true) >= $deadline) {
                                throw new RuntimeException('Playlist edit barrier timed out.');
                            }

                            usleep(1000);
                        }

                        try {
                            (new UpdateBankPlaylistItems(new FingerprintPlaylistContent))->handle(
                                User::query()->findOrFail($user->id),
                                $playlist->id,
                                $fingerprint,
                                $selection,
                            );
                            file_put_contents("{$barrier}/result-{$index}", 'saved');
                        } catch (PlaylistEditConflict $exception) {
                            if ($exception->reason !== PlaylistEditConflict::CONTENT_CHANGED) {
                                throw $exception;
                            }

                            file_put_contents("{$barrier}/result-{$index}", 'conflict');
                        }

                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents(
                            "{$barrier}/error-{$index}",
                            $exception::class.': '.$exception->getMessage(),
                        );
                        exit(1);
                    }
                }

                $pids[] = $pid;
            }

            $deadline = microtime(true) + 10;

            while (count(glob("{$barrier}/ready-*")) !== count($selections)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Playlist edit contenders did not reach the barrier.');
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

            $results = array_map(
                static fn (int $index): string => file_get_contents("{$barrier}/result-{$index}"),
                array_keys($selections),
            );
            sort($results);
            $this->assertSame(['conflict', 'saved'], $results);

            DB::purge();
            $positions = Playlist::query()->findOrFail($playlist->id)->items()->pluck('position')->all();
            $this->assertSame(range(0, count($positions) - 1), $positions);
        } finally {
            touch("{$barrier}/go");

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }

            DB::purge();
            User::query()->whereKey($user->id)->delete();
            File::deleteDirectory($barrier);
        }
    }
}
