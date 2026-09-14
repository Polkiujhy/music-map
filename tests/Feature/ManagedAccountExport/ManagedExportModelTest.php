<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\PlaylistOrigin;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\StreamingAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ManagedExportModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_enums_and_models_expose_the_durable_export_contract(): void
    {
        $this->assertSame(['imported', 'managed_target'], array_column(PlaylistOrigin::cases(), 'value'));
        $this->assertSame([
            'queued', 'processing', 'succeeded', 'failed', 'partial_failed',
            'manual_recovery_required', 'recreate_required',
        ], array_column(ExportOperationStatus::cases(), 'value'));
        $this->assertSame([
            'configuration-unavailable', 'authentication-required', 'required-scope-missing',
            'account-mismatch', 'refresh-rotation-required', 'rate-limited', 'quota-exceeded',
            'transport-unavailable', 'invalid-response', 'target-missing', 'target-owner-mismatch',
            'target-marker-mismatch', 'target-visibility-mismatch', 'metadata-rejected',
            'item-rejected', 'ambiguous-mutation', 'persistence-failure',
        ], array_column(ManagedExportFailureCode::cases(), 'value'));

        $user = User::factory()->create();
        $source = Playlist::factory()->for($user)->create();
        $target = Playlist::factory()->for($user)->create([
            'origin' => PlaylistOrigin::ManagedTarget,
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => 'managed-target',
            'source_account_id' => 'technical-owner',
        ]);
        $account = StreamingAccount::factory()->for($user)->spotify()->create();
        $review = ExportReview::factory()->for($user)->for($source)->create([
            'streaming_account_id' => $account->id,
            'target_account_id' => 'technical-owner',
        ]);
        $playlistExport = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'streaming_account_id' => $account->id,
            'target_account_id' => 'technical-owner',
            'target_playlist_id' => $target->id,
        ]);
        $operation = ExportOperation::factory()->for($user)->for($review, 'exportReview')
            ->for($playlistExport)->create([
                'status' => ExportOperationStatus::PartialFailed,
                'failure_code' => ManagedExportFailureCode::ItemRejected,
                'attempt_generation' => 2,
                'retry_generation' => 1,
                'automatic_claim_count' => 2,
                'heartbeat_at' => now(),
                'retry_available_at' => now()->addMinute(),
            ]);

        $this->assertSame(PlaylistOrigin::Imported, $source->origin);
        $this->assertTrue($target->isManagedTarget());
        $this->assertSame($source->id, $playlistExport->sourcePlaylist->id);
        $this->assertSame($target->id, $playlistExport->targetPlaylist->id);
        $this->assertSame($account->id, $playlistExport->streamingAccount->id);
        $this->assertTrue($source->managedExports->contains($playlistExport));
        $this->assertSame($playlistExport->id, $target->managedExportTarget->id);
        $this->assertSame(StreamingProvider::Spotify, $playlistExport->target_provider);
        $this->assertSame(ExportDestinationType::Managed, $playlistExport->destination_type);
        $this->assertSame(ExportOperationStatus::PartialFailed, $operation->status);
        $this->assertSame(ManagedExportFailureCode::ItemRejected, $operation->failure_code);
        $this->assertInstanceOf(CarbonImmutable::class, $operation->heartbeat_at);
        $this->assertSame($operation->id, $review->exportOperation->id);
        $this->assertTrue($user->exportOperations->contains($operation));
        $this->assertTrue($playlistExport->operations->contains($operation));
        $this->assertLessThanOrEqual(255, strlen($operation->id));
    }

    public function test_target_attempt_generations_keep_history_and_copy_the_canonical_owner_identity(): void
    {
        $playlistExport = PlaylistExport::factory()->create(['target_generation' => 2]);
        $first = PlaylistExportTargetAttempt::factory()->for($playlistExport)->resolved()->create([
            'generation' => 1,
            'status' => PlaylistExportTargetAttempt::STATUS_ABANDONED,
        ]);
        $second = PlaylistExportTargetAttempt::factory()->for($playlistExport)->create([
            'generation' => 2,
            'status' => PlaylistExportTargetAttempt::STATUS_UNKNOWN,
            'create_started_at' => now(),
        ]);

        $this->assertSame([1, 2], $playlistExport->targetAttempts->pluck('generation')->all());
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_ABANDONED, $first->fresh()->status);
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_UNKNOWN, $second->fresh()->status);
        $this->assertNotSame($first->marker, $second->marker);
        $this->assertSame($playlistExport->target_provider, $second->target_provider);
        $this->assertSame($playlistExport->target_account_id, $second->target_account_id);
        $this->assertNull($second->provider_playlist_id);

        try {
            DB::transaction(
                fn () => PlaylistExportTargetAttempt::factory()->for($playlistExport)->create(['generation' => 2]),
            );
            $this->fail('A target generation must be unique within its export.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_saved_locators_and_attempt_identity_are_write_once(): void
    {
        $target = Playlist::factory()->create(['source_playlist_id' => 'first-target']);
        $replacement = Playlist::factory()->create(['source_playlist_id' => 'replacement-target']);
        $playlistExport = PlaylistExport::factory()->create(['target_playlist_id' => $target->id]);
        $attempt = PlaylistExportTargetAttempt::factory()->for($playlistExport)->resolved()->create();

        try {
            $playlistExport->update(['target_playlist_id' => $replacement->id]);
            $this->fail('The stable managed playlist locator must not be replaced during retry.');
        } catch (LogicException $exception) {
            $this->assertSame('The managed target playlist locator is write-once.', $exception->getMessage());
        }

        foreach ([
            ['provider_playlist_id' => 'replacement-provider-id'],
            ['canonical_url' => 'https://example.test/replacement'],
            ['generation' => 99],
            ['marker' => '00000000-0000-4000-8000-000000000099'],
        ] as $change) {
            $attempt = $attempt->fresh();

            try {
                $attempt->update($change);
                $this->fail('Attempt identity and locators must remain immutable.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_operation_is_unique_per_review_and_persistence_has_no_secret_or_raw_payload_fields(): void
    {
        $user = User::factory()->create();
        $source = Playlist::factory()->for($user)->create();
        $review = ExportReview::factory()->for($user)->for($source)->create();
        $playlistExport = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create();
        ExportOperation::factory()->for($user)->for($review, 'exportReview')->for($playlistExport)->create();

        try {
            DB::transaction(
                fn () => ExportOperation::factory()->for($user)->for($review, 'exportReview')->for($playlistExport)->create(),
            );
            $this->fail('A confirmed review may have only one durable operation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $columns = array_column(
            (new ExportOperation)->getConnection()->getSchemaBuilder()->getColumns('export_operations'),
            'name',
        );
        $attributes = array_keys(ExportOperation::query()->firstOrFail()->getAttributes());
        $serialized = json_encode(ExportOperation::query()->firstOrFail(), JSON_THROW_ON_ERROR);

        foreach (['access_token', 'refresh_token', 'manifest', 'payload', 'raw_response'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
            $this->assertNotContains($forbidden, $attributes);
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }
}
