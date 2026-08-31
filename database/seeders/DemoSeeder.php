<?php

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Populates the disposable demo environment with a lived-in organization.
 *
 * ---------------------------------------------------------------------------
 * SAFETY: this seeder creates accounts whose password is publicly documented.
 *
 * Every account below is `password`, and the addresses are printed in the
 * README so a reviewer can sign in. Anyone who can reach the application can
 * therefore sign in as an owner. NEVER RUN THIS AGAINST A DATABASE THAT SERVES
 * REAL USERS — it would hand an owner account to the whole internet, and it
 * would write demo projects into a real tenant's data.
 *
 * The only protection is that it must be asked for by name:
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * It is deliberately not referenced from `DatabaseSeeder`, so `db:seed` with no
 * arguments, `migrate --seed`, and the test suite can never reach it. The one
 * automated caller is the "Seed demo data" step in `.github/workflows/deploy.yml`,
 * which targets the `dev` environment described in `infra/terraform/dev` — a
 * demo environment that is created and destroyed by hand and holds nothing real.
 *
 * There is intentionally no `app()->isProduction()` guard: that environment runs
 * with `APP_ENV=production` (ADR-007), so such a guard would block the only
 * legitimate automated caller while doing nothing about the case that actually
 * matters — someone typing the command at a real database. See ADR-012.
 * ---------------------------------------------------------------------------
 *
 * Re-running is safe. Every record is matched on its natural key and brought
 * back to the state described here, so a second run neither duplicates nor
 * errors, and refreshes the relative due dates the risk analysis reads.
 */
class DemoSeeder extends Seeder
{
    /**
     * The password shared by every demo account. See the safety note above.
     */
    private const string PASSWORD = 'password';

    private const string ORGANIZATION_NAME = 'Northwind Studio';

    private const string ORGANIZATION_SLUG = 'northwind-studio';

    /**
     * Seed the demo organization, its members, its work, and one open invitation.
     */
    public function run(): void
    {
        $organization = $this->seedOrganization();
        $members = $this->seedMembers($organization);

        foreach ($this->projectScenarios() as $scenario) {
            $this->seedProject($organization, $members, $scenario);
        }

        $this->seedPendingInvitation($organization, $members[OrganizationRole::Owner->value]);

        $this->report($organization);
    }

    /**
     * Create or reuse the single demo organization.
     */
    private function seedOrganization(): Organization
    {
        return Organization::query()->updateOrCreate(
            ['slug' => self::ORGANIZATION_SLUG],
            ['name' => self::ORGANIZATION_NAME],
        );
    }

    /**
     * Create the three known accounts and their memberships.
     *
     * Keyed by role value so the project scenarios can name an assignee without
     * knowing anything about the people.
     *
     * @return array<string, User>
     */
    private function seedMembers(Organization $organization): array
    {
        $people = [
            OrganizationRole::Owner->value => ['owner@smoke.test', 'Dana Whitfield'],
            OrganizationRole::Manager->value => ['manager@smoke.test', 'Marcus Oyelaran'],
            OrganizationRole::Employee->value => ['employee@smoke.test', 'Priya Raghunathan'],
        ];

        $members = [];

        foreach ($people as $role => [$email, $name]) {
            $user = $this->seedUser($email, $name);

            Membership::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                ['role' => OrganizationRole::from($role)],
            );

            $members[$role] = $user;
        }

