<?php

namespace Database\Factories;

use App\Enums\ExportDestinationType;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportReview;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExportReview> */
class ExportReviewFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ExportReview $review): void {
            if ($review->user_id === null) {
                $review->user_id = Playlist::query()->findOrFail($review->playlist_id)->user_id;
            }
        });
    }

    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'streaming_account_id' => null,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'canary-managed-account',
            'target_market' => 'GB',
            'source_fingerprint' => hash('sha256', 'canary-playlist-snapshot'),
            'status' => ExportReviewStatus::Queued,
            'failure_code' => null,
            'correlation_id' => (string) Str::uuid(),
            'started_at' => null,
            'completed_at' => null,
            'expires_at' => now()->addDay(),
            'confirmed_at' => null,
            'notification_sent_at' => null,
        ];
    }
}
