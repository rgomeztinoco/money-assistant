<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('Reminder and integration incident application surfaces are absent', function () {
    Artisan::call('schedule:list');
    $schedule = Artisan::output();

    foreach ([
        'app/Jobs/DeliverReminder.php',
        'app/Models/IntegrationIncident.php',
        'app/Models/Reminder.php',
        'app/Models/ReminderDelivery.php',
        'app/Models/ReminderLifecycleEvent.php',
    ] as $removedPath) {
        expect(file_exists(base_path($removedPath)))->toBeFalse();
    }

    expect(Route::has('integration_incidents.acknowledgement.store'))->toBeFalse()
        ->and(Route::has('integration_incidents.replay.store'))->toBeFalse()
        ->and($schedule)->not->toContain(
            'reminder',
            'incident',
            'exchange-rate',
            'ai-',
        );
});

test('Reminder and integration incident persistence is absent', function () {
    expect(Schema::hasTable('reminders'))->toBeFalse()
        ->and(Schema::hasTable('reminder_deliveries'))->toBeFalse()
        ->and(Schema::hasTable('reminder_lifecycle_events'))->toBeFalse()
        ->and(Schema::hasTable('integration_incidents'))->toBeFalse()
        ->and(Schema::hasColumns('gmail_message_discoveries', [
            'processing_failed_at',
            'last_error_code',
            'failed_job_uuid',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('gmail_connections', [
            'last_synchronization_failed_at',
            'last_synchronization_error_code',
        ]))->toBeTrue();
});
