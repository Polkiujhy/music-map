<?php

namespace Tests\Unit\Integrations\ExportMatching;

use App\Integrations\ExportMatching\YouTubeSearchBudget;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class YouTubeSearchBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.export_matching.youtube.daily_search_limit' => 3]);
        Cache::flush();
    }

    public function test_it_deduplicates_fingerprints_across_parallel_starts(): void
    {
        $this->assertTrue((new YouTubeSearchBudget)->reserve(['same', 'same']));
        $this->assertTrue((new YouTubeSearchBudget)->reserve(['same']));

        $this->assertSame(['same'], Cache::get($this->key()));
    }

    public function test_cache_hits_need_no_reservation_and_consume_no_budget(): void
    {
        $budget = new YouTubeSearchBudget;
        $this->assertTrue($budget->reserve(['miss']));
        $this->assertTrue($budget->reserve([]));

        $this->assertSame(['miss'], Cache::get($this->key()));
    }

    public function test_reservation_is_all_or_nothing_before_any_search(): void
    {
        $budget = new YouTubeSearchBudget;
        $this->assertTrue($budget->reserve(['first', 'second']));
        $this->assertFalse($budget->reserve(['third', 'fourth']));

        $this->assertSame(['first', 'second'], Cache::get($this->key()));
    }

    public function test_exhaustion_returns_a_readable_boolean_refusal_without_partial_charge(): void
    {
        config(['services.export_matching.youtube.daily_search_limit' => 0]);

        $this->assertFalse((new YouTubeSearchBudget)->reserve(['not-charged']));
        $this->assertNull(Cache::get($this->key()));
    }

    private function key(): string
    {
        return 'export-matching:youtube-budget:'.now()->format('Y-m-d');
    }
}
