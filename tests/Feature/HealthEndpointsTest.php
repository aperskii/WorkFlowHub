<?php

use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Liveness: /up
|--------------------------------------------------------------------------
|
| Laravel's own health route. It answers "did the application boot" and must
| stay independent of the database, because the container health check uses it
| to decide whether to restart the process. Restarting a container because
| PostgreSQL is briefly unreachable would turn a database blip into an outage.
|
*/

test('the liveness endpoint reports up', function () {
    $this->get('/up')
        ->assertOk()
        ->assertSee('Application up');
});

test('the liveness endpoint does not require a session', function () {
    // It is registered outside the web middleware group, so no session cookie
    // is issued. With the database session driver, a session here would mean a
    // row written on every poll.
    $response = $this->get('/up');

    expect($response->headers->getCookies())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Readiness: /up/ready
|--------------------------------------------------------------------------
|
| Answers "can it actually serve a request", which for this application means
| reaching the database. The load balancer uses this to decide whether to send
| traffic, so it must fail while /up keeps succeeding.
|
*/

test('the readiness endpoint reports ready when the database answers', function () {
    $this->get('/up/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ready']);
});

test('the readiness endpoint reports not ready when the database is unreachable', function () {
    // Exceptions are reported rather than swallowed, so the log is silenced
    // here to keep the expected failure out of the test output.
    Log::spy();

    // A connection that cannot succeed: nothing listens on port 1. Pointed at
    // through the default connection name rather than by purging the existing
    // one, so the transaction RefreshDatabase opened stays intact and can still
    // be rolled back afterwards.
    config([
        'database.connections.unreachable' => array_merge(
            config('database.connections.pgsql'),
            ['host' => '127.0.0.1', 'port' => 1],
        ),
        'database.default' => 'unreachable',
    ]);

    try {
        $this->get('/up/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'not ready']);
    } finally {
        config(['database.default' => 'pgsql']);
    }
});

test('an unreachable database does not bring down the liveness endpoint', function () {
    Log::spy();

    config([
        'database.connections.unreachable' => array_merge(
            config('database.connections.pgsql'),
            ['host' => '127.0.0.1', 'port' => 1],
        ),
        'database.default' => 'unreachable',
    ]);

    try {
        // The distinction the two endpoints exist for: the load balancer stops
        // sending traffic, but the container is not restarted.
        $this->get('/up/ready')->assertStatus(503);
        $this->get('/up')->assertOk();
    } finally {
        config(['database.default' => 'pgsql']);
    }
});
