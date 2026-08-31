# ADR-012: Demo Environment Seeding

## Status

Accepted

## Date

2026-08-31

## Context

The `dev` environment described in `infra/terraform/dev` is a portfolio
environment: it is provisioned from CI, shown to someone, and destroyed the same
day. Its purpose is to let a reviewer open a URL and see the application working.

Until now it could not do that. The deploy workflow ran `php artisan migrate
--force` and nothing else, so every deployment produced a correct but completely
empty database — no organizations, no members, no projects, no tasks. The first
page a reviewer reached was the login form, and there was nothing to log in with.

This went unnoticed for as long as it did because of a false assumption. The
`owner@smoke.test`, `manager@smoke.test`, and `employee@smoke.test` accounts have
been used throughout local development, and were assumed to come from a seeder.
They did not. They were created ad hoc in one developer's local database and were
never in version control at all. Locally they worked; anywhere else they had
never existed. The deployed environment was not broken — it had simply never been
given any data, and the only reason that was surprising was that nobody had
noticed the local accounts were untracked.

Registering by hand after each deploy is not a workaround. The environment is
recreated per demo, registration requires email verification against a mail
configuration the environment does not have, and building an organization,
several projects, and enough tasks to make the AI risk analysis (ADR-011) say
anything interesting is several minutes of clicking that nobody wants to do while
someone is watching.

## Decisions

### 1. A Seeder, Committed to the Repository

The demo scenario is `database/seeders/DemoSeeder.php`. It is code, reviewed like
code, and versioned with the schema it depends on.

The alternative was a SQL dump or a manual runbook. Both were rejected for the
same reason the current situation arose: data that lives outside version control
does not exist anywhere it has not been carried by hand, and nobody finds out
until it is missing. A dump additionally breaks on the next migration, silently
and in a way no test catches.

Every record is written explicitly rather than through a model factory. That is
the opposite of the usual advice in this project, and it is worth saying why.

The first version did use `User::factory()`, on the reasoning that the factory is
the one place that knows a user needs a hashed password and a verified address.
It passed the whole suite and then failed on the first real deploy:

```
Call to undefined function Database\Factories\fake()
  at database/factories/UserFactory.php:28
```

Factories call `fake()`, and Laravel only defines that helper when
`fakerphp/faker` is loaded. Faker is a `require-dev` package, the image is built
with `composer install --no-dev`, and `Factory::create()` evaluates
`definition()` before applying any override — so the call happened even though
the seeder supplied its own name and email.

**This seeder is the only application code that runs in the container but never
in a browser request**, which makes it the only place a dev-only dependency can
hide. Factories exist for tests, where dev dependencies are guaranteed; this
runs in production. Nothing here may reach for one, and a test asserts that
against the source, because the suite itself always has Faker available and so
could never catch it by running the seeder.

