<?php

use App\Actions\NotificationIngestion\DispatchGmailSynchronizations;
use App\GmailSynchronizationType;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::useCache('database');

Schedule::call(
    fn () => app(DispatchGmailSynchronizations::class)
        ->handle(GmailSynchronizationType::Reconciliation),
)
    ->dailyAt('00:00')
    ->name('gmail-seven-day-reconciliation')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::call(
    fn () => app(DispatchGmailSynchronizations::class)
        ->handle(GmailSynchronizationType::Incremental),
)
    ->cron(GmailSynchronizationType::INCREMENTAL_SCHEDULE)
    ->name('gmail-history-synchronization')
    ->onOneServer()
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
