# ADR-004: Organization Invitations

## Status

Accepted

## Date

2026-08-10

## Context

Until this slice the only way to gain an organization membership was to create
the organization, which made the creator its owner. There was no way to add a
second person to an organization.

This record captures the durable and security-sensitive decisions taken for the
organization invitation flow.

## Decisions

### 1. Invitations Are Organization-Scoped

An `Invitation` belongs to exactly one organization through a non-nullable
`organization_id` foreign key, and is deleted with its organization through a
database-level cascade, consistent with memberships and projects.

The invitation itself carries the organization, so no tenant identifier appears
in the acceptance URL and there is nothing tenant-related for a client to tamper
with.

### 2. Only a Token Hash Is Stored

The raw invitation token is generated server-side with `Str::random(64)` and is
returned to the caller exactly once, so it can be placed in the emailed URL. Only
its SHA-256 digest is persisted, in `token_hash`.

Lookup hashes the supplied token and queries `token_hash`. An unknown token is
indistinguishable from a token that never existed: both produce a 404.

SHA-256 is used rather than bcrypt because the token must be resolvable from the
URL alone. This mirrors Laravel Sanctum's personal access tokens, which store
`hash('sha256', $token)` for the same reason. Laravel's password reset broker
uses bcrypt, but that flow looks the record up by email first and therefore has a
different requirement.

### 3. Owners Cannot Be Invited

`OrganizationRole::invitable()` returns Manager and Employee only. Neither an
Owner nor a Manager may create an owner through an invitation.

Ownership is granted exclusively through the existing member role management
flow, which already enforces that only owners may grant the owner role
(ADR-002 and the `MembershipPolicy` owner-escalation guard).

This means the invitation flow structurally cannot mint an owner, rather than
relying on a check that could later be missed.

### 4. Invitation Management Reuses `canManageMembers()`

Sending, resending, revoking, and listing invitations all require
`OrganizationRole::canManageMembers()`, the same capability that governs member
management. No second authorization hierarchy is introduced.

Managers may invite Managers as well as Employees, matching what
`MembershipPolicy::updateRole` already allows them to do to an existing member.
Restricting managers to Employee-only invitations would be inconsistent and
trivially bypassable by inviting an Employee and then promoting them.

| Actor       | Invite | Invitable roles     | Resend | Revoke | See pending |
| ----------- | ------ | ------------------- | ------ | ------ | ----------- |
| Owner       | yes    | Manager, Employee   | yes    | yes    | yes         |
| Manager     | yes    | Manager, Employee   | yes    | yes    | yes         |
| Employee    | no     | none                | no     | no     | no          |
| Non-member  | no     | none                | no     | no     | no          |

The pending invitation list exposes email addresses, so it follows the same
capability rather than being visible to all members.

### 5. One Invitation Row Per Address Per Organization

A composite unique constraint `(organization_id, email)` guarantees that an
address can have at most one invitation row per organization.

Re-inviting or resending updates that row: it rotates the token, resets the
expiry, and clears the accepted and revoked timestamps. Duplicate outstanding
invitations are therefore impossible at the database level, without needing a
partial index or an application-level check with a race window.

The trade-off is that invitation history is not retained. Audit logging is
outside the MVP scope, so this is acceptable; introducing history later would
mean adding an append-only table rather than changing this one.

Email addresses are normalized to lowercase before storage, so the constraint is
effectively case-insensitive. This matches `fortify.lowercase_usernames`.

### 6. Resending Rotates the Token

Resending an invitation issues a new token and immediately invalidates the
previous one.

A resend usually happens because the first email went somewhere unhelpful: a
shared mailbox, a forwarded thread, or a spam folder somebody else can read.
Reusing the token would leave every one of those copies valid for the remainder
of the window. Rotating bounds the exposure of any earlier copy to the moment of
the resend.

The cost is that an older email stops working, which is the behaviour users
already expect from comparable products.

### 7. Invitation State Is Derived, Not Stored

There is no status column. `InvitationStatus` is computed from `accepted_at`,
`revoked_at`, and `expires_at`, so the stored record and its state can never
disagree.

```text
Pending   accepted_at null, revoked_at null, expires_at in the future
Expired   accepted_at null, revoked_at null, expires_at in the past
Accepted  accepted_at not null
Revoked   revoked_at not null
```

Only a Pending invitation can be accepted.

### 8. Expiry Is Configurable and Defaults to Seven Days

`config('auth.invitation_expires_after_days')`, driven by
`AUTH_INVITATION_EXPIRES_AFTER_DAYS`, follows the pattern already established by
`auth.auto_verify_new_users`. Nothing hardcodes the window.

### 9. Acceptance Does Not Weaken Email Verification

Accepting an invitation requires an authenticated user whose email matches the
invitation, and whose email address is verified.

Accepting an invitation is deliberately **not** treated as proof of email
ownership, and never marks a user's email as verified. Local development remains
convenient through the existing `AUTH_AUTO_VERIFY_NEW_USERS` setting, which is
false everywhere else.

A user who is already a member consumes the invitation without a second
membership being created, which the `(organization_id, user_id)` unique
constraint would reject in any case.

### 10. The Invitation Page Is Public, Acceptance Is Not

`GET /invitations/{token}` is reachable without authentication so an invited
person can see who invited them, to which organization, and with what role,
before deciding to sign in.

Accepting requires authentication and verification, is re-authorized at action
time, and re-resolves the invitation from the token rather than trusting the
rendered page.

Guests are offered sign-in and registration links that store the invitation URL
in `url.intended`, which both `Fortify\LoginResponse` and
`Fortify\RegisterResponse` honour. No invitation state is kept in the session
beyond that intended URL.

### 11. Emails Are Sent Synchronously

The invitation notification is an on-demand mail notification, addressed with
`Notification::route('mail', $email)` because the invitee may not have an account.

It is not queued. The local environment runs Mailpit but no queue worker, so
`ShouldQueue` would silently strand invitation emails. Queueing should be
revisited when a worker becomes part of the deployment.

## Consequences

This model provides:

* A safe way to grow an organization's membership
* Tokens that are useless to an attacker who reads the database
* An invitation flow that cannot create owners
* Database-enforced protection against duplicate invitations and memberships
* A clear, reversible lifecycle with revoke and expiry

It intentionally leaves invitation history and auditing, queued delivery,
bulk invitations, invitation reminders, and client portal invitations to later
slices.
