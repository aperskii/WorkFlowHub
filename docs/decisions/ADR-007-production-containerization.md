# ADR-007: Production Containerization and Configuration

## Status

Accepted

## Date

2026-08-11

## Context

Until now the application has only ever run on a developer's machine. `compose.yaml`
containerizes PostgreSQL and Mailpit; the application itself runs on the host through
`php artisan serve` and `npm run dev`.

Deploying to AWS ECS requires a container image of the application. This record covers
the image and the configuration it expects, taken as preparation for the infrastructure
work rather than as part of it. No Terraform, no AWS resources, and no CI/CD pipeline
exist yet, and nothing here depends on them being written a particular way — with one
exception, recorded in §6.

The local development flow is unchanged. `compose.yaml` was deliberately not touched.

## Decisions

### 1. One Container Running PHP-FPM and Nginx Under supervisord

The image runs two long-lived processes, PHP-FPM and Nginx, supervised by supervisord.

The alternative of one process per container is the more orthodox reading of the
"one concern per container" guidance, but it buys nothing here. Nginx and PHP-FPM for a
single application are scaled together, deployed together, and useless apart. Splitting
them means two task definitions, a shared volume for the application code so Nginx can
serve `public/`, and a network hop between them — cost with no corresponding benefit at
this size.

Laravel Octane and FrankenPHP were both rejected for this phase. They are faster, but
they hold application state between requests, which changes the failure modes the code
has to defend against. The application has never run under a resident worker model and
nothing has been written or tested with that in mind. Adopting it at the same time as
first deployment would confuse two independent sources of risk.

### 2. Multi-Stage Build, With Neither Composer Nor Node in the Runtime Layer

Three stages: `composer:2` resolves PHP dependencies, `node:24-bookworm-slim` builds the
Vite assets, and `php:8.5-fpm-alpine` is the runtime that receives the output of both.

The runtime image contains no package manager, no compiler toolchain, no development
dependencies, and no test suite. Build tooling that never ships cannot be exploited and
does not need patching.

The asset stage is Debian rather than Alpine because `package.json` pins the
`linux-x64-gnu` builds of Rollup, Lightning CSS, and the Tailwind oxide binary. Those are
glibc artifacts and do not run against musl. This costs nothing in the final image, since
only the built assets are copied out of that stage.

The asset stage also receives `.gitignore`. Tailwind v4's automatic source detection skips
whatever git ignores; without that file it also walks `vendor/`, producing a different and
larger stylesheet inside the image than the one built and reviewed locally.

`php:8.5-fpm-alpine` already enables every extension Composer requires. Only `pdo_pgsql`
is added. Its runtime shared library, `libpq`, is installed as a package in its own right
rather than being allowed to arrive as a transitive dependency of the build tooling, which
is removed again once the extension is compiled.

### 3. The Container Runs As a Non-Root User

Every process — supervisord, Nginx, and PHP-FPM — runs as `www-data`. Nginx listens on
8080 so no privileged port is bound, and its PID file, temporary directories, and logs are
relocated to paths the unprivileged user owns.

### 4. Laravel's Caches Are Built At Container Start, Not At Image Build

`config:cache` freezes the value of every environment variable at the moment it runs.
Running it during the image build would bake in either meaningless placeholders or, if
real values were supplied to the build, secrets in an image layer. The entrypoint
therefore caches configuration, routes, and views on each boot.

The cost is a few hundred milliseconds of start-up. The alternative is an image whose
correctness depends on where it was built.

### 5. A Missing `APP_KEY` Stops the Container

The entrypoint exits non-zero when `APP_KEY` is empty or unset.

Without it every encrypted payload fails: sessions cannot be decrypted, so nobody can log
in, and signed URLs cannot be verified. None of that is visible at boot. The application
starts, the health endpoint answers, the container reports itself healthy, and the failure
only appears when a real user tries to do something. Failing at start-up turns a
confusing runtime fault into an obvious deployment one.

### 6. All Proxies Are Trusted, Which Depends On the Network Topology

`bootstrap/app.php` calls `trustProxies(at: '*')` with Laravel's default header set, which
already covers the ELB variant.

Behind an Application Load Balancer that terminates TLS, every request reaches the
container over plain HTTP. Without trusting the forwarded headers Laravel sees `http://`
and the load balancer's address rather than the client's: secure cookies are never set,
generated URLs and redirects point at `http://`, and anything reading the client IP reads
the wrong one.

