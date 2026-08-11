# ADR-010: Expand and Contract Migrations

## Status

Accepted

## Date

2026-08-11

## Context

The application now runs as an ECS service behind a load balancer (ADR-008).
Deployments replace tasks rather than restarting a single process, and ECS
starts the replacement before draining the one it supersedes. For a window of
seconds to minutes, **two revisions of the application run at once against one
database**.

That window exists today at `desired_count = 1`, because a rolling replacement
still overlaps. It widens with every additional task.

A migration that changes the shape of the schema in a single step is unsafe in
that window, in both directions:

* Migrate first, then deploy, and the old code meets a schema it was not written
  for. A dropped or renamed column breaks every query naming it.
* Deploy first, then migrate, and the new code meets the old schema and breaks
  in the same way.

There is no ordering that makes a destructive change safe while both revisions
are live. The problem is the change itself, not when it runs.

Every migration written so far is purely additive — each creates a new table —
so nothing existing is affected. This record is a policy adopted before the
first destructive change rather than after one causes an incident.

## Decisions

### 1. A Migration May Only Widen What the Running Code Can Tolerate

A migration is safe to deploy when the revision currently running keeps working
after it is applied. In practice:

**Allowed in a single migration**

* Creating a table
* Adding a **nullable** column, or one with a default
* Adding an index
* Adding a constraint that the existing data and the running code already satisfy

**Not allowed in a single migration**

* Dropping a column or table
* Renaming a column or table
* Making a nullable column `NOT NULL`
* Narrowing a type or tightening a constraint
* Adding a unique index to a column that is not already unique in practice

The distinction is whether the previously deployed code still functions once the
migration lands. A new nullable column is invisible to code that does not select
it. A dropped column is not.

### 2. A Destructive Change Is Split Across Deployments

Reshaping anything takes three deployments, in this order:

1. **Expand.** Add the new shape alongside the old. Write to both; keep reading
   from the old. Backfill existing rows in the same or a following migration.
2. **Migrate the code.** Deploy a revision that reads from the new shape. The old
   column still exists and still receives writes, so a rollback to the previous
   revision remains possible for the whole of this step.
3. **Contract.** Once no running revision references the old shape, drop it.

Renaming a column is therefore never `renameColumn`. It is: add the new column,
write both, backfill, switch reads, stop writing the old, drop the old.

Step 3 is a separate migration in a separate deployment. Combining it with step 1
recreates exactly the problem this avoids.

### 3. The Cost Is Accepted Deliberately

This is slower and more verbose than editing a column in place. A rename becomes
three deployments and a period where two columns hold the same data.

It is accepted because the alternative is a deployment window during which the
application is broken for some fraction of requests — the fraction served by
whichever revision does not match the schema. That failure is intermittent,
depends on which task answers, and is difficult to reproduce afterwards.

The discipline also makes rollbacks possible. A deployment that only added a
nullable column can be rolled back without touching the database. One that
dropped a column cannot be rolled back at all without restoring data.

### 4. No Mechanism Enforces This

Nothing in the codebase or the pipeline checks that a migration is additive. This
is a review-time concern: the rule is recorded so that a destructive migration is
recognised as a decision rather than written by habit.

An automated check is possible — parsing migrations for `dropColumn`,
`renameColumn`, and similar — and is not worth building before there is a single
destructive migration to catch. Should one be needed, this record is what it
would enforce.

## Consequences

Schema changes are additive by default, and the schema accumulates columns that
are no longer read until a contract migration removes them. That untidiness is
the visible cost of being able to deploy without a maintenance window.

The rule interacts with the migration ordering problem, which it does not solve.
Nothing yet guarantees migrations run before the tasks that depend on them; that
gate belongs with the deployment pipeline. What this record does is remove the
*requirement* for perfect ordering: if every migration is additive, a task that
starts slightly before or after one runs still works. Ordering becomes a
correctness detail rather than the only thing standing between a deployment and
an outage.

It also pairs with the readiness endpoint added alongside this record. `/up/ready`
reaches the database and fails when it cannot, so a task that genuinely cannot
serve is taken out of the load balancer's rotation instead of being sent traffic
it will answer with a 500.
