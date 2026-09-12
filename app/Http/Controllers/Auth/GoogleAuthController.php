<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\Auth\IdentityConflictException;
use App\Http\Controllers\Controller;
use App\Services\Auth\ResolveGoogleIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, ResolveGoogleIdentity $resolver): RedirectResponse
    {
        if (! $this->hasValidState($request)) {
            return $this->denied();
        }

        if ($request->filled('error')) {
            $request->session()->forget('state');

            return $this->denied();
        }

        try {
            $googleUser = Socialite::driver('google')->user();
            $raw = $googleUser->getRaw();
            $emailVerified = ($raw['email_verified'] ?? null) === true
                || ($raw['verified_email'] ?? null) === true;
            $subject = trim((string) $googleUser->getId());
            $name = trim((string) $googleUser->getName());
            $email = trim((string) $googleUser->getEmail());

            if (! $emailVerified || $subject === '' || $name === '' || $email === '') {
                return $this->denied();
            }

            $user = $resolver->resolve($subject, $name, $email);

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('bank.index');
        } catch (IdentityConflictException) {
            return $this->denied();
        } catch (Throwable) {
            return $this->denied();
        } finally {
            $request->session()->forget('state');
        }
    }

    private function hasValidState(Request $request): bool
    {
        $expected = $request->session()->get('state');
        $received = $request->query('state');

        if (! is_string($expected) || $expected === '' || ! is_string($received) || $received === '') {
            $request->session()->forget('state');

            return false;
        }

        if (! hash_equals($expected, $received)) {
            $request->session()->forget('state');

            return false;
        }

        return true;
    }

    private function denied(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'google' => 'Nie udało się bezpiecznie zalogować przez Google. Spróbuj ponownie.',
        ]);
    }
}
