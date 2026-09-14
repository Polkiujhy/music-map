<?php

use App\Providers\AppServiceProvider;
use App\Providers\ExportMatchingServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PlaylistImportServiceProvider;
use App\Providers\StreamingAccountsServiceProvider;

return [
    AppServiceProvider::class,
    ExportMatchingServiceProvider::class,
    FortifyServiceProvider::class,
    PlaylistImportServiceProvider::class,
    StreamingAccountsServiceProvider::class,
];
