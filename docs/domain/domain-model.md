# Domain Model

## Core Entities

- User
- Organization
- Membership
- Role
- Permission
- Project
- ProjectMember
- Task
- Client
- TimeEntry
- Subscription

## Multi-Tenancy

A user can belong to multiple organizations.

A user's role is organization-specific and is represented
through the Membership entity.

Example:

User
→ Organization A
→ Manager

User
→ Organization B
→ Employee

Organization-owned resources must always be properly
scoped to their organization.

## Initial Relationships

User
→ has many Memberships

Organization
→ has many Memberships

Organization
→ has many Projects

Organization
→ has many Clients

Organization
→ has one Subscription

Project
→ has many Tasks

Project
→ has many TimeEntries

User
→ has many TimeEntries

Client
→ has many Projects

Project
→ has many ProjectMembers

User
→ can belong to many Projects through ProjectMembers