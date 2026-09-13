<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ImportPlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'playlist_url' => ['required', 'string', 'max:512'],
            'policy_consent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'playlist_url.required' => 'Wklej link do playlisty.',
            'playlist_url.max' => 'Link do playlisty jest zbyt długi.',
            'policy_consent.accepted' => 'Zaakceptuj Warunki korzystania i Politykę prywatności przed importem.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $response = redirect($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag);

        throw new ValidationException($validator, $response);
    }
}
