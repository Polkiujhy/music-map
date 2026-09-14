<?php

namespace Tests\Feature\PlaylistSync;

use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncOutcome;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistSynchronizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_expose_typed_state_casts_and_unambiguous_relationships(): void
    {
        $account = StreamingAccount::factory()->youtube()->create();
        $sync = PlaylistSynchronization::factory()->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'automatic_enabled' => true,
            'last_outcome' => PlaylistSyncOutcome::Pulled,
            'last_checked_at' => now(),
            'last_succeeded_at' => now(),
            'next_check_at' => now()->addHour(),
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'trigger' => PlaylistSyncTrigger::Automatic,
            'direction' => PlaylistSyncDirection::Pull,
        ]);

        $this->assertTrue($sync->playlist->synchronization->is($sync));
        $this->assertTrue($account->playlistSynchronizations->contains($sync));
        $this->assertTrue($sync->runs->contains($run));
        $this->assertTrue($run->synchronization->is($sync));
        $this->assertSame(PlaylistSyncStatus::Enabled, $sync->status);
        $this->assertSame(PlaylistSyncOutcome::Pulled, $sync->last_outcome);
        $this->assertTrue($sync->automatic_enabled);
        $this->assertInstanceOf(CarbonImmutable::class, $sync->last_checked_at);
        $this->assertInstanceOf(CarbonImmutable::class, $sync->last_succeeded_at);
        $this->assertInstanceOf(CarbonImmutable::class, $sync->next_check_at);
        $this->assertInstanceOf(CarbonImmutable::class, $sync->created_at);
        $this->assertSame(PlaylistSyncTrigger::Automatic, $run->trigger);
        $this->assertSame(PlaylistSyncDirection::Pull, $run->direction);
        $this->assertInstanceOf(CarbonImmutable::class, $run->created_at);
    }

    public function test_factories_default_to_inactive_safe_canary_state_without_checkpoint_data(): void
    {
        $sync = PlaylistSynchronization::factory()->create();
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create();

        $this->assertSame(PlaylistSyncStatus::PendingConfirmation, $sync->status);
        $this->assertFalse($sync->automatic_enabled);
        $this->assertNull($sync->streaming_account_id);
        $this->assertNull($sync->baseline_bank_fingerprint);
        $this->assertNull($sync->baseline_source_fingerprint);
        $this->assertNull($run->checkpoint);
        $this->assertSame(['canary-track-a', 'canary-track-b'], $run->bank_snapshot);
        $this->assertSame(['canary-track-a', 'canary-track-b'], $run->source_snapshot);
        $this->assertArrayNotHasKey('checkpoint', $run->toArray());
        $this->assertStringContainsString('canary', $run->input_provider_revision);
        $this->assertStringNotContainsString('token', $run->toJson());
        $this->assertStringNotContainsString('secret', $run->toJson());
    }

    public function test_sync_enums_are_closed_to_the_persisted_contract(): void
    {
        $this->assertSame(
            ['pending-confirmation', 'enabled', 'attention', 'disabled'],
            array_column(PlaylistSyncStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['activation', 'manual', 'automatic', 'login'],
            array_column(PlaylistSyncTrigger::cases(), 'value'),
        );
        $this->assertSame(['no-op', 'pull', 'push'], array_column(PlaylistSyncDirection::cases(), 'value'));
        $this->assertSame(['no-op', 'pulled', 'pushed', 'failed'], array_column(PlaylistSyncOutcome::cases(), 'value'));
    }
}
