<?php

namespace App\Actions\PlaylistSync;

use App\Enums\PlaylistSyncDirection;

final class DeterminePlaylistSyncDirection
{
    public function handle(
        ?string $baselineBankFingerprint,
        ?string $baselineSourceFingerprint,
        string $currentBankFingerprint,
        string $currentSourceFingerprint,
    ): ?PlaylistSyncDirection {
        if ($baselineBankFingerprint === null || $baselineSourceFingerprint === null) {
            return null;
        }

        $sourceChanged = ! hash_equals($baselineSourceFingerprint, $currentSourceFingerprint);
        $bankChanged = ! hash_equals($baselineBankFingerprint, $currentBankFingerprint);

        if ($sourceChanged) {
            return PlaylistSyncDirection::Pull;
        }

        if ($bankChanged) {
            return PlaylistSyncDirection::Push;
        }

        return PlaylistSyncDirection::NoOp;
    }
}
