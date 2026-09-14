<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmPlaylistSynchronizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string', 'size:64'],
        ];
    }

    public function previewToken(): string
    {
        return (string) $this->validated('preview_token');
    }
}
