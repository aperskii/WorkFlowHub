# ADR-002: Organization and Membership Model

## Status

Accepted

## Context

WorkFlowHub is a multi-tenant SaaS application for small businesses.

Users need to belong to organizations and collaborate with other members while maintaining strict organization boundaries.

A user may belong to more than one organization.

## Decisions

### Organization

An organization represents a tenant in WorkFlowHub.

Each organization has:

* A unique identifier
* A name
* A unique slug
* Timestamps

### Membership

Users belong to organizations through a Membership entity.

Membership represents the relationship between a User and an Organization and contains the user's organization-specific role.

A user may have memberships in multiple organizations.

### Roles

The initial organization roles are:

* Owner
* Manager
* Employee

Roles are represented using a PHP backed Enum rather than arbitrary strings throughout the application.

### Multiple Owners

An organization may have multiple Owners.

The system must not assume that an organization has exactly one Owner.

### Organization Creation

When a user creates an organization, the creating user automatically becomes an Owner of that organization.

### Tenant Isolation

Organization-owned resources must always be accessed within the context of an authorized organization membership.

A user must never be able to access another organization's resources merely by knowing their identifier.

### Clients

Clients are not organization members.

Clients will use the application's authentication system but will have a separate authorization model for the Client Portal.

Client functionality is outside the scope of this decision.

### Deletion

Organization deletion is allowed for authorized Owners in the MVP.

The deletion strategy for related domain entities will be defined when those entities are introduced.

## Consequences

This model provides:

* Multi-organization support
* Organization-specific roles
* Explicit authorization boundaries
* A clear foundation for projects, tasks, clients, billing, and time tracking
* A structure that can later support invitations and role management
