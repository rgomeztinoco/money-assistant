<?php

use App\Jobs\CompleteDeploymentRehearsalProbe;
use App\Operations\DeploymentRehearsal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
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
    $queuedProbe->handle(app(DeploymentRehearsal::class));
    $queuedProbe->handle(app(DeploymentRehearsal::class));

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
    $scheduledProbe->handle(app(DeploymentRehearsal::class));
    $scheduledProbe->handle(app(DeploymentRehearsal::class));

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