        return $members;
    }

    /**
     * Create a demo account, or restore an existing one to its known state.
     *
     * The factory owns the defaults — it is the one place in the project that
     * knows a user needs a hashed password and a verified address. A re-run
     * re-applies both, so a password changed during a demo does not leave the
     * documented credentials wrong.
     */
    private function seedUser(string $email, string $name): User
    {
        $user = User::query()->firstWhere('email', $email);

        if ($user === null) {
            return User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
            ]);
        }

        $user->forceFill([
            'name' => $name,
            'password' => self::PASSWORD,
        ])->save();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    /**
     * Create or refresh one project and the tasks that give it its health.
     *
     * @param  array<string, User>  $members
     * @param  array{
     *     name: string,
     *     slug: string,
     *     description: string,
     *     status: ProjectStatus,
     *     age_days: int,
     *     tasks: list<array{
     *         title: string,
     *         status: TaskStatus,
     *         priority: TaskPriority,
     *         due_in_days: int|null,
     *         assignee: string|null,
     *     }>,
     * }  $scenario
     */
    private function seedProject(Organization $organization, array $members, array $scenario): void
    {
        $project = $organization->projects()->updateOrCreate(
            ['slug' => $scenario['slug']],
            [
                'name' => $scenario['name'],
                'description' => $scenario['description'],
                'status' => $scenario['status'],
            ],
        );

        // The risk analysis reads the project's age, so a project created today
        // cannot also have work that has been late for a fortnight. created_at
        // is not fillable, by design, so it is written directly.
        $project->forceFill([
            'created_at' => Carbon::today()->subDays($scenario['age_days'])->setTime(9, 0),
        ])->save();

        foreach ($scenario['tasks'] as $task) {
            $project->tasks()->updateOrCreate(
                ['title' => $task['title']],
                [
                    'status' => $task['status'],
                    'priority' => $task['priority'],
                    'due_date' => $task['due_in_days'] === null
                        ? null
                        : Carbon::today()->addDays($task['due_in_days']),
                    'assigned_to_user_id' => $task['assignee'] === null
                        ? null
                        : $members[$task['assignee']]->id,
                ],
            );
        }
    }

    /**
     * Leave one invitation outstanding so the members page has one to show.
     *
     * The raw token is discarded rather than reported: the invitation exists to
     * demonstrate the pending state, and an acceptance URL in a deploy log is
     * one more credential than this seeder needs to hand out.
     */
    private function seedPendingInvitation(Organization $organization, User $invitedBy): void
    {
        $organization->invitations()->updateOrCreate(
            ['email' => Invitation::normalizeEmail('nina.calder@northwindstudio.test')],
            [
                'role' => OrganizationRole::Manager,
                'token_hash' => Invitation::hashToken(Invitation::generateToken()),
                'expires_at' => now()->addDays(config('auth.invitation_expires_after_days')),
                'accepted_at' => null,
                'revoked_at' => null,
                'invited_by_user_id' => $invitedBy->id,
            ],
        );
    }

    /**
     * Describe the projects to seed.
     *
     * The spread is deliberate rather than random: the risk analysis (ADR-011)
     * judges a project on completion rate, overdue work, unowned work, and
     * imminent high-priority work, so there is one project that is plainly
     * healthy on all four, one that is plainly in trouble, and two in between.
     * Due dates are relative to the day of the run, which keeps "overdue" and
     * "due this week" true whenever the environment is deployed.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     description: string,
     *     status: ProjectStatus,
     *     age_days: int,
     *     tasks: list<array{
     *         title: string,
     *         status: TaskStatus,
     *         priority: TaskPriority,
     *         due_in_days: int|null,
     *         assignee: string|null,
     *     }>,
     * }>
     */
    private function projectScenarios(): array
    {
        $owner = OrganizationRole::Owner->value;
        $manager = OrganizationRole::Manager->value;
        $employee = OrganizationRole::Employee->value;

        return [
            // Healthy: 75% complete, nothing late, nothing unowned.
            [
                'name' => 'Client Portal Redesign',
                'slug' => 'client-portal-redesign',
                'description' => 'Rebuild the customer-facing portal on the new design system, starting with the beta cohort.',
                'status' => ProjectStatus::Active,
                'age_days' => 64,
                'tasks' => [
                    ['title' => 'Audit the existing portal navigation', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -40, 'assignee' => $manager],
                    ['title' => 'Define the new information architecture', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'due_in_days' => -33, 'assignee' => $manager],
                    ['title' => 'Design the dashboard layout', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -26, 'assignee' => $employee],
                    ['title' => 'Build the responsive shell', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -19, 'assignee' => $employee],
                    ['title' => 'Migrate the document download flow', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'due_in_days' => -12, 'assignee' => $employee],
                    ['title' => 'Accessibility pass on the new components', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -5, 'assignee' => $manager],
                    ['title' => 'Write the portal onboarding copy', 'status' => TaskStatus::InReview, 'priority' => TaskPriority::Low, 'due_in_days' => 6, 'assignee' => $manager],
                    ['title' => 'Ship the redesign to the beta cohort', 'status' => TaskStatus::InProgress, 'priority' => TaskPriority::Medium, 'due_in_days' => 11, 'assignee' => $employee],
                ],
            ],

            // At risk: 17% complete, five tasks late, six unowned, two
            // high-priority tasks falling due inside the week.
            [
                'name' => 'Harborview Onboarding Revamp',
                'slug' => 'harborview-onboarding-revamp',
                'description' => 'Rework the Harborview signup funnel end to end, from the first email to the account setup wizard.',
                'status' => ProjectStatus::Active,
                'age_days' => 47,
                'tasks' => [
                    ['title' => 'Map the current signup funnel', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -30, 'assignee' => $manager],
                    ['title' => 'Interview five recent customers', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -24, 'assignee' => $manager],
                    ['title' => 'Rewrite the welcome email sequence', 'status' => TaskStatus::InProgress, 'priority' => TaskPriority::High, 'due_in_days' => -14, 'assignee' => $employee],
                    ['title' => 'Rebuild the account setup wizard', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Urgent, 'due_in_days' => -9, 'assignee' => null],
                    ['title' => 'Replace the legacy verification screen', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'due_in_days' => -6, 'assignee' => null],
                    ['title' => 'Instrument funnel drop-off analytics', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'due_in_days' => -3, 'assignee' => $employee],
                    ['title' => 'Hand off the billing copy to the client', 'status' => TaskStatus::InReview, 'priority' => TaskPriority::High, 'due_in_days' => -1, 'assignee' => $manager],
                    ['title' => 'Finalise the pricing table content', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Urgent, 'due_in_days' => 2, 'assignee' => null],
                    ['title' => 'Load test the new wizard', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'due_in_days' => 5, 'assignee' => null],
                    ['title' => 'Draft the launch announcement', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Low, 'due_in_days' => 9, 'assignee' => null],
                    ['title' => 'Update the help centre articles', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'due_in_days' => null, 'assignee' => null],
                    ['title' => 'Schedule the client walkthrough', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'due_in_days' => 14, 'assignee' => $manager],
                ],
            ],

            // Middling: moving, but one task has slipped and one has no owner.
            [
                'name' => 'Meridian Analytics Dashboard',
                'slug' => 'meridian-analytics-dashboard',
                'description' => 'A reporting dashboard for Meridian covering weekly activity, retention, and exportable summaries.',
                'status' => ProjectStatus::Active,
                'age_days' => 30,
                'tasks' => [
                    ['title' => 'Agree the metric definitions with the client', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'due_in_days' => -21, 'assignee' => $manager],
                    ['title' => 'Model the reporting tables', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -15, 'assignee' => $employee],
                    ['title' => 'Build the date range picker', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'due_in_days' => -8, 'assignee' => $employee],
                    ['title' => 'Chart the weekly active users panel', 'status' => TaskStatus::InProgress, 'priority' => TaskPriority::Medium, 'due_in_days' => -2, 'assignee' => $employee],
                    ['title' => 'Add CSV export to every report', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'due_in_days' => 4, 'assignee' => $employee],
                    ['title' => 'Cache the heavy aggregate queries', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'due_in_days' => 10, 'assignee' => null],
                    ['title' => 'Write the dashboard user guide', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Low, 'due_in_days' => 17, 'assignee' => $owner],
                ],
            ],

            // Early: nothing finished, but nothing late either — a project that
            // has only just been scoped.
            [
                'name' => 'Ridgeway Coffee Site Build',
                'slug' => 'ridgeway-coffee-site-build',
                'description' => 'A brochure and storefront site for Ridgeway Coffee, pending a decision on the ecommerce integration.',
                'status' => ProjectStatus::Planning,
                'age_days' => 6,
                'tasks' => [
                    ['title' => 'Collect brand assets from the client', 'status' => TaskStatus::InProgress, 'priority' => TaskPriority::Medium, 'due_in_days' => 3, 'assignee' => $manager],
                    ['title' => 'Draft the sitemap', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'due_in_days' => 7, 'assignee' => $employee],
                    ['title' => 'Scope the ecommerce integration', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'due_in_days' => 12, 'assignee' => null],
                    ['title' => 'Agree the launch date with Ridgeway', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'due_in_days' => null, 'assignee' => $owner],
                    ['title' => 'Estimate the build in story points', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Low, 'due_in_days' => 18, 'assignee' => null],
                ],
            ],
        ];
    }

    /**
     * Print what was seeded, so a deploy log says what can now be signed in to.
     */
    private function report(Organization $organization): void
    {
        $projects = $organization->projects()->withCount('tasks')->orderBy('name')->get();

        $this->command->info(sprintf(
            'Seeded "%s" with %d members, %d projects, and %d tasks.',
            $organization->name,
            $organization->memberships()->count(),
            $projects->count(),
            $projects->sum('tasks_count'),
        ));

        $this->command->info('Demo accounts (password: '.self::PASSWORD.'):');

        foreach ($organization->memberships()->with('user')->get() as $membership) {
            $this->command->info("  {$membership->user->email} — {$membership->role->label()}");
        }
    }
}
