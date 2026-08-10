# ADR-003: Project Domain

## Status

Accepted

## Date

2026-08-10

## Context

Projects are the first business domain built on top of the Organization and
Membership foundation recorded in ADR-002.

`docs/product/requirements.md` and `docs/product/mvp.md` describe projects as the
unit of client or internal work an organization delivers. Projects will later
contain tasks and time entries, and may optionally belong to a client.

This record captures the decisions taken for the first Project slice.

## Decisions

### 1. Projects Belong Directly to an Organization

A project is owned by exactly one organization through a non-nullable
`organization_id` foreign key.

There is no intermediate table and no polymorphic owner.

Deleting an organization deletes its projects through a database-level cascade,
consistent with the membership cascade decided in ADR-002.

### 2. Organization Membership Controls Project Visibility

Project authorization is derived entirely from the acting user's `Membership` of
the owning organization.

Every member of an organization can see every project belonging to it.

The `ProjectPolicy` resolves the acting user's real membership and delegates the
decision to the `OrganizationRole` enum. It never trusts a role, organization
identifier, or project identifier supplied by the client.

### 3. ProjectMember Is Intentionally Deferred

`docs/domain/domain-model.md` lists `ProjectMember` as a domain entity, and
`docs/product/mvp.md` lists adding and removing project members.

`ProjectMember` is deliberately **not** implemented in this slice.

A per-project membership system introduces a second set of roles, policies,
invariants, and isolation tests, comparable in size to the entire Organization
and Membership slice. Shipping projects on organization membership first keeps
this slice reviewable and gets the core product working.

Consequence: until `ProjectMember` exists, an Employee can view every project in
their organization. Introducing `ProjectMember` later will **narrow** visibility
rather than widen it, so this is a safe direction of travel.

### 4. Project Slugs Are Unique Per Organization

Project slugs are generated from the project name, normalized to lowercase, and
constrained by a composite unique index:

```text
UNIQUE (organization_id, slug)
```

Slugs are therefore **not** globally unique, unlike organization slugs. Two
organizations may each own a project slugged `website-redesign`.

This is what allows nested scoped route model binding to act as the tenant
boundary. Project routes are nested under the organization and the group uses
`->scopeBindings()`, so a project is resolved through
`$organization->projects()`. A slug belonging to another tenant produces a 404
rather than an authorization failure, and does not reveal that the record exists.

Slug collisions within an organization are resolved with a numeric suffix.
Because a failed statement aborts the surrounding PostgreSQL transaction, a
concurrent collision is handled by replaying the insert rather than by
recovering inside it.

The create route is `/o/{organization:slug}/projects/new` rather than
`/projects/create`, so no project slug needs to be reserved.

### 5. Projects Are Archived, Not Deleted

`docs/product/mvp.md` lists creating, updating, archiving, and viewing projects.
It does not list deletion.

Projects therefore have **no delete action** in the MVP. `Archived` is a value of
the `ProjectStatus` enum and is the terminal state. Archived projects remain
readable and can be moved back to another status.

Soft deletes are intentionally not used. Revisiting deletion will require
deciding the cascade behaviour for tasks and time entries, which do not exist
yet.

### 6. Project Management Belongs to Owners and Managers

A new capability is added to the `OrganizationRole` enum:

```text
canManageProjects(): Owner, Manager
```

| Ability                | Owner | Manager | Employee |
| ---------------------- | ----- | ------- | -------- |
| View projects          | yes   | yes     | yes      |
| Create projects        | yes   | yes     | no       |
| Update projects        | yes   | yes     | no       |
| Change project status  | yes   | yes     | no       |
| Archive projects       | yes   | yes     | no       |
| Delete projects        | n/a   | n/a     | n/a      |

This mirrors `canManageMembers` and keeps the enum capability-based. No numeric
role hierarchy is introduced, consistent with ADR-002.

### 7. No State Machine for Status Transitions

Any `ProjectStatus` may move to any other `ProjectStatus`, including
`Archived` back to `Active`.

Guarded transitions would require a state machine, which is not justified by the
MVP requirements. The status values are:

```text
Planning, Active, On hold, Completed, Archived
```

### 8. Status Storage

`status` is stored as a `varchar(20)` and cast to the `ProjectStatus` enum.

PostgreSQL native enum types are intentionally avoided, for the same reason as
`memberships.role`: adding or removing values would require `ALTER TYPE`
migrations, and the type authority belongs in the application.

## Consequences

This model provides:

* A project domain owned by, and isolated to, a single organization
* Tenant isolation enforced at the routing layer as well as in policies
* Role-based project management reusing the existing capability enum
* A readable, reversible archive lifecycle
* A foundation for Tasks, Time Tracking, and Clients

It intentionally leaves per-project membership, tasks, clients, time tracking,
comments, attachments, project deletion, and status transition rules to later
slices.
