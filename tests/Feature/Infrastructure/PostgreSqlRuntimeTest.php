<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('shared runtime services use PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(config('cache.default'))->toBe('database')
        ->and(config('cache.stores.database.connection'))->toBe('pgsql')
        ->and(config('cache.stores.database.lock_connection'))->toBe('pgsql')
        ->and(config('queue.default'))->toBe('database')
        ->and(config('queue.connections.database.connection'))->toBe('pgsql')
        ->and(config('queue.batching.database'))->toBe('pgsql')
        ->and(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(config('queue.failed.database'))->toBe('pgsql')
        ->and(config('session.driver'))->toBe('database')
        ->and(config('session.connection'))->toBe('pgsql');
});

test('database cache locks coordinate competing PostgreSQL connections', function () {
    $connection = config('database.connections.pgsql');
    $databaseCacheStore = fn (string $connectionName): array => [
        'driver' => 'database',
        'connection' => $connectionName,
        'table' => 'cache',
        'lock_connection' => $connectionName,
        'lock_table' => 'cache_locks',
    ];

    config([
        'database.connections.pgsql_cache_primary' => $connection,
        'database.connections.pgsql_cache_competitor' => $connection,
        'cache.stores.database_primary' => $databaseCacheStore('pgsql_cache_primary'),
        'cache.stores.database_competitor' => $databaseCacheStore('pgsql_cache_competitor'),
    ]);

    $primaryBackend = DB::connection('pgsql_cache_primary')
        ->selectOne('select pg_backend_pid() as id');
    $competingBackend = DB::connection('pgsql_cache_competitor')
        ->selectOne('select pg_backend_pid() as id');
    $lockName = 'postgresql-concurrency-'.str()->uuid();
    $primaryLock = Cache::store('database_primary')->lock($lockName, 10);
    $competingLock = Cache::store('database_competitor')->lock($lockName, 10);

    expect($primaryBackend->id)->not->toBe($competingBackend->id)
        ->and($primaryLock->get())->toBeTrue()
        ->and($competingLock->get())->toBeFalse();

    $primaryLock->release();

    expect($competingLock->get())->toBeTrue();

    $competingLock->release();
});
