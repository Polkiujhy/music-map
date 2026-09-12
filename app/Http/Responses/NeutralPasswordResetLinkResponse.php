<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    /**
     * Return the same acknowledgement whether or not the address has an account.
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $message = __('passwords.sent');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message])
            : back()->with('status', $message);
    }
}
