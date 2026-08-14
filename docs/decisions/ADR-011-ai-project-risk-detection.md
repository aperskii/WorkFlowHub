# ADR-011: AI Project Risk Detection

## Status

Accepted

## Date

2026-08-14

## Context

Managers can already see everything that determines whether a project is in
trouble — overdue tasks, unassigned work, how much is finished — but only as
individual rows on the project page. Reading the situation means scanning the
task list and holding the totals in your head.

This record covers the first slice of an **AI Copilot** capability: a button on
the project page that reads those totals and explains, in plain language,
whether the project looks at risk and why.

It is the first time the application calls a third-party AI service, so the
decisions here are about more than one button: they set the pattern for how this
application talks to a large language model, what it is willing to send, and who
pays for it.

## Decisions

### 1. Claude Haiku 4.5, Not a Larger Model

The model is `claude-haiku-4-5`, the cheapest model in the current Claude family
at $1 per million input tokens and $5 per million output tokens.

The task is narrow. The model receives ten integers and strings and writes three
sentences about them. There is no code to reason about, no long document to
follow, no multi-step plan — the arithmetic that matters (how many tasks are
overdue, what fraction is complete) is already done in SQL before the model sees
anything. What is being bought is fluent phrasing and sensible emphasis, which is
the part of the job the smallest current model already does well.

A larger model would cost five to twenty-five times as much per call and add
latency to a synchronous page interaction, in exchange for reasoning depth this
prompt cannot use. If assessments later prove consistently weak, the model is a
single environment variable (`ANTHROPIC_MODEL`) and can be raised without a code
change.

The model string is pinned in configuration rather than scattered through code,
and was verified against Anthropic's published model list rather than assumed.

### 2. A Raw HTTP Call, Not an SDK or Package

The call is a plain `POST` to Anthropic's Messages API built with Laravel's
`Http` facade, in `App\Actions\Projects\AnalyzeProjectRisk`.

This application is deliberately conservative about dependencies. A package earns
its place when it solves something genuinely hard; here the entire API surface
used is one endpoint, two headers, and a four-key JSON body. An SDK would add a
dependency to audit, update, and keep compatible with the framework, to save
roughly fifteen lines of code that any Laravel developer can already read.

The trade-off accepted is that response parsing is ours to maintain: the shape of
the `content` array and the meaning of `stop_reason` are handled by hand, and a
future API change is our problem rather than a package upgrade. That is
considered cheap because both are stable, versioned by the `anthropic-version`
header the request pins, and covered by tests.

If a later slice needs streaming, tool use, or multi-turn conversations, this
decision should be revisited — those are where an SDK starts to earn its keep.

### 3. Only Aggregate Counts Leave the Application

This is the load-bearing decision, and the one most worth defending.

Sending a project to a third party for analysis is a data export. The application
holds task titles, task descriptions, member names and email addresses, and
project descriptions — in a client-facing product, that content routinely
contains client names, commercial terms, and occasionally credentials.

**Exactly this is sent, and nothing else:**

| Field | Why it is needed |
| --- | --- |
| `project_name` | So the assessment can name the project it is describing |
| `project_status` | A planning project and an active one carry different urgency |
| `project_age_days` | Distinguishes a slow start from long-running drift |
| `total_tasks`, `completed_tasks`, `open_tasks` | The base of every judgement |
| `completion_percentage` | Progress, independent of project size |
| `overdue_tasks` | The strongest single risk signal |
| `unassigned_open_tasks` | Work nobody owns |
| `high_priority_due_within_7_days` | Pressure that has not yet become lateness |

**Explicitly excluded, by decision rather than oversight:**

* Task titles and descriptions
* Member names, email addresses, and avatars — no person is identifiable at all
* The project description
* Client names and any client record
* Time entries, comments, and attachments
* Organization identity beyond the project's own name

Task titles were considered. They would let the model say *which* work is late
rather than only how much, which is a real improvement in usefulness. They were
rejected because titles are free text written by users who have no reason to
expect them to be transmitted, and because the counts alone already support the
assessment the feature promises. The gain is incremental; the exposure is
categorical.

The whole payload is built in one method, `snapshotFor()`, and its return type is
a closed array shape. Auditing what leaves the application means reading one
method, and a test asserts the exact payload field by field, so widening it
cannot happen quietly.

**This decision should be revisited explicitly, not drifted past.** Any future
slice that wants richer output should come back to this record.

### 4. On Demand Only

Nothing is analysed on page load, on a schedule, or in a queue. An assessment is
computed when a member clicks the button, and only then.

This is a cost decision before it is a UX one. Analysing on page load would spend
money every time anyone opened any project, including the many opens that are
navigational rather than analytical, and would put a third-party HTTP call on the
critical path of a page that currently renders from the local database.

Assessments are also not stored. A stored assessment goes stale the moment a task
changes, and would then need invalidation rules of its own. Recomputing on demand
is cheap enough to be simpler.

### 5. Authorization Gates Spend, Not Access: Managers and Owners Only

