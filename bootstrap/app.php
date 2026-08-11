<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
             * Readiness, as opposed to the liveness check Laravel registers at
             * /up. The two answer different questions and are deliberately kept
             * apart:
             *
             *   /up        did the application boot? No middleware, no
             *              database. Used by the container health check, which
             *              should restart a process that has genuinely died.
             *   /up/ready  can it actually serve a request? Reaches the
             *              database. Used by the load balancer, which should
             *              stop sending traffic to a task that cannot answer.
             *
             * Without this distinction a task pointed at an empty or
             * unreachable database reports healthy and returns 500 to real
             * users: /up never touches the database, and the DiagnosingHealth
             * event it dispatches has no listeners in this application.
             *
             * Registered here rather than in routes/web.php on purpose. Routes
             * in that file run through the web middleware group, which starts a
             * session, and the session driver is the database — so a health
             * check placed there would write a session row on every poll and
             * fail for reasons unrelated to readiness.
             */
            Route::get('/up/ready', function () {
                try {
                    // A round trip, not merely a connection object. getPdo()
                    // can hand back a cached handle without touching the server.
                    DB::connection()->select('select 1');
                } catch (Throwable $exception) {
                    report($exception);

                    return response()->json(['status' => 'not ready'], 503);
                }

                return response()->json(['status' => 'ready']);
            })->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Trust the load balancer's X-Forwarded-* headers.
         *
         * In production the application is served by an AWS Application Load
         * Balancer which terminates TLS, so every request reaches the container
         * over plain HTTP. Without this, Laravel sees http:// and the wrong
         * client address: secure cookies are never set, generated URLs and
         * redirects point at http://, and anything reading the client IP sees
         * the load balancer instead.
         *
         * Trusting '*' rather than a fixed CIDR is the usual pattern on ECS.
         * ALB nodes take arbitrary addresses from the VPC subnets and change
         * without notice, so there is no stable range to pin. This is only safe
         * because the load balancer is the sole route to the container: the
         * task runs in a private subnet with no public address, so nothing can
         * reach it directly to forge these headers. That property is enforced
         * by the network topology, not by the application, and it is a
         * prerequisite for this setting rather than an assumption about it.
         *
         * The header set is Laravel's default, which already covers the ELB
         * variant, so it is deliberately not overridden here.
         */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
