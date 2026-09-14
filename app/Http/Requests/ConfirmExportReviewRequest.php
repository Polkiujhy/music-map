<?php

namespace App\Http\Requests;

use App\Enums\ExportReviewDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfirmExportReviewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $playlist = $this->user()?->playlists()->sourceOnly()->whereKey($this->route('playlist'))->firstOrFail();
        $this->user()?->exportReviews()
            ->where('playlist_id', $playlist?->getKey())
            ->whereKey($this->route('exportReview'))
            ->firstOrFail();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'decisions' => ['present', 'array'],
            'decisions.*' => [Rule::enum(ExportReviewDecision::class)],
        ];
    }

    /** @return array<int|string, string> */
    public function decisions(): array
    {
        return $this->validated('decisions', []);
    }
}
