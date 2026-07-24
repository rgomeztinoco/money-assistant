<?php

use App\Jobs\RecordRuntimeHealthProbe;
use App\Operations\RuntimeHealth;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

test('the application health endpoint checks PostgreSQL', function () {
    $this->get('/up')->assertSuccessful();

    config([
        'database.default' => 'unavailable',
        'database.connections.unavailable' => [
            'driver' => 'pgsql',
            'host' => 'unavailable',
            'port' => 5432,
            'database' => 'unavailable',
            'username' => 'unavailable',
            'password' => 'unavailable',
        ],
    ]);

    Exceptions::fake();
    $response = $this->get('/up');
    config(['database.default' => 'pgsql']);

    $response->assertServerError();
    Exceptions::assertReported(QueryException::class);
});

test('the scheduler durably dispatches a runtime health probe', function () {
    Queue::fake();

    $this->artisan('schedule:run')->assertSuccessful();

    Queue::assertPushed(RecordRuntimeHealthProbe::class);
    $this->artisan('app:health-check', ['service' => 'scheduler'])
        ->assertExitCode(Command::SUCCESS);
});

test('the worker reports whether its durable runtime probe is fresh', function () {
    $this->artisan('app:health-check', ['service' => 'worker'])
        ->assertExitCode(Command::FAILURE);

    app(RecordRuntimeHealthProbe::class)->handle(app(RuntimeHealth::class));

    $this->artisan('app:health-check', ['service' => 'worker'])
        ->assertExitCode(Command::SUCCESS);

    $this->travel(3)->minutes();

    $this->artisan('app:health-check', ['service' => 'worker'])
        ->assertExitCode(Command::FAILURE);
});

test('runtime health checks reject unknown services', function () {
    $this->artisan('app:health-check', ['service' => 'unknown'])
        ->assertExitCode(Command::INVALID);
});
