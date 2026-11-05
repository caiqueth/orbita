<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Órbita maintenance schedule
|--------------------------------------------------------------------------
|
| Queue worker (queue:work) is NOT scheduled here; it must be supervised (systemd/supervisor).
*/

Schedule::command('orbita:reconcile')
    ->hourly()
    ->withoutOverlapping(30);

Schedule::command('orbita:posts-close-inactive')
    ->daily()
    ->withoutOverlapping(30);

Schedule::command('orbita:notifications-clean')
    ->daily()
    ->withoutOverlapping(30);

Schedule::command('orbita:sitemap-generate')
    ->daily()
    ->withoutOverlapping(30);

Schedule::command('orbita:post-drafts-prune')
    ->daily()
    ->withoutOverlapping(30);
