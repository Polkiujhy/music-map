<?php

namespace Tests\Unit\Integrations\PlaylistImport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\ImportResult;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PlaylistDataTest extends TestCase
{
    public function test_snapshot_is_bounded_to_twenty_typed_items(): void
    {
        $items = array_map(fn (int $position) => $this->item($position), range(0, 19));
        $snapshot = $this->snapshot($items);

        $this->assertCount(20, $snapshot->items);
        $this->assertSame(['Canary creator'], $snapshot->items[0]->creators);

        $this->expectException(InvalidArgumentException::class);
        $this->snapshot([...$items, $this->item(20)]);
    }

    public function test_item_rejects_negative_positions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->item(-1);
    }

    public function test_result_contains_only_typed_safe_failure_context(): void
    {
        $result = ImportResult::failure(
            ImportFailureCode::ProviderUnavailable,
            StreamingProvider::YouTube,
            '9f8ad42e-0138-4cc6-a470-bf70f15695e8',
        );

        $this->assertFalse($result->successful);
        $this->assertSame(ImportFailureCode::ProviderUnavailable, $result->failureCode);
        $this->assertSame(StreamingProvider::YouTube, $result->provider);
        $this->assertStringContainsString($result->correlationId, $result->failureMessage());
        $this->assertSame(
            ['correlationId', 'successful', 'playlistId', 'refreshed', 'failureCode', 'provider'],
            array_keys(get_object_vars($result)),
        );
    }

    private function snapshot(array $items): PlaylistSnapshot
    {
        return new PlaylistSnapshot(
            StreamingProvider::YouTube,
            'PL_canary',
            null,
            'https://www.youtube.com/playlist?list=PL_canary',
            'canary-revision',
            'Canary playlist',
            null,
            new DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            $items,
        );
    }

    private function item(int $position): PlaylistItemSnapshot
    {
        return new PlaylistItemSnapshot(
            "occurrence-{$position}",
            'catalog-canary',
            'canary:catalog:item',
            'Canary item',
            ['Canary creator'],
            'Canary album',
            1000,
            'CANARY000001',
            $position,
            true,
        );
    }
}
