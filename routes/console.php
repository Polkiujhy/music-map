<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('playlists:refresh-youtube-metadata')
    ->daily()
    ->withoutOverlapping();

Schedule::command('managed-exports:recover')
    ->everyMinute()
    ->withoutOverlapping(10);
