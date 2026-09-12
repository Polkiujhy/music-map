<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\IdentityConflictException;
use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ResolveGoogleIdentity
{
    private const PROVIDER = 'google';

    public function resolve(string $subject, string $name, string $email): User
    {
        $subject = trim($subject);
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($subject === '' || $name === '' || $email === '') {
            throw IdentityConflictException::forGoogle();
        }

        try {
            return $this->resolveAttempt($subject, $name, $email);
        } catch (UniqueConstraintViolationException) {
            try {
                return $this->resolveAttempt($subject, $name, $email);
            } catch (UniqueConstraintViolationException $exception) {
                throw IdentityConflictException::forGoogle($exception);
            }
        }
    }

    protected function resolveAttempt(string $subject, string $name, string $email): User
    {
        return DB::transaction(function () use ($subject, $name, $email): User {
            $identity = AuthIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_user_id', $subject)
                ->lockForUpdate()
                ->first();

            $emailUser = User::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($identity !== null) {
                if ($emailUser !== null && $emailUser->isNot($identity->user)) {
                    throw IdentityConflictException::forGoogle();
                }

                return $identity->user;
            }

            if ($emailUser !== null) {
                $hasAnotherGoogleIdentity = $emailUser->authIdentities()
                    ->where('provider', self::PROVIDER)
                    ->lockForUpdate()
                    ->exists();

                if ($hasAnotherGoogleIdentity) {
                    throw IdentityConflictException::forGoogle();
                }

                if (! $emailUser->hasVerifiedEmail()) {
                    $emailUser->markEmailAsVerified();
                }

                $emailUser->authIdentities()->create([
                    'provider' => self::PROVIDER,
                    'provider_user_id' => $subject,
                ]);

                return $emailUser;
            }

            $user = new User;
            $user->name = $name;
            $user->email = $email;
            $user->email_verified_at = now();
            $user->password = null;
            $user->save();

            $user->authIdentities()->create([
                'provider' => self::PROVIDER,
                'provider_user_id' => $subject,
            ]);

            return $user;
        });
    }
}
