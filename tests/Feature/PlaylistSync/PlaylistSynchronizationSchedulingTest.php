<?php

namespace Tests\Feature\PlaylistSync;

use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Enums\StreamingProvider;
use App\Jobs\RunPlaylistSynchronization;
use App\Models\Playlist;
use App\Models\PlaylistSynchronization;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlaylistSynchronizationSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_command_claims_a_bounded_batch_once_and_schedules_within_four_hours(): void
    {
        Queue::fake();
        config()->set('playlist-sync.dispatch_batch_size', 2);
        config()->set('playlist-sync.maximum_check_interval_minutes', 240);
        $syncs = collect(range(1, 3))->map(fn (): PlaylistSynchronization => $this->synchronization([
            'automatic_enabled' => true,
            'next_check_at' => now()->subMinute(),
        ]));

        $this->artisan('playlist-sync:dispatch-due')->assertSuccessful();

        Queue::assertPushed(RunPlaylistSynchronization::class, 2);
        $this->assertSame(2, $syncs->sum(fn (PlaylistSynchronization $sync): int => $sync->runs()->count()));
        $syncs->take(2)->each(function (PlaylistSynchronization $sync): void {
            $next = $sync->refresh()->next_check_at;
            $this->assertTrue($next->isFuture());
            $this->assertTrue($next->lessThanOrEqualTo(now()->addMinutes(235)));
        });

        $this->artisan('playlist-sync:dispatch-due')->assertSuccessful();
        Queue::assertPushed(RunPlaylistSynchronization::class, 3);
        $this->assertSame(3, $syncs->sum(fn (PlaylistSynchronization $sync): int => $sync->runs()->count()));
    }

    public function test_login_dispatches_only_enabled_synchronizations_not_checked_for_fifteen_minutes(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $due = $this->synchronization([
            'automatic_enabled' => true,
            'last_checked_at' => now()->subMinutes(15),
        ], $user);
        $recent = $this->synchronization([
            'automatic_enabled' => true,
            'last_checked_at' => now()->subMinutes(14),
        ], $user);
        $manualOnly = $this->synchronization(['last_checked_at' => now()->subHour()], $user);
        $disabled = $this->synchronization([
            'status' => PlaylistSyncStatus::Disabled,
            'last_checked_at' => now()->subHour(),
        ], $user);

        Event::dispatch(new Login('web', $user, false));

        Queue::assertPushed(RunPlaylistSynchronization::class, 1);
        $this->assertSame(PlaylistSyncTrigger::Login, $due->runs()->value('trigger'));
        $this->assertSame(0, $recent->runs()->count());
        $this->assertSame(0, $manualOnly->runs()->count());
        $this->assertSame(0, $disabled->runs()->count());

        Event::dispatch(new Login('web', $user, false));
        Queue::assertPushed(RunPlaylistSynchronization::class, 1);
    }

    public function test_login_dispatch_is_limited_to_the_configured_batch(): void
    {
        Queue::fake();
        config()->set('playlist-sync.dispatch_batch_size', 2);
        $user = User::factory()->create();
        $due = collect(range(1, 3))->map(fn (): PlaylistSynchronization => $this->synchronization([
            'automatic_enabled' => true,
            'last_checked_at' => now()->subHour(),
        ], $user));

        Event::dispatch(new Login('web', $user, false));

        Queue::assertPushed(RunPlaylistSynchronization::class, 2);
        $this->assertSame(2, $due->sum(fn (PlaylistSynchronization $sync): int => $sync->runs()->count()));
        $this->assertSame(0, $due->last()->runs()->count());
    }

    private function synchronization(array $attributes = [], ?User $user = null): PlaylistSynchronization
    {
        $user ??= User::factory()->create();
        $account = $user->streamingAccounts()
            ->where('provider', StreamingProvider::Spotify->value)
            ->first()
            ?? StreamingAccount::factory()->for($user)->spotify()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'streaming_account_id' => $account->id,
            'source_playlist_id' => 'schedule-'.Str::uuid(),
        ]);

        return PlaylistSynchronization::factory()->for($playlist)->create(array_merge([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => hash('sha256', 'bank-'.$playlist->id),
            'baseline_source_fingerprint' => hash('sha256', 'source-'.$playlist->id),
        ], $attributes));
    }
}
