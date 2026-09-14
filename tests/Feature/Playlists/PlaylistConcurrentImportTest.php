<?php

namespace Tests\Feature\Playlists;

use App\Actions\Playlists\ReplaceImportedPlaylist;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Models\Playlist;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PlaylistConcurrentImportTest extends TestCase
{
    public function test_simultaneous_first_import_keeps_one_complete_snapshot_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This race test requires PostgreSQL.');
        }

        $user = User::factory()->create();
        $barrier = sys_get_temp_dir().'/music-map-import-race-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();

        $contenders = [
            ['First', ['first-a']],
            ['Second', ['second-a', 'second-b']],
        ];
        $pids = [];

        try {
            foreach ($contenders as $index => [$name, $catalogIds]) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork import contender.');
                }

                if ($pid === 0) {
                    try {
                        DB::purge();
                        touch("{$barrier}/ready-{$index}");

                        $deadline = microtime(true) + 10;

                        while (! is_file("{$barrier}/go")) {
                            if (microtime(true) >= $deadline) {
                                throw new RuntimeException('Import barrier timed out.');
                            }

                            usleep(1000);
                        }

                        (new ReplaceImportedPlaylist)->handle(
                            User::query()->findOrFail($user->id),
                            $this->snapshot($name, $catalogIds),
                        );

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

            while (count(glob("{$barrier}/ready-*")) !== count($contenders)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Contenders did not reach the barrier.');
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

            $playlist = Playlist::query()->with('items')->sole();
            $catalogIds = $playlist->items->pluck('catalog_id')->all();

            $this->assertDatabaseCount('playlists', 1);
            $this->assertContains(
                $catalogIds,
                [['first-a'], ['second-a', 'second-b']],
                'The final items must be one complete contender snapshot.',
            );
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

    /**
     * @param  list<string>  $catalogIds
     */
    private function snapshot(string $name, array $catalogIds): PlaylistSnapshot
    {
        return new PlaylistSnapshot(
            StreamingProvider::YouTube,
            'PL_concurrent_canary',
            null,
            'https://www.youtube.com/playlist?list=PL_concurrent_canary',
            'revision-canary',
            $name,
            'Canary description',
            new DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            array_map(
                static fn (string $catalogId, int $position) => new PlaylistItemSnapshot(
                    "occurrence-{$position}",
                    $catalogId,
                    "canary:{$catalogId}",
                    'Canary title',
                    ['Canary creator'],
                    'Canary album',
                    1000,
                    'CANARY000001',
                    $position,
                    true,
                ),
                $catalogIds,
                array_keys($catalogIds),
            ),
        );
    }
}
