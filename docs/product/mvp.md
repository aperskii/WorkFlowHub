# WorkFlowHub – MVP Scope

## 1. Goal

The goal of the MVP is to provide a production-quality multi-tenant SaaS platform for small companies and agencies to manage:

* Organizations
* Team members
* Projects
* Tasks
* Clients
* Time tracking
* Client collaboration
* Subscription billing

The MVP should demonstrate strong software engineering practices, including:

* Multi-tenancy
* Authentication and authorization
* Clean domain modeling
* Server-side validation
* Automated testing
* Database constraints and indexing
* External service integration
* Maintainable Laravel architecture

---

# 2. Authentication

Users must be able to:

* Register
* Login
* Logout
* Reset their password
* Verify their email address

### MVP Requirements

* Authentication must be handled securely.
* Passwords must never be stored in plain text.
* Email verification must be supported.
* Authorization must be enforced server-side.

---

# 3. Organizations

An organization represents a company or team using WorkFlowHub.

Users can belong to multiple organizations.

### Organization Owner

The owner can:

* Create an organization
* Update the organization profile
* Invite members
* Remove members
* Manage member roles
* View organization information

### Multi-Tenancy

All organization-owned resources must be isolated by organization.

A user must never be able to access resources belonging to another organization without explicit authorization.

---

# 4. Memberships and Roles

A user joins an organization through a membership.

The membership defines the user's role within that organization.

Initial roles:

* Owner
* Manager
* Employee

The Client role is handled separately through the Client Portal.

### Example

A user may belong to multiple organizations:

```text
User
├── Organization A → Manager
└── Organization B → Employee
```

Roles are organization-specific.

---

# 5. Projects

An organization can create multiple projects.

Users with appropriate permissions can:

* Create projects
* Update projects
* Archive projects
* View projects
* Add project members
* Remove project members
* Change project status

### Project Status

Initial statuses:

* Planning
* Active
* On Hold
* Completed
* Archived

A project may optionally belong to a client.

Internal projects without a client must also be supported.

---

# 6. Tasks

Projects contain tasks.

Authorized users can:

* Create tasks
* Assign a task to a project member
* Update task information
* Change task status
* Set priority
* Set due date
* Add comments

### Task Status

Initial statuses:

* Todo
* In Progress
* In Review
* Done

### Task Priority

Initial priorities:

* Low
* Medium
* High
* Urgent

In the MVP, a task can be assigned to one user.

---

# 7. Time Tracking

Users can track the time they spend working on projects.

The system must support:

* Starting a timer
* Stopping a timer
* Creating manual time entries
* Viewing personal time entries
* Viewing project time

Each time entry belongs to:

* A user
* A project

The system should prevent invalid time entries such as negative or overlapping durations where applicable.

---

# 8. Clients

Organizations can manage their clients.

Authorized users can:

* Create clients
* Update client information
* View clients
* Associate projects with clients

A client belongs to exactly one organization.

---

# 9. Client Portal

Clients receive restricted access to WorkFlowHub.

Clients can:

* Login
* View projects they are associated with
* View project progress
* View relevant tasks
* Add comments
* Upload files
* Download permitted files

Clients must not have access to internal organization data.

They must not be able to:

* Manage organization members
* Manage subscriptions
* Access internal notes
* Access unrelated projects
* Access unauthorized time entries

---

# 10. Subscription & Billing

WorkFlowHub uses a SaaS subscription model.

Stripe is responsible for payment processing and subscription billing.

The application must support:

* Viewing available plans
* Starting a subscription
* Upgrading a subscription
* Downgrading a subscription
* Cancelling a subscription
* Viewing subscription status
* Accessing the Stripe Billing Portal

Stripe webhooks will be used to synchronize relevant subscription lifecycle events with the application.

### Important

The application must not trust the frontend for subscription authorization.

Subscription-related permissions must be enforced server-side.

---

# 11. Dashboard

The organization dashboard should provide a useful overview of the current organization.

The initial dashboard should display:

* Number of active projects
* Number of open tasks
* Completed tasks
* Tracked hours
* Recent projects
* Recent activity

The dashboard should be designed to be extendable for future analytics.

---

# 12. Authorization

Authorization is a core MVP requirement.

Users must only be able to perform actions allowed by their organization role and permissions.

Authorization must be enforced on the server.

The system must protect against:

* Cross-tenant access
* Unauthorized project access
* Unauthorized task modifications
* Unauthorized client access
* Unauthorized billing access

Laravel Policies and appropriate authorization mechanisms should be used.

---

# 13. Testing

Business-critical functionality must have automated tests.

The MVP should include tests for:

* Authentication
* Organization access
* Memberships
* Role-based authorization
* Project access
* Task authorization
* Client isolation
* Time tracking
* Subscription state handling

Feature tests should be preferred for testing complete business flows.

Unit tests should be used where isolated domain logic benefits from them.

---

# 14. Non-Functional Requirements

The MVP should be:

### Secure

* Proper authentication
* Server-side authorization
* Tenant isolation
* Input validation
* Secure file access
* No secrets committed to Git

### Maintainable

* Clear naming
* Small focused classes
* Laravel conventions
* Minimal unnecessary abstraction
* Documented architectural decisions

### Testable

Critical business rules must be covered by automated tests.

### Performant

The application should use:

* Appropriate database indexes
* Eager loading where appropriate
* Pagination for large collections
* Efficient queries

Premature optimization should be avoided.

---

# 15. Explicitly Out of Scope

The following features are intentionally excluded from the MVP:

* Full accounting system
* Advanced invoice management
* Advanced analytics
* Real-time chat
* Calendar
* Mobile application
* Public API
* File versioning
* Advanced notification system
* Microservice architecture
* Kubernetes
* Multi-region AWS deployment

These features may be considered in future versions.

---

# 16. MVP Success Criteria

The MVP is considered complete when an organization can:

1. Register and verify an account.
2. Create an organization.
3. Invite team members.
4. Assign organization roles.
5. Create and manage projects.
6. Create and assign tasks.
7. Track working time.
8. Manage clients.
9. Give clients restricted portal access.
10. Subscribe to a plan through Stripe.
11. Manage the subscription through the billing portal.
12. Access only data they are authorized to access.
13. Run the automated test suite successfully.

The MVP must be deployable as a production-ready Laravel application.

AWS infrastructure and Terraform deployment will be implemented as a separate phase after the application MVP is stable.
