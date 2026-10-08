<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('instagram:refresh-token')->weekly();

Schedule::command('reel:daily')->dailyAt(config('reels.daily_at'))->timezone('Asia/Kolkata')->withoutOverlapping(60)->runInBackground();
Schedule::command('reel:telegram')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('autodm:run')->everyMinute()->withoutOverlapping(5)->runInBackground();
