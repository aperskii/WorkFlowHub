<?php

use App\Actions\Projects\AnalyzeProjectRisk;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * The seeded organization, after running the seeder the given number of times.
 *
 * Invoked through `db:seed` rather than by calling `run()` directly, so the test
 * exercises the same entry point the deploy workflow and the documented command
 * both use.
 */
function seedDemo(int $times = 1): Organization
{
    for ($run = 0; $run < $times; $run++) {
        test()->seed(DemoSeeder::class);
    }

    return Organization::query()->where('slug', 'northwind-studio')->sole();
}

/*
|--------------------------------------------------------------------------
| The accounts a reviewer signs in with
|--------------------------------------------------------------------------
*/

test('every demo account can sign in with the documented password', function (string $email, OrganizationRole $role) {
    $organization = seedDemo();

    expect(Auth::attempt(['email' => $email, 'password' => 'password']))->toBeTrue();

    $user = User::query()->where('email', $email)->sole();

    // Users must verify their address, so an unverified demo account would be
    // bounced to the verification page and read as a broken login.
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->membershipFor($organization)?->role)->toBe($role);
})->with([
    'owner' => ['owner@smoke.test', OrganizationRole::Owner],
    'manager' => ['manager@smoke.test', OrganizationRole::Manager],
    'employee' => ['employee@smoke.test', OrganizationRole::Employee],
]);

/*
|--------------------------------------------------------------------------
| A lived-in organization rather than an empty shell
|--------------------------------------------------------------------------
*/

test('the demo organization has members, projects, tasks, and an open invitation', function () {
    $organization = seedDemo();

    expect($organization->memberships()->count())->toBe(3)
        ->and($organization->projects()->count())->toBe(4)
        ->and(Task::query()->count())->toBeGreaterThanOrEqual(20)
        ->and($organization->invitations()->outstanding()->count())->toBe(1);

    $invitation = $organization->invitations()->sole();

    expect($invitation->status())->toBe(InvitationStatus::Pending)
        ->and($invitation->role)->toBe(OrganizationRole::Manager);
});

test('every seeded project belongs to the demo organization', function () {
    $organization = seedDemo();

    expect(Project::query()->where('organization_id', '!=', $organization->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The spread the risk analysis is meant to demonstrate
|--------------------------------------------------------------------------
*/

test('the at-risk project carries every signal the risk analysis reads', function () {
    $organization = seedDemo();

    $project = $organization->projects()->where('slug', 'harborview-onboarding-revamp')->sole();
    $snapshot = (new AnalyzeProjectRisk)->snapshotFor($project);

    expect($snapshot['completion_percentage'])->toBeLessThan(30)
        ->and($snapshot['overdue_tasks'])->toBeGreaterThanOrEqual(3)
        ->and($snapshot['unassigned_open_tasks'])->toBeGreaterThanOrEqual(3)
        ->and($snapshot['high_priority_due_within_7_days'])->toBeGreaterThanOrEqual(1)
        ->and($snapshot['project_age_days'])->toBeGreaterThan(0);
});

test('the healthy project carries none of them', function () {
    $organization = seedDemo();

    $project = $organization->projects()->where('slug', 'client-portal-redesign')->sole();
    $snapshot = (new AnalyzeProjectRisk)->snapshotFor($project);

    expect($snapshot['completion_percentage'])->toBeGreaterThanOrEqual(70)
        ->and($snapshot['overdue_tasks'])->toBe(0)
        ->and($snapshot['unassigned_open_tasks'])->toBe(0)
        ->and($snapshot['high_priority_due_within_7_days'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Safe to re-run
|--------------------------------------------------------------------------
*/

test('running the seeder twice neither errors nor duplicates anything', function () {
    $once = seedDemo();

    $counts = [
        'users' => User::query()->count(),
        'organizations' => Organization::query()->count(),
        'memberships' => $once->memberships()->count(),
        'projects' => $once->projects()->count(),
        'tasks' => Task::query()->count(),
        'invitations' => Invitation::query()->count(),
    ];

    $twice = seedDemo();

    expect(User::query()->count())->toBe($counts['users'])
        ->and(Organization::query()->count())->toBe($counts['organizations'])
        ->and($twice->memberships()->count())->toBe($counts['memberships'])
        ->and($twice->projects()->count())->toBe($counts['projects'])
        ->and(Task::query()->count())->toBe($counts['tasks'])
        ->and(Invitation::query()->count())->toBe($counts['invitations'])
        ->and($twice->id)->toBe($once->id);
});

test('a re-run restores the documented password and leaves the invitation pending', function () {
    seedDemo();

    $owner = User::query()->where('email', 'owner@smoke.test')->sole();
    $owner->forceFill(['password' => 'something-else'])->save();

    Invitation::query()->sole()->forceFill(['expires_at' => now()->subMonth()])->save();

    $organization = seedDemo();

    expect(Auth::attempt(['email' => 'owner@smoke.test', 'password' => 'password']))->toBeTrue()
        ->and($organization->invitations()->sole()->isPending())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Never reachable by accident
|--------------------------------------------------------------------------
*/

test('the default seeder does not seed publicly known credentials', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'like', '%@smoke.test')->exists())->toBeFalse()
        ->and(Organization::query()->where('slug', 'northwind-studio')->exists())->toBeFalse();
});
