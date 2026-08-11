# ADR-005: Task Domain

## Status

Accepted

## Date

2026-08-11

## Context

Tasks are the second business domain built on the Organization, Membership, and
Project foundation recorded in ADR-002 and ADR-003.

`docs/product/mvp.md` §6 states that projects contain tasks, and that authorized
users can create tasks, assign a task to a project member, update task
information, change task status, set priority, set a due date, and add comments.

This record captures the decisions taken for the first Task slice.

## Decisions

### 1. A Task Belongs to a Project, and Only to a Project

`docs/domain/domain-model.md` records exactly one edge for tasks:
`Project → has many Tasks`. There is no documented relationship between an
organization and a task.

A task therefore carries a non-nullable `project_id` and **no** denormalized
`organization_id`. The owning organization is reached through
`$task->project->organization`.

A denormalized column would be a second source of truth that could drift away
from the project's actual owner. Organization-scoped reporting, such as the
dashboard task counts, queries through the project relationship instead.

Deleting a project deletes its tasks through a database cascade. Deleting an
organization therefore removes its tasks transitively, through its projects.

### 2. ProjectMember Is Still Not Introduced

`mvp.md` §6 says a task is assigned to "a project member", but `ProjectMember`
was deliberately deferred in ADR-003 §3, which established that project access
currently follows organization membership.

`ProjectMember` is therefore **not** introduced here either. This slice does not
change that decision, it inherits it.

### 3. Assignment Is Based on Organization Membership

A task may have one assignee or none, matching `mvp.md` §6: "In the MVP, a task
can be assigned to one user."

The assignee must be a member of the project's organization. This is validated
server-side whenever a task is created or its assignee changed, so a task can
never be handed to somebody outside the tenant.

When `ProjectMember` is eventually introduced, the assignable set will **narrow**
from organization members to project members, which is a safe direction of
travel.

### 4. An Employee May Change the Status of Their Own Task Only

Owners and Managers hold a new `OrganizationRole::canManageTasks()` capability
and may create tasks, edit any field, assign tasks, and change any task's status.

An Employee may view every task in their organization's projects, and may change
the status of a task **that is assigned to them**. They may not create tasks, nor
edit a title, description, priority, due date, or assignee, and may not change
the status of anybody else's task.

This is the first row-level permission in the application: the decision depends
on the record's assignee, not only on the actor's role. Without it, the assignee
of a task could not mark their own work as done, which would make the product
unusable for the role that performs most of the work.

It is a single explicit predicate, `$task->isAssignedTo($user)`. No numeric role
hierarchy is introduced, consistent with ADR-002 and ADR-003.

| Ability                     | Owner | Manager | Employee                  |
| --------------------------- | ----- | ------- | ------------------------- |
| View tasks                  | yes   | yes     | yes                       |
| Create tasks                | yes   | yes     | no                        |
| Edit task fields            | yes   | yes     | no                        |
| Assign tasks                | yes   | yes     | no                        |
| Change task status          | yes   | yes     | only their assigned tasks |
| Delete tasks                | n/a   | n/a     | n/a                       |

Non-members and guests have no access at all.

### 5. A Dedicated `canManageTasks()` Capability

Task management uses its own capability rather than reusing
`canManageProjects()`.

The two answer different questions: one is "may this person archive a project",
the other is "may this person create work inside it". Keeping them separate means
task permissions can change later without altering who may archive a project.

### 6. Tasks Are Not Deleted

`mvp.md` §6 lists creating, assigning, updating, changing status, setting
priority, setting a due date, and commenting. It does not list deletion.

There is therefore no delete route, policy ability, action, or button. Tasks move
through the documented statuses, ending at `Done`.

Unlike projects, tasks have no `Archived` status, so a task created by mistake
can only be moved to `Done`. This is a known consequence of following the
documentation rather than inventing a status or a deletion path. Revisiting it
would mean either adding a documented status or introducing deletion, and both
are product decisions rather than implementation details.

### 7. No Task Routes and No Task Slugs

Tasks are managed entirely through Livewire actions on the existing project page
at `/o/{organization:slug}/projects/{project:slug}`.

No task route exists, so no task slug is needed and no task identifier appears in
a URL. Nothing in the documentation calls for a task slug, and inventing one
would add a uniqueness concern for no benefit.

Every read and mutation resolves through `$project->tasks()`, never through a
global `Task::find()`. A tampered task identifier therefore produces a 404 rather
than reaching another project's or another tenant's work.

### 8. Removing a Membership Unassigns That Person's Tasks

When a membership is deleted, every task assigned to that user **within that
organization** is unassigned. Their assignments in other organizations are left
untouched.

Leaving a former member attached to work they can no longer see would show a
stranger's name on the board and leave the task effectively orphaned.

Deleting a user account is handled separately, at the database level: the
`assigned_to_user_id` foreign key uses `ON DELETE SET NULL`, so the work survives
and only the assignment is cleared. Tasks are never deleted because a person was.

### 9. Dashboard Task Metrics

`mvp.md` §11 asks the organization dashboard to show the number of open tasks and
completed tasks. Both are now real, organization-scoped counts derived through the
organization's projects.

Open means any status other than `Done`. Completed means `Done`.

Tracked hours and recent activity remain absent because time tracking and an
activity feed do not exist. No metric is displayed that is not backed by real
data.

### 10. Comments Are Deferred to Their Own Slice

Comments are explicitly listed in both `docs/product/requirements.md` and
`docs/product/mvp.md` §6. They are **not** implemented here, and this is a
deliberate deferral rather than an oversight.

A comment is a separate entity with its own lifecycle and its own unanswered
questions: who may edit or delete a comment, whether an author may edit their own
comment after the fact, whether mentions or notifications are involved, and how
comments interact with the client portal, where clients are explicitly expected
to comment on projects.

Answering those inside the task slice would either double its size or settle
product questions by implication. Comments will therefore be implemented as a
dedicated slice with their own authorization decisions. No `Comment` model or
migration exists yet.

## Consequences

This model provides:

* Tasks scoped to a single project, and therefore to a single tenant
* Assignment restricted to people who can actually see the work
* A working loop for the employee role without granting broader permissions
* Real dashboard metrics for two of the four documented figures
* No identifiers in URLs and no new tenant boundary to defend

It intentionally leaves comments, attachments, activity logs, notifications,
subtasks, dependencies, recurring tasks, board and timeline views, search, bulk
actions, task deletion, multi-assignee support, and time tracking to later
slices.
