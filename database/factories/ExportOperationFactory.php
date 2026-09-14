<?php

namespace Database\Factories;

use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExportOperation> */
class ExportOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'operation_id' => (string) Str::uuid(),
            'export_review_id' => ExportReview::factory()->state([
                'status' => ExportReviewStatus::Confirmed,
                'confirmed_at' => now(),
            ]),
            'user_id' => fn (array $attributes) => ExportReview::query()->findOrFail($attributes['export_review_id'])->user_id,
            'source_playlist_id' => fn (array $attributes) => ExportReview::query()->findOrFail($attributes['export_review_id'])->playlist_id,
            'playlist_export_link_id' => null,
            'streaming_account_id' => null,
            'target_provider' => fn (array $attributes) => ExportReview::query()->findOrFail($attributes['export_review_id'])->target_provider,
            'destination_type' => fn (array $attributes) => ExportReview::query()->findOrFail($attributes['export_review_id'])->destination_type,
            'target_account_id' => fn (array $attributes) => ExportReview::query()->findOrFail($attributes['export_review_id'])->target_account_id,
            'target_market' => fn (array $attributes) => ExportReview::query()->findOrFail($attributes['export_review_id'])->target_market,
            'playlist_name' => 'Canary playlist',
            'playlist_description' => 'Non-production fixture data.',
            'source_fingerprint' => fn (array $attributes) => ExportReview::query()->findOrFail($attributes['export_review_id'])->source_fingerprint,
            'status' => ExportOperationStatus::Queued,
            'failure_code' => null,
            'attempt_count' => 0,
            'active_key' => function (array $attributes): string {
                $provider = $attributes['target_provider'];

                return ExportOperation::activeKey(
                    (int) $attributes['source_playlist_id'],
                    $provider instanceof StreamingProvider ? $provider : StreamingProvider::from($provider),
                    $attributes['target_account_id'],
                );
            },
            'provider_mutation_started_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'notification_sent_at' => null,
        ];
    }
}
