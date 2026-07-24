<?php

use App\Operations\RuntimeHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::useCache('database');

Schedule::call(
    fn () => app(RuntimeHealth::class)->dispatchProbe(),
)
    ->name('runtime-health-probe')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