A fixed CIDR would be preferable to `'*'` but is not available. ALB nodes take arbitrary
addresses from the VPC subnets and change without notice, so there is no stable range to
pin. This is the usual pattern for ECS behind an ALB.

**This decision has a prerequisite that the application cannot enforce.** Trusting every
proxy is only safe while the load balancer is the sole route to the container. If the task
were ever reachable directly, a client could forge `X-Forwarded-For` and
`X-Forwarded-Proto` at will. The infrastructure work must therefore place the task in a
private subnet with no public address and no ingress except from the load balancer's
security group. If that topology changes, this setting has to be revisited.

### 7. Production Configuration Is Documented In `.env.example`, Not In a Committed File

No `.env.production` exists. Secrets do not belong in version control, and a file of
placeholder values invites someone to deploy the placeholders.

Instead `.env.example` carries a `Production:` comment beside every key whose value must
differ, stating what production needs and why. The keys that must change are `APP_ENV`,
`APP_DEBUG`, `APP_URL`, `APP_KEY`, `AUTH_AUTO_VERIFY_NEW_USERS`, and
`SESSION_SECURE_COOKIE`.

Two of those are security-relevant rather than cosmetic. `AUTH_AUTO_VERIFY_NEW_USERS` is a
local convenience that marks new registrations as verified; left enabled it would let
anyone register with an address they do not control and reach the application as a
verified user, which also defeats the invitation flow's requirement that an invited
address be proven before the invitation can be accepted. It is read through
`config/auth.php`, which defaults to `false`, so the risk is confined to an environment
that sets it explicitly.

`SESSION_SECURE_COOKIE` is read by `config/session.php` with no fallback, so leaving it
unset sends the session cookie without the `Secure` attribute. The remaining cookie
settings in that file — `same_site` at `lax` and `http_only` at `true` — are already
correct for production and are deliberately not overridden.

### 8. Sessions, Cache, and Queue Stay In PostgreSQL

`SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION` remain `database`. No Redis or
ElastiCache is introduced.

Every web request consequently touches PostgreSQL for its session, which is the honest
cost of this choice. It is accepted for now because the alternative is another managed
service, another set of credentials, and another failure mode, in exchange for latency
that no measurement has yet shown to matter.

No queue worker runs in the image. Nothing in the application currently implements
`ShouldQueue`, so a worker would have nothing to consume. Both decisions are expected to
be revisited — the first when load justifies it, the second as soon as any mail or
notification is queued.

### 9. An Unrecoverable Process Failure Stops the Container

supervisord restarts either process if it dies. When it cannot, a supervisord event
listener stops the container instead.

This closes a failure mode found while testing the image. Killing the PHP-FPM master
orphans its workers, which keep the FastCGI port bound; every restart attempt then exits
with status 78 because the address is in use, until supervisord marks the program `FATAL`
and stops trying. The orphaned worker continues answering Nginx, so the health check keeps
passing and the container sits indefinitely in a degraded state that nothing is
supervising.

Turning `FATAL` into container exit makes the scheduler replace the task, which is the
behaviour the platform already knows how to provide. The container exits 0, because
supervisord shuts its remaining programs down cleanly on `SIGTERM`; ECS replaces an
essential container on any exit code, so the distinction does not matter there.

## Consequences

The application can be built into a self-contained image and run anywhere a container
runs. The image contains no secrets, no build tooling, and no development dependencies,
and runs as an unprivileged user.

Deployment now depends on the environment supplying a valid `APP_KEY`, correct `APP_ENV`,
`APP_DEBUG`, `APP_URL`, `AUTH_AUTO_VERIFY_NEW_USERS`, and `SESSION_SECURE_COOKIE` values,
and reachable PostgreSQL credentials. The first of those is enforced by the container; the
rest are not, and remain the deployment's responsibility.

Two decisions are explicitly provisional. §6 is safe only while the container sits behind
the load balancer with no direct route to it, which the infrastructure phase must
establish. §8 holds only while load stays modest.

Database migrations are deliberately not run by the container. Several tasks may start
concurrently behind a load balancer, and schema changes belong in a separate one-off job
rather than in whichever container happens to boot first. Nothing yet provides that job.