What the factory was providing is written out instead: `forceFill` for the
password (through the model's `hashed` cast) and `markEmailAsVerified()` for the
verification timestamp, which is outside the model's `#[Fillable]` list and would
otherwise be dropped in silence. The domain data was always going to be explicit
anyway — the whole point of the fixture is that the numbers are chosen rather
than random.

### 2. A Separate Seeder, Not Part of `DatabaseSeeder`

`DatabaseSeeder` is untouched, and it does not call `DemoSeeder`. The only way to
run the demo scenario is to name it:

```
php artisan db:seed --class=DemoSeeder
```

This is the load-bearing safety decision, and it is a decision about blast radius
rather than about tidiness.

`DatabaseSeeder` is what `db:seed` runs with no arguments, what `migrate --seed`
runs, and what `RefreshDatabase` would run if the suite were configured to seed.
Anything reachable from it is reachable by accident — by a developer running a
familiar command, by a future CI step, by a `--seed` flag added to a migration
command for an unrelated reason. Something that plants an owner account with a
publicly documented password must not be one flag away from any of those.

Keeping them separate also keeps their audiences separate. `DatabaseSeeder`
serves local development; `DemoSeeder` serves one disposable environment. Folding
them together would mean every local developer inherits the demo organization,
and every future change to the demo scenario becomes a change to everyone's local
database.

### 3. The Password Is Publicly Known, and That Is Recorded Rather Than Hidden

All three accounts use `password`, and the README prints the addresses next to
it. Anyone who can reach the deployed environment can sign in as an owner.

This is acceptable in exactly one place and for exactly one reason: the `dev`
environment holds nothing but the contents of this seeder. There is no real user,
no real client, and no real data in it, it exists for a few hours at a time, and
it is destroyed by the `destroy` workflow afterwards. The tenancy and
authorization rules are still fully enforced inside it — a demo owner is an owner
of the demo organization and of nothing else.

The mitigation is not a weaker password or an obscure one; a demo account nobody
can find the password for is a demo account nobody can use. The mitigation is
that the seeder can only ever run somewhere it has been pointed by name, and that
the class carries a comment saying so in the first thing anyone reads.

### 4. No `isProduction()` Guard

The seeder deliberately does **not** refuse to run based on `APP_ENV`.

That guard looks like the obvious safety net and is worse than useless here. The
`dev` environment runs with `APP_ENV=production` (ADR-007 §7, and
`infra/terraform/dev/ecs.tf`), because it is a production-shaped container and
running it any other way would defeat the purpose of having it. So an
`isProduction()` guard would block the one caller that is supposed to run, and
would do nothing about the case that actually matters — someone typing the
command against a database that holds real users, which by definition is a
database whose `APP_ENV` the seeder cannot distinguish from this one.

Guards that block the legitimate path and miss the dangerous one train people to
remove guards. The protection here is that the command must be typed
deliberately, which no environment check can substitute for.

### 5. Idempotent by Natural Key

Every record is matched on the key the database already treats as unique — the
organization slug, the user's email, `(organization_id, slug)` for a project,
`(project_id, title)` for a task, `(organization_id, email)` for an invitation —
and brought to the intended state with `updateOrCreate`.

Running it twice is therefore safe, which matters because it runs on every deploy
and a deploy may land on an environment that already has data. A version that
only created records would fail on the second run with a unique constraint
violation, and the failure would surface as a red deploy rather than as anything
informative.

Updating rather than skipping is the deliberate half of this. A re-run restores
the documented password if someone changed it mid-demo, and recomputes every due
date relative to the day of the run, so "five tasks overdue" and "two
high-priority tasks due this week" stay true however long the environment has
been up. A `firstOrCreate` that left existing rows alone would slowly turn the
at-risk project into a project where everything is simply late, which is a
duller demonstration.

### 6. Deliberately Uneven Project Health

Four projects, with health chosen against the four figures the risk analysis
reads (ADR-011 §3): completion rate, overdue work, unowned work, and imminent
high-priority work.

| Project | Tasks | Complete | Overdue | Unassigned | High priority due ≤7d |
| --- | --- | --- | --- | --- | --- |
| Client Portal Redesign | 8 | 75% | 0 | 0 | 0 |
| Harborview Onboarding Revamp | 12 | 17% | 5 | 6 | 2 |
| Meridian Analytics Dashboard | 7 | 43% | 1 | 1 | 0 |
| Ridgeway Coffee Site Build | 5 | 0% | 0 | 2 | 0 |

The two extremes exist so the AI feature can be shown to discriminate rather than
to produce text. A single project, whatever its health, only demonstrates that
the button returns a paragraph; two projects that get opposite verdicts from the
same prompt demonstrate that the paragraph is derived from the data. The two in
between exist so the projects list does not read as a contrived pair.

Project ages are backdated for the same reason. A project created today cannot
credibly have work that has been late for a fortnight, and `project_age_days` is
one of the figures the model is given.

### 7. The Deploy Runs It by Overriding the Migration Task

The `Seed demo data` step in `.github/workflows/deploy.yml` starts a one-off ECS
task from the **existing** `workflowhub-dev-migrate` task definition, overriding
only the container command:

```
--overrides '{"containerOverrides":[{"name":"migrate","command":["php","artisan","db:seed","--class=DemoSeeder","--force"]}]}'
```

No Terraform change was needed. The image, the environment variables, the Secrets
Manager references, the log configuration, and both IAM roles are inherited from
the migration task, which means they cannot drift away from it — a new task
definition would be a second copy of all of that to keep in step.

This is in tension with a comment on that resource, which argues for a separate
task definition precisely so the intent is recorded in infrastructure rather than
in whichever command someone happens to type. The tension is acknowledged and the
comment's concern is met a different way: the command is not typed anywhere, it
is a named step in a version-controlled workflow, reviewed in a pull request and
visible in the run log. That is the same audit trail the comment is asking for.
The comment stands for anything invoked ad hoc from a terminal.

The step fails the deployment on a non-zero exit code, and prints the task's log
stream afterwards, matching the migration gate immediately above it. An
environment that came up but cannot be logged into is the exact failure this step
exists to prevent, so it is loud rather than discovered during a demo.

### 8. Tested Like Anything Else

`tests/Feature/DemoSeederTest.php` runs the seeder against the test database and
asserts what the demo depends on: that all three accounts authenticate with the
documented password, hold their intended roles, and have verified addresses; that
the at-risk project carries every signal and the healthy one carries none; that a
second run changes no row count and restores a changed password and an expired
invitation; and that `DatabaseSeeder` still creates no `@smoke.test` account.

The last of those is the guard for decision 2. Wiring `DemoSeeder` into the
default seeder would be a small, plausible-looking edit, and it now fails a test
rather than passing review.

One test is unlike the others: it reads the seeder's own source and asserts it
contains no factory call and no `fake()`. Asserting on source is a blunt
instrument and is not a habit worth spreading, but it is the only thing that
works here. The suite always runs with dev dependencies installed, so running
the seeder can never reproduce the production dependency set that decision 1
describes. The choice was a source assertion or no guard at all.

## Consequences

The deployed environment is showable. A reviewer opens the load balancer URL,
signs in as any of three roles, and sees four projects with a plausible spread of
work — including one the AI risk analysis has something real to say about.

The deploy is roughly a minute longer, for one more Fargate cold start.

The `dev` environment now contains accounts anyone can sign in to. That is a
property of that environment and must not become a property of any other. The
constraint that keeps it there is decision 2, enforced by a test, and decision 3,
recorded at the top of the class where the next person to touch it will read it.

If this application ever gains a real production environment, nothing here
changes for it, because nothing here runs unless it is named. The question that
would need reopening is not the seeder but the workflow: a deploy pipeline shared
between a demo environment and a real one must not carry this step.
