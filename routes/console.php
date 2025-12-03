<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

Schedule::command('app:data-cleanup')->daily();
Schedule::command('app:sync-inv-query')->everyFiveMinutes();

// DWP scheduled commands
Schedule::command('app:ins-dwp-reset')->dailyAt('07:30');
