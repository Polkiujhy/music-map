<?php

namespace Database\Factories;

use App\Enums\ExportOperationStatus;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\PlaylistExport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExportOperation> */
class ExportOperationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ExportOperation $operation): void {
            $source = $operation->playlistExport?->sourcePlaylist;
            if (! array_key_exists('playlist_name', $operation->getAttributes())) {
                $operation->playlist_name = $source?->name;
                $operation->playlist_description = $source?->description;
            }
            if ($operation->export_review_id !== null && $operation->user_id === null) {
                $operation->user_id = ExportReview::query()->findOrFail($operation->export_review_id)->user_id;
            }
        });
    }

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'export_review_id' => ExportReview::factory(),
            'playlist_export_id' => PlaylistExport::factory(),
            'status' => ExportOperationStatus::Queued,
            'failure_code' => null,
            'attempt_generation' => 0,
            'retry_generation' => 0,
            'automatic_claim_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'heartbeat_at' => null,
            'job_publication_lease_until' => null,
            'job_published_at' => null,
            'possible_mutation_at' => null,
            'retry_available_at' => null,
            'manual_recovery_requested_at' => null,
            'notification_generation' => null,
            'notification_sent_at' => null,
        ];
    }
}
