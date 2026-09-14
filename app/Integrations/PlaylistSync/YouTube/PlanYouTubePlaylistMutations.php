<?php

namespace App\Integrations\PlaylistSync\YouTube;

use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use InvalidArgumentException;

final class PlanYouTubePlaylistMutations
{
    /** @param list<string> $desiredIdentifiers
     * @return list<YouTubePlaylistMutation>
     */
    public function handle(SourcePlaylistSnapshot $current, array $desiredIdentifiers): array
    {
        if (count($desiredIdentifiers) > 20 || count($current->providerItemIdentifiers) !== count($current->itemIdentifiers)) {
            throw new InvalidArgumentException('YouTube mutation inputs must be complete and contain at most 20 items.');
        }

        $working = [];
        foreach ($current->normalizedItemIdentifiers() as $index => $catalogId) {
            $providerItemId = $current->providerItemIdentifiers[$index] ?? null;
            if (! is_string($providerItemId) || $providerItemId === '') {
                throw new InvalidArgumentException('Every existing YouTube item needs its playlistItem ID.');
            }
            $working[] = ['catalog_id' => $catalogId, 'provider_item_id' => $providerItemId];
        }

        $mutations = [];
        $wantedCounts = array_count_values(array_map('trim', $desiredIdentifiers));
        $keptCounts = [];
        for ($position = count($working) - 1; $position >= 0; $position--) {
            $identifier = $working[$position]['catalog_id'];
            $keptCounts[$identifier] = ($keptCounts[$identifier] ?? 0) + 1;
            if ($keptCounts[$identifier] <= ($wantedCounts[$identifier] ?? 0)) {
                continue;
            }
            $item = $working[$position];
            $before = $this->fingerprint($working);
            array_splice($working, $position, 1);
            $mutations[] = new YouTubePlaylistMutation(
                YouTubePlaylistMutation::DELETE,
                $item['provider_item_id'],
                $item['catalog_id'],
                $position,
                $before,
                $this->fingerprint($working),
            );
        }

        $availableCounts = array_count_values(array_column($working, 'catalog_id'));
        $desiredSeen = [];
        $retainedDesired = [];
        foreach ($desiredIdentifiers as $identifier) {
            $desired = trim($identifier);
            if ($desired === '') {
                throw new InvalidArgumentException('Desired YouTube video IDs must be non-empty.');
            }
            $desiredSeen[$desired] = ($desiredSeen[$desired] ?? 0) + 1;
            if ($desiredSeen[$desired] <= ($availableCounts[$desired] ?? 0)) {
                $retainedDesired[] = $desired;
            }
        }

        $forward = $this->reorder($working, $retainedDesired, false);
        $backward = $this->reorder($working, $retainedDesired, true);
        $reorders = count($forward) <= count($backward) ? $forward : $backward;
        foreach ($reorders as $mutation) {
            $from = null;
            foreach ($working as $index => $item) {
                if ($item['provider_item_id'] === $mutation->providerItemId) {
                    $from = $index;
                    break;
                }
            }
            $item = $working[$from];
            array_splice($working, $from, 1);
            array_splice($working, $mutation->position, 0, [$item]);
            $mutations[] = $mutation;
        }

        for ($position = 0; $position < count($desiredIdentifiers); $position++) {
            $desired = trim($desiredIdentifiers[$position]);
            if (($working[$position]['catalog_id'] ?? null) === $desired) {
                continue;
            }
            $before = $this->fingerprint($working);
            $item = ['catalog_id' => $desired, 'provider_item_id' => null];
            array_splice($working, min($position, count($working)), 0, [$item]);
            $mutations[] = new YouTubePlaylistMutation(
                YouTubePlaylistMutation::INSERT,
                null,
                $desired,
                min($position, count($working) - 1),
                $before,
                $this->fingerprint($working),
            );
        }

        return $mutations;
    }

    /**
     * @param  list<array{catalog_id:string,provider_item_id:?string}>  $working
     * @param  list<string>  $desired
     * @return list<YouTubePlaylistMutation>
     */
    private function reorder(array $working, array $desired, bool $backward): array
    {
        $mutations = [];
        $positions = $backward ? array_reverse(array_keys($desired)) : array_keys($desired);
        foreach ($positions as $position) {
            if (($working[$position]['catalog_id'] ?? null) === $desired[$position]) {
                continue;
            }
            $found = null;
            if ($backward) {
                for ($candidate = $position - 1; $candidate >= 0; $candidate--) {
                    if ($working[$candidate]['catalog_id'] === $desired[$position]) {
                        $found = $candidate;
                        break;
                    }
                }
            } else {
                for ($candidate = $position + 1; $candidate < count($working); $candidate++) {
                    if ($working[$candidate]['catalog_id'] === $desired[$position]) {
                        $found = $candidate;
                        break;
                    }
                }
            }
            if ($found === null) {
                throw new InvalidArgumentException('YouTube mutation planner could not reconcile item occurrences.');
            }
            $before = $this->fingerprint($working);
            $item = $working[$found];
            array_splice($working, $found, 1);
            array_splice($working, $position, 0, [$item]);
            $mutations[] = new YouTubePlaylistMutation(
                YouTubePlaylistMutation::UPDATE_POSITION,
                $item['provider_item_id'],
                $item['catalog_id'],
                $position,
                $before,
                $this->fingerprint($working),
            );
        }

        return $mutations;
    }

    /** @param list<array{catalog_id:string,provider_item_id:?string}> $items */
    private function fingerprint(array $items): string
    {
        return self::fingerprintIdentifiers(array_column($items, 'catalog_id'));
    }

    /** @param list<string> $identifiers */
    public static function fingerprintIdentifiers(array $identifiers): string
    {
        return hash('sha256', "youtube-playlist:v1\n".json_encode(array_values($identifiers), JSON_THROW_ON_ERROR));
    }
}
