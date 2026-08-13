<?php

use App\Actions\NotificationIngestion\DispatchGmailSynchronizations;
use App\GmailSynchronizationType;
use App\Operations\DeploymentRehearsal;
use App\Operations\RuntimeHealth;
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

Schedule::everyMinute()
    ->name('money-assistant-minute')
    ->onOneServer()
    ->group(function () {
        Schedule::call(
            fn () => app(RuntimeHealth::class)->dispatchProbe(),
        )
            ->name('runtime-health-probe')
            ->withoutOverlapping();

        Schedule::call(
            fn () => app(DeploymentRehearsal::class)->dispatchDueScheduledProbes(),
        )
            ->name('deployment-rehearsal-probes')
            ->withoutOverlapping();

        Schedule::call(
            fn () => app(DispatchGmailSynchronizations::class)
                ->handle(GmailSynchronizationType::Incremental),
        )
            ->name('gmail-history-synchronization')
            ->withoutOverlapping();

    });

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
