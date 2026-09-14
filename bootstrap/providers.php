<?php

use App\Providers\AppServiceProvider;
use App\Providers\ExportMatchingServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PlaylistExportServiceProvider;
use App\Providers\PlaylistImportServiceProvider;
use App\Providers\StreamingAccountsServiceProvider;
use App\Providers\YouTubeWriteAdmissionServiceProvider;

return [
    AppServiceProvider::class,
    ExportMatchingServiceProvider::class,
    FortifyServiceProvider::class,
    PlaylistImportServiceProvider::class,
    PlaylistExportServiceProvider::class,
    StreamingAccountsServiceProvider::class,
    YouTubeWriteAdmissionServiceProvider::class,
];
