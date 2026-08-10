# ADR-002: Organization and Membership Model

## Status

Accepted

## Context

WorkFlowHub is a multi-tenant SaaS application for small businesses.

Users need to belong to organizations and collaborate with other members while maintaining strict organization boundaries.

A user may belong to more than one organization.

## Decisions

### Organization

An Organization represents a tenant in WorkFlowHub.

Each Organization has:

* A unique identifier
* A name
* A unique slug
* Timestamps

### Membership

Users belong to Organizations through an explicit Membership entity.

Membership represents the relationship between a User and an Organization and contains the user's organization-specific role.

A User may belong to multiple Organizations.

A User must not have more than one Membership for the same Organization.

### Roles

The initial organization roles are:

* Owner
* Manager
* Employee

Roles are represented by a PHP backed Enum.

The MVP does not introduce database-backed roles or permissions.

Custom roles and granular permissions may be introduced later if product requirements justify them.

### Multiple Owners

An Organization may have multiple Owners.

The system must never assume that an Organization has exactly one Owner.

However, an Organization must always have at least one Owner.

Therefore:

* The last Owner cannot be removed.
* The last Owner cannot be demoted.
* A User who is the last Owner cannot delete their account until ownership is transferred or another Owner exists.

### Organization Creation

Registration does not automatically create an Organization.

The onboarding flow is:

```text
Register
    ↓
Verify email
    ↓
Dashboard
    ↓
Create Organization
    ↓
Creator becomes Owner
```

When a User creates an Organization, the creating User automatically becomes an Owner.

Organization creation and the initial Membership creation must happen atomically inside one database transaction.

### Tenant Resolution

Organization context is route-based.

Organization-owned routes will use the following structure:

```text
/o/{organization:slug}/...
```

The Organization is therefore explicit in the URL rather than stored only as a session-level "current organization".

This provides:

* Explicit tenant context
* Shareable URLs
* Stateless tenant resolution
* Safer multi-tab behavior
* Simpler authorization and testing

### Tenant Isolation

Organization-owned resources must always be accessed within an authorized Organization Membership.

A User must never be able to access another Organization's resources merely by knowing their identifier.

Future Organization-owned resources should preferably be queried through the Organization relationship rather than relying on a global tenant scope.

### Authorization

Authorization is Organization-specific.

The Membership entity is the authoritative source for the relationship between a User and an Organization.

Organization and Membership authorization must verify the acting User's Membership and role.

Client Portal users are not Organization members and must not receive Organization access through these policies.

### Client Portal

Clients are not Organization members.

The Client Portal will use a separate authorization model and will be designed in a later domain slice.

### Organization Deletion

Organization deletion is allowed for authorized Owners in the MVP.

Organization deletion uses hard deletion.

Memberships are deleted through a database-level foreign-key cascade when their Organization is deleted.

Soft deletion is intentionally outside the MVP.

### User Deletion

Deleting a User removes their Membership records through a database-level cascade.

Before deleting a User, the application must ensure that the deletion does not leave any Organization without an Owner.

If the User is the last Owner of any Organization, account deletion must be prevented until ownership is transferred or another Owner exists.

### Membership Deletion and Role Changes

The application must prevent operations that would leave an Organization without an Owner.

This invariant must be enforced in the application layer because a simple foreign-key constraint cannot express it.

### Slugs

Organization slugs are unique and normalized using Laravel's slug generation.

The MVP uses a regular unique database constraint.

Organizations will be accessed through the `/o/{organization:slug}` URL structure, reducing conflicts with application-level routes.

### Database

PostgreSQL is the project's development, test, CI, and production database.

Memberships use the following constraints:

```text
UNIQUE (organization_id, user_id)
INDEX  (user_id)
```

Organization slugs use:

```text
UNIQUE (slug)
```

Foreign keys use cascading deletion where the child record has no meaning without its parent.

### Architecture

The first domain implementation will use:

```text
app/
├── Actions/
│   └── Organizations/
├── Enums/
├── Models/
└── Policies/
```

This introduces `Enums` and `Policies` as standard Laravel application directories.

The application will not introduce a generic service layer or repository layer for this domain.

### Consequences

This model provides:

* Multi-organization support
* Organization-specific roles
* Explicit tenant boundaries
* Strong database-level membership integrity
* A clear ownership invariant
* Safe organization-aware authorization
* A foundation for Projects, Tasks, Clients, Time Tracking, Billing, and Subscriptions

It also intentionally leaves invitations, custom roles, permissions, Client Portal authorization, and organization switching UI for later domain slices.
