# ADR-001: Initial Foundation Decisions

## Status

Accepted

## Date

2026-08-10

## Context

WorkFlowHub is being built as a production-quality portfolio SaaS application using Laravel and Livewire.

The application needs a clear technical foundation before implementing the first business domain.

## Decisions

### 1. Database

PostgreSQL will be used as the primary database for:

* Local development
* Automated testing
* Production

SQLite will not be used as the primary application database.

### 2. Multi-Tenancy

WorkFlowHub uses organization-based multi-tenancy.

A user may belong to multiple organizations.

Organization-specific roles are represented through memberships.

### 3. Client Authentication

Clients will use the same application authentication system as other users.

A client is not an organization member and will have a separate authorization model for accessing the Client Portal.

### 4. Architecture

The application will remain a modular Laravel monolith.

We will prefer Laravel conventions and clear separation of responsibilities over a highly abstract Domain-Driven Design structure.

Unnecessary repositories, DTOs, contracts, or abstractions will not be introduced without a concrete reason.

### 5. Authentication

The MVP includes:

* Registration
* Email verification
* Login
* Logout
* Password reset

The MVP does not include:

* Two-factor authentication
* Passkeys

Unused authentication scaffolding should be removed.

### 6. Billing

Stripe billing will be implemented after the core organization and project domains are stable.

The Stripe integration approach will be decided when the billing domain is designed.

## Consequences

These decisions prioritize:

* Maintainability
* Clear Laravel conventions
* Strong tenant isolation
* Production-like infrastructure
* A manageable MVP scope
* Demonstrable engineering decisions for technical interviews
