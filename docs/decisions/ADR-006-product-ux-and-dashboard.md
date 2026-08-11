# ADR-006: Product UX and Dashboard Foundation

## Status

Accepted

## Date

2026-08-11

## Context

By the end of the Task slice (ADR-005) the domain was complete enough to be useful, but
the interface was not. The organization dashboard presented eight stat tiles of equal
visual weight, two of which were not metrics at all — the organization's creation date and
its owner count. Nothing in the product answered the question a work-management tool
exists to answer: *what needs my attention?*

Two gaps were structural rather than cosmetic:

* `User::assignedTasks()` existed and no screen used it. An Employee could not see their
  own work without opening every project in turn.
* `due_date` was stored, displayed, and filterable, but nothing anywhere surfaced
  lateness.

This record captures the decisions taken to turn the existing foundation into a coherent
product surface. It adds no entities, no columns, and no routes.

## Decisions

### 1. Overdue Is Derived, Never Stored

A task is overdue when its `due_date` is before today **and** its status is not `Done`.

This is expressed once as `Task::overdue()`, with `Task::isOverdue()` and
`Task::daysOverdue()` for rendering a single record. No `is_overdue` column, no nightly
job, and no cached counter exists.

A stored flag would be a second source of truth that goes stale the moment a date passes
or a task is completed, which is precisely the failure mode ADR-005 §1 avoided by refusing
to denormalize `organization_id` onto tasks.

Because a finished task is never late, `overdue()` deliberately implies `open()` rather
than composing with it. A task due today is **not** overdue; lateness begins the day after
the due date.

### 2. The Dashboard Is Role-Aware, Using the Existing Capability

The dashboard branches on `OrganizationRole::canManageTasks()` — the capability introduced
in ADR-005 §5 — rather than introducing a new one.

Owners and Managers lead with organization-wide figures and a "Needs attention" panel
covering overdue and unassigned work. Employees lead with their own workload and the tasks
assigned to them.

The two audiences ask different questions. A manager asks "is anything slipping across the
organization?"; an employee asks "what am I supposed to do next?". A single layout answers
one of them badly.

This is presentation only. Both branches read through the same tenant-scoped queries, and
no authorization decision depends on which branch renders.

### 3. Only Figures the Domain Can Actually Support

`docs/product/mvp.md` §11 asks the dashboard for active projects, open tasks, completed
tasks, tracked hours, recent projects, and recent activity.

Four of those are now real. **Tracked hours remains absent because `TimeEntry` does not
exist, and recent activity remains absent because no activity log exists.** No placeholder,
sample figure, or zero-valued tile stands in for either.

Overdue and unassigned counts are shown although the MVP does not list them, because both
are derived entirely from data the domain already stores and both are directly actionable.

The dropped tiles — creation date, owner count, total projects — were removed because a
date is not a metric, the owner count is domain trivia rather than something a user acts
on, and the project total belongs on the projects index. The organization's member and
project counts survive as a compact summary card rather than as headline figures.

### 4. Task Metrics Continue to Route Through Projects

Every organization-scoped task query goes through
`Task::whereHas('project', fn ($q) => $q->where('organization_id', …))`, unchanged from
ADR-005 §9.

Adding `organization_id` to tasks would make these queries cheaper and is explicitly not
done. An index on `tasks.due_date` supports the new overdue queries instead.

### 5. Hiding a Control Is Never Authorization

Every control the dashboard renders conditionally is wrapped in the existing policy check
(`@can('create', [Project::class, $organization])`,
`@can('create', [Invitation::class, $organization])`). The role branch changes what is
*offered*, never what is *permitted*.

The task status control extracted into `x-task-status-control` is shared by the desktop
table and the mobile card list, and still consults `TaskPolicy::changeStatus` in both. No
policy, gate, or action was modified in this phase.

### 6. Two Screens Were Both Called "Dashboard"

`/dashboard` listed organizations while the org-scoped page was titled "Overview",
producing the breadcrumb `Dashboard › Acme › Overview`.

The global list is now **"Your organizations"** and the org-scoped page is the
**"Dashboard"**. The hierarchy reads Organization → Dashboard / Projects / Members /
Settings, matching the sidebar.

### 7. The Organization Switcher Was Already Correct

A link-only switcher already existed in the sidebar. It is route-bound, stores no "current
organization" in the session, and every entry is a slug URL — so tenancy remains resolved
entirely from the route, per ADR-002.

It is left untouched. No session-backed tenant context was introduced.

### 8. Dense Tables Fall Back to Cards, Not Horizontal Scroll

Flux already wraps tables in a horizontal scroll area, so nothing was broken at narrow
widths — but reading a six-column task table by scrolling sideways on a phone is not
usable.

The task and member lists render as a table at `sm` and above and as stacked cards below
it. Both render the same test hooks and the same `wire:click` targets, so behaviour and
authorization are identical at either width.

### 9. Empty States Are a Component, Not a Convention

Three different empty-state patterns had grown. `x-empty-state` wraps `flux:callout` and
takes a heading, a description, and an optional action, so every empty area answers what
is empty, why it matters, and what to do next.

The create action moved *inside* the empty state on the organizations list, where a new
user is actually looking, rather than sitting in the page header.

### 10. No Onboarding Wizard

A new user's path is Create account → Create organization → Dashboard → Create project →
Create first task → Invite members.

This is carried by strong empty states and contextual guidance rather than a wizard. A
wizard would add state to track, a way to skip and resume, and a second code path for
every screen it fronts — none of which the current architecture needs.

## Consequences

This gives the product:

* A dashboard that answers "what needs my attention?" for both audiences
* A working daily loop for the Employee role, which previously had none
* Lateness surfaced everywhere a due date appears
* One empty-state, badge, and status-control vocabulary
* A usable narrow-width experience for the two densest tables

It intentionally leaves tracked hours, activity feeds, notifications, charts, saved
filters, task search, bulk actions, per-project task counts on the projects list, and a
dedicated "my work across all organizations" surface to later slices.