Analysis is gated by a dedicated `ProjectPolicy@analyzeRisk` ability, backed by
`OrganizationRole::canAnalyzeProjectRisk()`. Owners and managers may run one;
employees may not, even though they can read the project perfectly well.

The ability is deliberately **not** `view`. Every other ability on this policy
answers "who may see or change this data"; this one answers "who may cause the
organization to be billed". Gating it on read access would mean any employee who
can open a project can spend the organization's money, and the number of people
who can open a project is by design much larger than the number who should be
able to do that.

It is also deliberately a separate role capability rather than a reuse of
`canManageProjects()`. The two happen to grant the same roles today, but they
answer different questions, and a future decision to let employees run analyses —
or to restrict analyses to owners alone — should not have to change who may
archive a project. This mirrors the existing split between `canManageProjects()`
and `canManageTasks()`.

Enforcement is server-side in the Livewire action, which re-resolves the project
through the route-bound organization before authorizing, matching every other
mutation on the page. The button is additionally hidden from employees, but that
is cosmetic only: the action authorizes again on every call, and a member demoted
after the page loaded is refused at click time.

### 6. Rate Limiting: Five Analyses Per Member Per Organization Per Minute

The framework's `RateLimiter` throttles the action, keyed on member and
organization together.

Five per minute is set against how the feature is actually used. Running an
analysis twice in a row is reasonable — read it, change some assignments, ask
again. Running it fifteen times in a minute is a held mouse button or a bored
user, not analysis. Five leaves ordinary use unobstructed while capping a single
member's worst case at roughly 5 × $0.0007 per minute.

The key combines member and organization deliberately:

* **Per member**, so one person cannot exhaust a shared allowance and lock out
  their colleagues.
* **Per organization**, so opening several projects in separate tabs does not
  multiply the allowance — the limit follows the person within the tenant rather
  than the page they happen to be on.

The limiter is only consumed when a call is actually about to be made, so a
throttled press costs nothing.

### 7. Failure Is Always Graceful, and Never Leaked

The API is a third-party network dependency on a synchronous page interaction, so
it is treated as expected to fail.

* The HTTP call has a 5-second connect timeout and a 10-second total timeout, so
  a slow response cannot hang the page.
* Every failure mode — no key configured, connection refused, timeout, non-2xx
  status, malformed body, a response with no text, or a model refusal — returns
  null from the action and is logged with the project id and the API's own error
  type.
* The user sees one message: that the analysis could not be run and to try again.
  No exception text, no HTTP status, no API error body reaches the interface.
* The rest of the page renders unaffected. A failed analysis costs the assessment
  panel, not the project page.

There is deliberately no automatic retry. A retry against a timeout doubles both
the latency the user waits through and the cost, for a failure the user can retry
themselves by pressing the button again.

### 8. The Key Is Configuration, Never Code

`ANTHROPIC_API_KEY` is read through `config/services.php` and is absent from
version control. `.env.example` documents it as an empty placeholder.

An absent key disables the feature rather than breaking the application: the
action logs and returns null, and the button reports that analysis is
unavailable. A developer without a key can run and test the whole application.

**Production is not yet wired.** The AWS deployment will need this in Secrets
Manager and exposed to the ECS task definition, which is a Terraform change that
has not been made. Until it is, the button will report unavailable in production.

### 9. Tests Never Call the Real API

The suite fakes the HTTP layer with `Http::fake()` and additionally calls
`Http::preventStrayRequests()`, so a test that accidentally bypasses the fake
fails rather than silently spending money. Real calls would be slow, would cost
money per run, would be non-deterministic against an assertion, and would require
a live key in CI where none should exist.

Covered: the happy path, the exact payload contents, the absence of excluded
fields, authorization for members, non-members, and cross-tenant access, the rate
limit including that a throttled press makes no call, and every failure path.

## This Is the First Slice of a Larger Capability

Risk detection was chosen to go first because it is the smallest useful thing
that exercises every hard decision — data minimization, authorization, cost
control, failure handling — without needing conversation state, storage, or
background processing.

Plausible later slices, **none of which are implemented or designed here**:

* **Free-form questions about a project** — needs multi-turn state and a much
  broader data exposure question than this record settles.
* **Weekly organization summaries** — needs scheduling, queueing, and a per-org
  cost model, since it would run without anyone asking.
* **Suggested task assignments** — would need member data, which decision 3
  currently forbids.

Each of these reopens decision 3, and possibly decision 5. They should be recorded
as their own decisions rather than treated as extensions of this one.

## Consequences

The application now has a third-party runtime dependency on a paid API. Project
pages still render without it, and the whole feature degrades to a message.

Spending is bounded by three independent limits: a cheap model, a small
`max_tokens` ceiling, and a per-member rate limit. At roughly 250 input and 100
output tokens per call, a single analysis costs about $0.0008 — a thousand
analyses cost under a dollar. Nothing in the design allows the application to
spend money without a person clicking a button.

The data minimization decision is enforced by a single method and asserted by
tests, which makes it hard to widen accidentally and easy to widen deliberately
once someone decides to. That is the intended balance: the constraint is a
recorded decision, not a technical obstacle.
