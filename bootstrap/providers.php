<?php

use App\Providers\AppServiceProvider;
use App\Providers\ExportMatchingServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ManagedAccountExportServiceProvider;
use App\Providers\PlaylistImportServiceProvider;
use App\Providers\StreamingAccountsServiceProvider;
use App\Providers\YouTubeWriteAdmissionServiceProvider;

return [
    AppServiceProvider::class,
    ExportMatchingServiceProvider::class,
    FortifyServiceProvider::class,
    ManagedAccountExportServiceProvider::class,
    PlaylistImportServiceProvider::class,
    StreamingAccountsServiceProvider::class,
    YouTubeWriteAdmissionServiceProvider::class,
];
