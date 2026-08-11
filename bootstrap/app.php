<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
