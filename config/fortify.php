<?php

use App\Http\Middleware\NormalizeAuthEmail;
use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web', NormalizeAuthEmail::class],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/bank',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => true,
    'limiters' => [
        'login' => 'login',
        'passkeys' => null,
    ],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
    ],
];
