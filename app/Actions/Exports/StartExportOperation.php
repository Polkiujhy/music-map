<?php

namespace App\Actions\Exports;

use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StartExportOperation
{
    public function handle(User $user, ExportReview $review, ConfirmedExportManifest $manifest): ExportOperation
    {
        if ((int) $review->user_id !== (int) $user->getKey()
            || $manifest->reviewId !== (int) $review->getKey()) {
            abort(404);
        }

        return DB::transaction(function () use ($user, $review, $manifest): ExportOperation {
            $locked = ExportReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->user_id !== (int) $user->getKey()
                || (int) $locked->playlist_id !== $manifest->playlistId) {
                abort(404);
            }

            if ($locked->status !== ExportReviewStatus::Confirmed) {
                throw ValidationException::withMessages(['review' => 'Tylko potwierdzony przegląd może rozpocząć eksport.']);
            }

            $source = $locked->playlist()->lockForUpdate()->firstOrFail();
            if ((int) $source->user_id !== (int) $user->getKey()) {
                abort(404);
            }
            $source->assertSource();

            if ($manifest->targetProvider !== $locked->target_provider
                || $manifest->destinationType !== $locked->destination_type
                || $manifest->targetAccountId !== $locked->target_account_id
                || $manifest->targetMarket !== $locked->target_market
                || ! hash_equals($locked->source_fingerprint, $manifest->sourceFingerprint)) {
                throw ValidationException::withMessages(['review' => 'Manifest nie odpowiada potwierdzonemu przeglądowi.']);
            }

            $existing = $locked->exportOperation()->first();
            if ($existing instanceof ExportOperation) {
                return $existing;
            }

            $activeKey = ExportOperation::activeKey(
                $manifest->playlistId,
                $manifest->targetProvider,
                $manifest->targetAccountId,
            );
            $active = ExportOperation::query()->where('active_key', $activeKey)->first();

            if ($active instanceof ExportOperation) {
                throw ValidationException::withMessages([
                    'operation' => "Eksport do tego celu już trwa (operacja {$active->operation_id}).",
                ]);
            }

            $operation = new ExportOperation([
                'operation_id' => (string) Str::uuid(),
                'target_provider' => $manifest->targetProvider,
                'destination_type' => $manifest->destinationType,
                'target_account_id' => $manifest->targetAccountId,
                'target_market' => $manifest->targetMarket,
                'playlist_name' => $manifest->playlistName,
                'playlist_description' => $manifest->playlistDescription,
                'source_fingerprint' => $manifest->sourceFingerprint,
                'status' => ExportOperationStatus::Queued,
                'attempt_count' => 0,
                'active_key' => $activeKey,
            ]);
            $operation->user()->associate($user);
            $operation->exportReview()->associate($locked);
            $operation->sourcePlaylist()->associate($source);
            if ($locked->streaming_account_id !== null) {
                $operation->streamingAccount()->associate($locked->streaming_account_id);
            }
            $operation->save();

            $operationId = $operation->operation_id;
            DB::afterCommit(static fn () => ExecuteExportOperation::dispatch($operationId));

            return $operation;
        });
    }
}
