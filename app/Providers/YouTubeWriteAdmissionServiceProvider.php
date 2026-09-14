<?php

namespace App\Providers;

use App\Integrations\YouTubeWriteAdmission\Actions\ReserveYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use Illuminate\Support\ServiceProvider;

final class YouTubeWriteAdmissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AdmitYouTubeWrite::class, ReserveYouTubeWrite::class);
    }
}
