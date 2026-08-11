# ADR-009: Terraform State Boundaries

## Status

Accepted

## Date

2026-08-11

## Context

ADR-008 established the development network in `infra/terraform/dev/` and, in §5,
deferred remote state on the grounds that the environment is destroyed at the end
of every working session and therefore has nothing worth preserving between them.

The container registry breaks that assumption. It is the first resource in the
project that is meant to outlive a session: it holds the image ECS pulls, and
re-pushing roughly 311 MB at the start of each session wastes time for no
benefit. An empty repository also costs nothing to keep, unlike the database and
the load balancer, which bill by the hour merely for existing.

The registry was initially written into `infra/terraform/dev/` alongside the VPC
and the database. That turned out not to work, for a reason worth recording.

## Decisions

### 1. Resources That Outlive a Session Get Their Own State

`infra/terraform/` now holds three independent configurations:

| Directory | Contents | Lifecycle |
|---|---|---|
| `shared/` | ECR repository and its lifecycle policy | Persists between sessions |
| `dev/` | VPC, subnets, routing, security groups, RDS | Created and destroyed each session |
| `level-1-learning/` | A single S3 bucket | Completed exercise, retained as a record |

Each has its own state file and its own `terraform init`. Nothing is shared
between them except the AWS account they target.

**`terraform destroy` operates on an entire state.** There is no supported way to
mark one resource as exempt. `-target` can restrict a run to named resources, but
using it routinely to protect something is fragile: it has to be remembered every
time, it is easy to mistype, and Terraform documents it as a tool for recovering
from mistakes rather than for normal operation.

The alternative, `lifecycle { prevent_destroy = true }`, is worse in this
specific case. It does not exempt the resource; it makes the whole destroy fail.
Terraform stops on that error, so a session-end teardown would abort part-way —
plausibly before deleting the database, which then keeps running and billing
overnight. A guard that causes the expensive resource to survive is the opposite
of what it is for.

Separating the state removes the problem rather than defending against it.
`terraform destroy` in `dev/` cannot touch the registry because the registry is
not in that state.

### 2. Values Cross State Boundaries Through the AWS API, Not Through State

`dev/` needs the repository URL now that ECS pulls from it, and that value is
produced by another state.

It is read with a `data "aws_ecr_repository"` lookup by repository name, which
queries the AWS API. Two alternatives were rejected:

* **`terraform_remote_state`.** This couples `dev/` to the internal layout of
  `shared/` — its output names become an interface that cannot change without
  breaking the other configuration — and requires `dev/` to hold read access to
  the other state file. Both reintroduce the dependency this ADR exists to
  remove.
* **A plain variable holding the URL.** A repository URL embeds the account ID
  (`<account>.dkr.ecr.<region>.amazonaws.com/<name>`), so a default value would
  hardcode the account into a file in version control, and supplying it at plan
  time makes every invocation need the right `-var`.

The data source has neither problem. AWS is the source of truth, only the
repository *name* crosses the boundary, and the account ID is resolved at plan
time. The two configurations stay independently plannable, which was the
property being protected.

The cost is that `dev/` cannot be planned before the repository exists. That
ordering is real regardless: a task definition pointing at a repository that
does not exist could not run.

### 3. The Registry Keeps `force_delete`

`aws_ecr_repository.app` retains `force_delete = true` even though it no longer
shares state with the database.

The original reason was to stop a destroy failing part-way and leaving the
database running. That reason is gone, but a second one remains: without it,
`terraform destroy` in `shared/` fails whenever the repository holds images,
which is almost always. Losing the images costs a rebuild and a push. A destroy
that half-succeeds is harder to reason about than one that completes.

## Consequences

Two `terraform init` runs and two state files now exist where there was one, and
a change spanning both requires two applies. That is the cost of the boundary.

The rule that produced this split generalises: **a Terraform state should contain
resources that share a lifecycle.** Anything else added later that outlives a
session — a remote state backend, a domain name, an ACM certificate that takes
time to validate — belongs in `shared/` rather than in `dev/`, for the same
reason.

ADR-008 §5's reasoning for deferring remote state still holds for `dev/`, whose
state is empty between sessions. It holds less well for `shared/`, whose state now
describes a resource that genuinely persists: losing that state file means the
repository still exists but Terraform no longer knows about it, and it would have
to be imported or deleted by hand. That is a small risk while the state is one
repository, and it is the first concrete argument for the remote backend ADR-008
deferred.
