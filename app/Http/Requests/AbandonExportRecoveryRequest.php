<?php

namespace App\Http\Requests;

use App\Models\ExportOperation;
use Illuminate\Foundation\Http\FormRequest;

final class AbandonExportRecoveryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->ownedOperation();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'orphan_copies_checked' => ['required', 'accepted'],
        ];
    }

    public function ownedOperation(): ExportOperation
    {
        $playlist = $this->user()?->playlists()
            ->sourceOnly()
            ->whereKey($this->route('playlist'))
            ->firstOrFail() ?? abort(404);

        return $this->user()?->exportOperations()
            ->where('source_playlist_id', $playlist->getKey())
            ->where('operation_id', $this->route('exportOperation'))
            ->firstOrFail() ?? abort(404);
    }
}
