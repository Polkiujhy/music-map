<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\StreamingAccountsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    StreamingAccountsServiceProvider::class,
];
