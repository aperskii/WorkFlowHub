# WorkFlowHub

## Project
Multi-tenant SaaS project management platform.

## Stack
- PHP 8.4+
- Laravel
- Livewire
- Tailwind CSS
- Pest
- Laravel Pint
- PHPStan
- Database: PostgreSQL

## Architecture Rules
- Use Laravel conventions.
- Business logic must not be placed unnecessarily in Livewire components.
- All organization-owned data must be tenant-scoped.
- Authorization must use Policies/Gates.
- Use Form Requests or appropriate validation.
- Prefer small, focused classes.
- Do not introduce packages without justification.

## Testing
Every business-critical feature must have tests.

## Before modifying code
Read the relevant documentation under docs/.

## After modifying code
Run relevant tests and formatting tools.

## Important
Do not modify unrelated files.
Do not implement features that were not requested.