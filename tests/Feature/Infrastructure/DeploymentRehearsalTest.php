<?php

use App\Actions\Operations\RunCrashRecoveryFinancialRehearsal;
use App\Jobs\CompleteDeploymentRehearsalProbe;
use App\Models\Transaction;
use App\Models\TransactionStateChange;
use App\Models\User;
use App\Operations\DeploymentRehearsal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('restart probes are prepared without contacting external recipients', function () {
    Http::fake();
    Mail::fake();
    Notification::fake();
    Queue::fake();
    $rehearsalId = (string) Str::uuid();

    $this->artisan('app:deployment-rehearsal:prepare', ['rehearsal' => $rehearsalId])
        ->assertExitCode(Command::SUCCESS);

    expect(DB::table('deployment_rehearsal_probes')->where('rehearsal_id', $rehearsalId)->count())
        ->toBe(2);
    Queue::assertPushed(CompleteDeploymentRehearsalProbe::class, 1);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('queued and scheduled restart probes each complete exactly once', function () {
    config()->set('cache.default', 'array');
    Queue::fake();
    Schedule::useCache('array');
    $rehearsalId = (string) Str::uuid();

    $this->artisan('app:deployment-rehearsal:prepare', ['rehearsal' => $rehearsalId])
        ->assertSuccessful();

    $queuedProbeId = (string) DB::table('deployment_rehearsal_probes')
        ->where('rehearsal_id', $rehearsalId)
        ->where('kind', 'queued')
        ->value('id');

    $queuedProbe = new CompleteDeploymentRehearsalProbe($queuedProbeId);
    $runCrashRecoveryFinancialRehearsal = app(RunCrashRecoveryFinancialRehearsal::class);
    $queuedProbe->handle(app(DeploymentRehearsal::class), $runCrashRecoveryFinancialRehearsal);
    $queuedProbe->handle(app(DeploymentRehearsal::class), $runCrashRecoveryFinancialRehearsal);

    $this->artisan('schedule:run')->assertSuccessful();
    $this->artisan('schedule:run')->assertSuccessful();

    $scheduledProbeId = (string) DB::table('deployment_rehearsal_probes')
        ->where('rehearsal_id', $rehearsalId)
        ->where('kind', 'scheduled')
        ->value('id');

    Queue::assertPushed(
        CompleteDeploymentRehearsalProbe::class,
        fn (CompleteDeploymentRehearsalProbe $probe): bool => $probe->probeId === $scheduledProbeId,
    );

    $scheduledProbe = new CompleteDeploymentRehearsalProbe($scheduledProbeId);
    $scheduledProbe->handle(app(DeploymentRehearsal::class), $runCrashRecoveryFinancialRehearsal);
    $scheduledProbe->handle(app(DeploymentRehearsal::class), $runCrashRecoveryFinancialRehearsal);

    expect(
        DB::table('deployment_rehearsal_probes')
            ->where('rehearsal_id', $rehearsalId)
            ->orderBy('kind')
            ->pluck('completion_count')
            ->all(),
    )->toBe([1, 1]);

    $this->artisan('app:deployment-rehearsal:verify', ['rehearsal' => $rehearsalId])
        ->assertExitCode(Command::SUCCESS);
});

test('restart probe verification rejects incomplete rehearsals', function () {
    Queue::fake();
    $rehearsalId = (string) Str::uuid();

    $this->artisan('app:deployment-rehearsal:prepare', ['rehearsal' => $rehearsalId])
        ->assertSuccessful();

    $this->artisan('app:deployment-rehearsal:verify', ['rehearsal' => $rehearsalId])
        ->assertExitCode(Command::FAILURE);
});

test('a crash rehearsal exposes a durable in-flight probe and completes once after redelivery', function () {
    Queue::fake();
    Storage::fake('local');
    config()->set('app.deployment_rehearsal_crash_hold_seconds', 0);
    User::factory()->create();
    $rehearsalId = (string) Str::uuid();

    $this->artisan('app:deployment-rehearsal:prepare', [
        'rehearsal' => $rehearsalId,
        '--hold-for-crash' => true,
    ])->assertSuccessful();

    Queue::assertPushed(CompleteDeploymentRehearsalProbe::class, function (CompleteDeploymentRehearsalProbe $probe): bool {
        $deploymentRehearsal = app(DeploymentRehearsal::class);
        Storage::disk('local')->assertExists($deploymentRehearsal->crashHoldMarker($probe->probeId));

        $probe->handle(
            $deploymentRehearsal,
            app(RunCrashRecoveryFinancialRehearsal::class),
        );
        $probe->handle(
            $deploymentRehearsal,
            app(RunCrashRecoveryFinancialRehearsal::class),
        );

        Storage::disk('local')->assertExists($deploymentRehearsal->crashStartedMarker($probe->probeId));

        return true;
    });

    $rehearsalTransaction = Transaction::query()
        ->where('deployment_rehearsal_id', $rehearsalId)
        ->sole();

    expect(DB::table('deployment_rehearsal_probes')
        ->where('rehearsal_id', $rehearsalId)
        ->where('kind', 'queued')
        ->value('completion_count'))->toBe(1)
        ->and($rehearsalTransaction->merchant_description)->toBe('Production trust crash rehearsal')
        ->and($rehearsalTransaction->voided_at)->not->toBeNull()
        ->and(TransactionStateChange::query()
            ->whereBelongsTo($rehearsalTransaction, 'transaction')
            ->count())->toBe(1);
});
