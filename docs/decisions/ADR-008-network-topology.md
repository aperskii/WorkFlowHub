# ADR-008: Development Network Topology

## Status

Accepted

## Date

2026-08-11

## Context

ADR-007 produced a container image but nothing to run it on. Before the database,
the container platform, or the load balancer can exist, they need a network to sit
in.

This record covers the first real infrastructure for the project, in
`infra/terraform/dev/`. It is separate from `infra/terraform/level-1-learning/`,
which was a disposable exercise and remains untouched.

Two constraints shape everything below. The environment runs on a fixed credit
balance of roughly €100, so recurring cost is a design input rather than an
afterthought. And it is a development environment, destroyed at the end of each
working session, which changes what is worth paying for.

Every resource created here is free. VPCs, subnets, route tables, internet
gateways, and security groups carry no charge in themselves.

## Decisions

### 1. Two Subnet Tiers, Not Three

The VPC is `10.0.0.0/16`, spread across two availability zones, with two tiers:

| Tier | CIDRs | Route to internet |
|---|---|---|
| Public | `10.0.0.0/20`, `10.0.16.0/20` | Yes, via the internet gateway |
| Isolated | `10.0.128.0/20`, `10.0.144.0/20` | None |

The conventional design has three tiers: public for load balancers, private for
applications, and isolated for data. The private tier is omitted here, and §2
explains why.

The gap between `10.0.16.0/20` and `10.0.128.0/20` is deliberate. It leaves room
to add a private tier later without renumbering anything that already exists.

Two availability zones is the minimum an Application Load Balancer accepts. The
zones are read from the account with `data "aws_availability_zones"` rather than
named literally, because which zones an account may use varies between accounts
in the same region.

`10.0.0.0/16` avoids `172.17.0.0/16`, which Docker uses for its default bridge.
Overlapping those ranges breaks connectivity in confusing ways on any host
running both.

### 2. No NAT Gateway: the Application Tier Is Public

The application tasks run in the public subnets with public IP addresses, and
reach the internet directly through the internet gateway.

The alternative is a private tier whose outbound traffic passes through a NAT
Gateway. That is the standard design and it is genuinely better, but it costs
roughly $32 per month per gateway, charged by the hour from creation, plus data
processing. Two gateways for two zones doubles it. Against a €100 balance that
is between a third and two thirds of the budget spent on network plumbing for an
environment that is deleted every evening.

**The trade-off is real and worth stating plainly.** The tasks have publicly
routable IP addresses. Nothing stands between them and the internet at the
network layer. What protects them is the security group: the application group
accepts inbound traffic only from the load balancer's security group, on one
port. That rule is doing work that network isolation would otherwise do.

Concretely, this means:

* A misconfigured security group exposes the application directly to the
  internet. In a private-subnet design the same mistake exposes it only to the
  VPC, because there is no route from outside.
* There is one control rather than two. Defence in depth is reduced to defence.
* Outbound traffic is unrestricted, so a compromised task can reach any host on
  the internet. A NAT Gateway would not prevent that either, so nothing is lost
  here relative to the alternative.

The mitigation is that the rule is written to be checkable. The application
group's only inbound rule references the load balancer's *security group*, not
an address range, so it cannot accidentally widen to a CIDR. It is one rule, in
one file, and reviewing it is the whole audit.

This decision is appropriate for a development environment on a fixed budget and
is **not** appropriate for production handling real user data. A production
deployment should add the private tier and the NAT Gateways, which is why the
address space leaves room for it.

### 3. The Database Tier Is Genuinely Isolated, and §2 Does Not Weaken It

The isolated subnets have a route table with no route to the internet gateway.
AWS adds an unmanaged `local` route covering the VPC range to every route table,
which is how the application reaches the database; nothing else is added, so
there is no path out of the VPC in either direction.

The database security group allows inbound PostgreSQL from the application's
security group only, and declares no egress at all. AWS attaches an allow-all
egress rule to every new security group; Terraform removes it when the group
declares none of its own, leaving the database unable to open outbound
connections. It has no reason to: a database answers connections, it does not
start them.

This tier costs nothing to isolate properly, because it needs no internet access
to function. The compromise in §2 was made to avoid paying for outbound
connectivity that the application genuinely needs. The database needs none, so
there is nothing to trade away, and it gets the stronger design for free.

### 4. Security Groups Reference Each Other, Not Address Ranges

Three groups form a chain: the internet may reach the load balancer, the load
balancer may reach the application, and the application may reach the database.

The two internal links are expressed with `referenced_security_group_id` rather
than a CIDR. Membership of a group authorises the traffic, so whichever address
a task receives when it starts, the rule still holds. With the application tier
in public subnets and addresses assigned per task, an address-based rule would
be both wrong and unmaintainable.

Rules are separate resources rather than blocks inside the group. Inline rules
are replaced as a set on every change, and two groups that reference each other
cannot be expressed inline at all without a dependency cycle.

The application port is 8080, not 80. That is the port nginx binds in the
container image, which listens unprivileged and therefore cannot use a port
below 1024 (ADR-007 §3).

### 5. Remote State Is Deferred, Not Skipped

State is a local file. There is no S3 backend and no DynamoDB lock table.

Remote state solves two problems: several people changing the same
infrastructure concurrently, and state surviving the loss of one machine.
Neither applies yet. One person works on this, and the environment is destroyed
at the end of each session, so there is no long-lived state to protect — the
`.tf` files in git are the durable artifact.

There is also an ordering problem. An S3 backend needs a bucket, and creating
that bucket with Terraform means storing its state somewhere before the backend
exists. That is soluble but it is a step of its own, and doing it here would
mean solving it before there is anything to protect.

The cost of deferring is that the local state file must not be lost while
resources exist, or Terraform loses track of what it created and the resources
have to be deleted by hand. Destroying at the end of each session keeps that
window short. `.gitignore` already excludes state files, which must never be
committed: they record every resource attribute in plain text, including
generated passwords.

## Consequences

The project has a network that later steps can attach to. The outputs — VPC ID,
subnet IDs, and the three security group IDs — are the interface the database,
container platform, and load balancer will consume.

Nothing created here costs money. The first recurring charge arrives with the
database, and the second with the load balancer.

Two decisions are explicitly provisional and both are recorded here rather than
left implicit. §2 trades network isolation for roughly $32 per month and should
be revisited before any real user data exists. §5 holds only while one person
works on a disposable environment.

The address space accommodates the private tier that §2 declines to build, so
adopting it later is additive rather than a renumbering exercise.
