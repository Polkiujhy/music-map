<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlaylistSynchronizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->user()?->playlists()->whereKey($this->route('playlist'))->firstOrFail();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'automatic_enabled' => ['required', 'boolean'],
        ];
    }

    public function automaticEnabled(): bool
    {
        return (bool) $this->validated('automatic_enabled');
    }
}
