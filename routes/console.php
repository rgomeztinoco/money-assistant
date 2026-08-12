<?php

use App\Actions\NotificationIngestion\DispatchGmailSynchronizations;
use App\Actions\Reminders\DispatchPendingReminderDeliveries;
use App\Actions\Reminders\EnqueueDueReminderDeliveries;
use App\Actions\Reporting\DiscoverMissingDailyExchangeRates;
use App\Actions\Reporting\DispatchPendingDailyExchangeRateSeeds;
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
            fn () => app(DiscoverMissingDailyExchangeRates::class)->handle(),
        )
            ->name('daily-exchange-rate-discovery')
            ->withoutOverlapping();

        Schedule::call(
            fn () => app(DispatchPendingDailyExchangeRateSeeds::class)->handle(),
        )
            ->name('daily-exchange-rate-seeds')
            ->withoutOverlapping();

        Schedule::call(
            fn () => app(DispatchGmailSynchronizations::class)
                ->handle(GmailSynchronizationType::Incremental),
        )
            ->name('gmail-history-synchronization')
            ->withoutOverlapping();

        Schedule::call(
            fn () => app(EnqueueDueReminderDeliveries::class)->handle(),
        )
            ->name('reminder-outbox')
            ->withoutOverlapping();

        Schedule::call(
            fn () => app(DispatchPendingReminderDeliveries::class)->handle(),
        )
            ->name('reminder-deliveries')
            ->withoutOverlapping();
    });

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
