<?php

namespace Tests\Unit\PlaylistSync;

use App\Actions\PlaylistSync\DeterminePlaylistSyncDirection;
use App\Enums\PlaylistSyncDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeterminePlaylistSyncDirectionTest extends TestCase
{
    #[DataProvider('directionMatrix')]
    public function test_it_uses_source_wins_direction_matrix(
        string $currentBank,
        string $currentSource,
        PlaylistSyncDirection $expected,
    ): void {
        $direction = new DeterminePlaylistSyncDirection;

        $this->assertSame(
            $expected,
            $direction->handle('bank-baseline', 'source-baseline', $currentBank, $currentSource),
        );
    }

    /** @return iterable<string, array{string, string, PlaylistSyncDirection}> */
    public static function directionMatrix(): iterable
    {
        yield 'neither side changed' => [
            'bank-baseline',
            'source-baseline',
            PlaylistSyncDirection::NoOp,
        ];
        yield 'only bank changed' => [
            'bank-current',
            'source-baseline',
            PlaylistSyncDirection::Push,
        ];
        yield 'only source changed' => [
            'bank-baseline',
            'source-current',
            PlaylistSyncDirection::Pull,
        ];
        yield 'both sides changed' => [
            'bank-current',
            'source-current',
            PlaylistSyncDirection::Pull,
        ];
    }

    public function test_missing_baseline_is_reserved_for_activation(): void
    {
        $direction = new DeterminePlaylistSyncDirection;

        $this->assertNull($direction->handle(null, 'source-baseline', 'bank-current', 'source-current'));
        $this->assertNull($direction->handle('bank-baseline', null, 'bank-current', 'source-current'));
    }
}
