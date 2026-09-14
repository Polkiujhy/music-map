<?php

namespace App\Integrations\ExportMatching;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class YouTubeSearchBudget
{
    /**
     * Atomically reserves every previously unseen fingerprint or none of them.
     *
     * @param  list<string>  $fingerprints
     */
    public function reserve(array $fingerprints): bool
    {
        $fingerprints = array_values(array_unique($fingerprints));
        $day = now()->format('Y-m-d');
        $key = 'export-matching:youtube-budget:'.$day;

        try {
            return Cache::lock($key.':lock', 10)->block(5, function () use ($fingerprints, $key): bool {
                $reserved = Cache::get($key, []);
                $reserved = is_array($reserved) ? array_values(array_filter($reserved, 'is_string')) : [];
                $new = array_values(array_diff($fingerprints, $reserved));
                $limit = max(0, (int) config('services.export_matching.youtube.daily_search_limit', 100));

                if (count($reserved) + count($new) > $limit) {
                    return false;
                }

                Cache::put($key, array_values(array_unique([...$reserved, ...$new])), now()->endOfDay());

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }
}
