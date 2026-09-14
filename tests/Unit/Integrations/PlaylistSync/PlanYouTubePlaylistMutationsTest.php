<?php

namespace Tests\Unit\Integrations\PlaylistSync;

use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\YouTube\PlanYouTubePlaylistMutations;
use App\Integrations\PlaylistSync\YouTube\YouTubePlaylistMutation;
use PHPUnit\Framework\TestCase;

class PlanYouTubePlaylistMutationsTest extends TestCase
{
    public function test_it_plans_a_minimal_deterministic_diff_with_duplicates(): void
    {
        $snapshot = new SourcePlaylistSnapshot(
            ['a', 'extra', 'b', 'a'],
            providerItemIdentifiers: ['item-a1', 'item-extra', 'item-b', 'item-a2'],
        );

        $first = (new PlanYouTubePlaylistMutations)->handle($snapshot, ['a', 'a', 'b']);
        $second = (new PlanYouTubePlaylistMutations)->handle($snapshot, ['a', 'a', 'b']);

        $this->assertEquals($first, $second);
        $this->assertSame(
            [YouTubePlaylistMutation::DELETE, YouTubePlaylistMutation::UPDATE_POSITION],
            array_column(array_map(fn ($mutation) => $mutation->toArray(), $first), 'type'),
        );
        $this->assertSame('item-extra', $first[0]->providerItemId);
        $this->assertSame('item-a2', $first[1]->providerItemId);
    }

    public function test_it_handles_empty_and_twenty_item_boundaries(): void
    {
        $empty = new SourcePlaylistSnapshot([], providerItemIdentifiers: []);
        $this->assertSame([], (new PlanYouTubePlaylistMutations)->handle($empty, []));

        $desired = array_map(fn (int $number): string => "video-{$number}", range(1, 20));
        $mutations = (new PlanYouTubePlaylistMutations)->handle($empty, $desired);
        $this->assertCount(20, $mutations);
        $this->assertContainsOnlyInstancesOf(YouTubePlaylistMutation::class, $mutations);
        $this->assertSame(range(0, 19), array_column(array_map(fn ($mutation) => $mutation->toArray(), $mutations), 'position'));
    }

    public function test_every_step_links_its_before_and_after_fingerprint(): void
    {
        $snapshot = new SourcePlaylistSnapshot(['a', 'b'], providerItemIdentifiers: ['item-a', 'item-b']);
        $mutations = (new PlanYouTubePlaylistMutations)->handle($snapshot, ['c', 'a']);

        foreach (array_slice($mutations, 1) as $index => $mutation) {
            $this->assertSame($mutations[$index]->afterFingerprint, $mutation->beforeFingerprint);
        }
        $this->assertSame(
            PlanYouTubePlaylistMutations::fingerprintIdentifiers(['c', 'a']),
            $mutations[array_key_last($mutations)]->afterFingerprint,
        );
    }
}
