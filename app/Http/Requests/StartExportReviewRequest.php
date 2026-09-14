<?php

namespace App\Http\Requests;

use App\Enums\ExportDestinationType;
use App\Enums\StreamingProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartExportReviewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->user()?->playlists()->whereKey($this->route('playlist'))->firstOrFail();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'target_provider' => ['required', Rule::enum(StreamingProvider::class)],
            'destination_type' => ['required', Rule::enum(ExportDestinationType::class)],
            'streaming_account_id' => [
                Rule::requiredIf(fn (): bool => $this->input('destination_type') === ExportDestinationType::Linked->value),
                'nullable',
                'integer',
            ],
        ];
    }

    public function provider(): StreamingProvider
    {
        return StreamingProvider::from($this->string('target_provider')->toString());
    }

    public function destinationType(): ExportDestinationType
    {
        return ExportDestinationType::from($this->string('destination_type')->toString());
    }

    public function streamingAccountId(): ?int
    {
        return $this->filled('streaming_account_id') ? $this->integer('streaming_account_id') : null;
    }
}
