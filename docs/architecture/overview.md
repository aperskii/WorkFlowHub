# Architecture Overview

## Application

WorkFlowHub is a modular Laravel monolith.

The application uses:

- Laravel
- Livewire
- Tailwind CSS
- PostgreSQL

## Architecture Principles

- Multi-tenant by organization
- Server-side authorization
- Explicit business rules
- Automated testing
- Database constraints
- Clear separation of responsibilities
- Simple architecture before premature abstraction

## External Services

### Stripe

Used for:

- Subscription checkout
- Subscription lifecycle
- Billing portal
- Payment-related webhooks

### AWS

Production infrastructure will be introduced later.

Infrastructure will be managed using Terraform.

AWS architecture is intentionally not part of the initial development phase.
