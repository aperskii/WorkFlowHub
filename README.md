# WorkFlowHub

A multi-tenant project and team management application for small companies and agencies.

A user can belong to several organizations at once, holding a different role in each.
Every organization owns its own projects, tasks, and members, and no organization's data
is reachable from another. Authorization is enforced server-side by policies on every
action, never by hiding controls in the interface.

## Features

Built and working:

- **Organizations** — create an organization, rename it, delete it, and switch between the
  ones you belong to
- **Membership and roles** — Owner, Manager, and Employee, scoped per organization rather
  than globally, so the same user can be an Owner in one and an Employee in another
- **Invitations** — invite by email with a signed, expiring token; resend or revoke a
  pending invitation. Accepting requires a verified email matching the invited address
- **Projects** — per-organization projects with a status lifecycle and archiving
- **Tasks** — status, priority, due date, and assignment to a member of the organization,
  with lateness derived from the due date rather than stored
- **Dashboard** — role-aware: managers see what needs attention across the organization,
  employees see the work assigned to them

Not built. The domain model reserves room for these, and nothing in the application claims
otherwise: clients, time tracking, a client portal, and subscription billing.

## Stack

| | |
|---|---|
| PHP | 8.5 |
| Laravel | 13 |
| Livewire | 4 (single-file page components) |
| Flux UI | 2 (free tier) |
| Tailwind CSS | 4 |
| PostgreSQL | 18 |
| Testing | Pest 5 |
| Static analysis | Larastan / PHPStan |
| Formatting | Laravel Pint |

## Local development

Requires PHP 8.5, Composer, Node 22+, and Docker.

PostgreSQL and Mailpit run in containers; the application itself runs on the host.

```bash
# 1. Configuration. Compose reads DB_* from this file, so it must exist first.
cp .env.example .env

# 2. PostgreSQL on port 5433 and Mailpit on 1025/8025.
docker compose up -d

# 3. Install dependencies, generate the app key, migrate, and build assets.
composer setup

# 4. Serve the application, queue listener, and Vite together.
composer dev
```

The application is then at `http://localhost:8000`. Mail is captured by Mailpit rather
than delivered — read it at `http://localhost:8025`.

`DB_PORT` defaults to 5433 rather than 5432 so the container cannot collide with a
PostgreSQL instance already running locally. Change it in `.env` if that port is taken.

`AUTH_AUTO_VERIFY_NEW_USERS=true` in `.env.example` marks newly registered users as
verified so you can reach the dashboard without opening Mailpit. It is a local
convenience only and must be `false` in production.

### Tests and checks

```bash
php artisan test --compact          # 660 tests
composer ci:check                   # Pint, PHPStan, and the full suite
vendor/bin/pint --dirty             # format changed files
```

`composer ci:check` is what CI runs. The test suite uses a separate `workflowhub_testing`
database, created by `docker/postgres/init` when the data volume is first initialized.

## Production container

`docker/app/Dockerfile` builds a self-contained image running PHP-FPM and Nginx under
supervisord, as an unprivileged user. Composer and Node run in earlier build stages, so
neither they nor the development dependencies reach the runtime image.

```bash
docker build -f docker/app/Dockerfile -t workflowhub-app .

docker run --rm -p 8080:8080 \
  -e APP_ENV=production \
  -e APP_KEY="$(php artisan key:generate --show)" \
  -e APP_DEBUG=false \
  -e APP_URL=http://localhost:8080 \
  -e DB_CONNECTION=pgsql -e DB_HOST=... -e DB_PORT=5432 \
  -e DB_DATABASE=... -e DB_USERNAME=... -e DB_PASSWORD=... \
  workflowhub-app
```

The container refuses to start without `APP_KEY`, or with `APP_DEBUG=true` while
`APP_ENV=production`. It does not run migrations; schema changes belong in a separate
one-off job rather than in whichever container starts first.

`.env.example` marks every key whose value must differ in production. Reasoning for the
image and its configuration is in
[ADR-007](docs/decisions/ADR-007-production-containerization.md).

## Documentation

- [Architecture overview](docs/architecture/overview.md)
- [Domain model](docs/domain/domain-model.md)
- [Product requirements](docs/product/requirements.md) and [MVP scope](docs/product/mvp.md)
- [Architecture decision records](docs/decisions/) — one per slice, recording what was
  decided and why
