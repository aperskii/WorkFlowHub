<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the organization dashboard as the given user.
 */
function dashboardAs(User $user, Organization $organization): Testable
{
    return Livewire::actingAs($user)
        ->test('pages::organizations.dashboard', ['organization' => $organization]);
}

/*
|--------------------------------------------------------------------------
| Role-aware layout
|--------------------------------------------------------------------------
*/

test('owners and managers see the organization wide metrics', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $actor = memberWithRole($organization, $role);

    dashboardAs($actor, $organization)
        ->assertSee('data-test="organization-metrics"', escape: false)
        ->assertDontSee('data-test="personal-metrics"', escape: false)
        ->assertSee('Open tasks')
        ->assertSee('Overdue');
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
]);

test('an employee sees their own workload instead of organization metrics', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    dashboardAs($employee, $organization)
        ->assertSee('data-test="personal-metrics"', escape: false)
        ->assertDontSee('data-test="organization-metrics"', escape: false)
        ->assertSee('My open tasks')
        ->assertSee('My overdue tasks');
});

test('the dashboard shows the viewer\'s own role', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $actor = memberWithRole($organization, $role);

    dashboardAs($actor, $organization)
        ->assertSee('data-test="organization-role"', escape: false)
        ->assertSee($role->label());
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

/*
|--------------------------------------------------------------------------
| Primary actions follow the existing policies
|--------------------------------------------------------------------------
*/

test('owners and managers get create actions', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $actor = memberWithRole($organization, $role);

    dashboardAs($actor, $organization)
        ->assertSee('data-test="dashboard-new-project"', escape: false)
        ->assertSee('data-test="dashboard-invite-member"', escape: false);
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
]);

test('an employee is offered no create actions', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    dashboardAs($employee, $organization)
        ->assertDontSee('data-test="dashboard-new-project"', escape: false)
        ->assertDontSee('data-test="dashboard-invite-member"', escape: false);
});

/*
|--------------------------------------------------------------------------
| Overdue
|--------------------------------------------------------------------------
*/

test('the overdue metric counts only open work past its due date', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->todo()->dueOn(today()->subDays(2)->toDateString())->count(3)->create();
    Task::factory()->for($project)->todo()->dueOn(today()->addWeek()->toDateString())->create();
    Task::factory()->for($project)->done()->dueOn(today()->subMonth()->toDateString())->create();
    Task::factory()->for($project)->todo()->create(['due_date' => null]);

    expect(dashboardAs($owner, $organization)->instance()->overdueTaskCount())->toBe(3);
});

test('the needs attention panel is always available to a manager', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    dashboardAs($owner, $organization)
        ->assertSee('data-test="needs-attention"', escape: false)
        ->assertSee('Needs attention')
        ->assertSee('No tasks need your attention');
});

test('needs attention covers late, imminent, and unowned work', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->todo()->assignedTo($owner)
        ->dueOn(today()->subDay()->toDateString())->create(['title' => 'Already late']);

    Task::factory()->for($project)->todo()->assignedTo($owner)
        ->dueOn(today()->addDays(2)->toDateString())->create(['title' => 'Falling due']);

    Task::factory()->for($project)->todo()
        ->create(['title' => 'Nobody owns this', 'assigned_to_user_id' => null]);

    dashboardAs($owner, $organization)
        ->assertSee('Already late')
        ->assertSee('Falling due')
        ->assertSee('Nobody owns this');
});

test('needs attention leaves alone work that is owned, dated far out, and open', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->todo()->assignedTo($owner)
        ->dueOn(today()->addMonth()->toDateString())->create(['title' => 'Plenty of time']);

    Task::factory()->for($project)->done()->assignedTo($owner)
        ->dueOn(today()->subMonth()->toDateString())->create(['title' => 'Late but finished']);

    $component = dashboardAs($owner, $organization);

    expect($component->instance()->attentionCount())->toBe(0);

    $component->assertSee('No tasks need your attention');
});

test('unassigned open work is surfaced to managers', function () {
    $organization = Organization::factory()->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->todo()->count(2)->create(['assigned_to_user_id' => null]);
    Task::factory()->for($project)->done()->create(['assigned_to_user_id' => null]);

    $component = dashboardAs($manager, $organization);

    expect($component->instance()->unassignedTaskCount())->toBe(2);

    $component->assertSee('data-test="unassigned-task-summary"', escape: false);
});

test('an employee never sees the needs attention panel', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->todo()->dueOn(today()->subDay()->toDateString())->count(3)->create();

    dashboardAs($employee, $organization)->assertDontSee('data-test="needs-attention"', escape: false);
});

/*
|--------------------------------------------------------------------------
| Assigned to me
|--------------------------------------------------------------------------
*/

test('assigned to me lists only the viewer\'s open tasks', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->assignedTo($employee)->todo()->create(['title' => 'Mine to do']);
    Task::factory()->for($project)->assignedTo($employee)->done()->create(['title' => 'Mine finished']);
    Task::factory()->for($project)->assignedTo($colleague)->todo()->create(['title' => 'Theirs to do']);
    Task::factory()->for($project)->todo()->create(['title' => 'Nobody\'s', 'assigned_to_user_id' => null]);

    dashboardAs($employee, $organization)
        ->assertSee('Mine to do')
        ->assertDontSee('Mine finished')
        ->assertDontSee('Theirs to do')
        ->assertDontSee('Nobody\'s');
});

test('assigned to me orders soonest due first and puts undated work last', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->assignedTo($employee)->todo()->create(['due_date' => null, 'title' => 'Undated']);
    Task::factory()->for($project)->assignedTo($employee)->todo()->dueOn(today()->addMonth()->toDateString())->create(['title' => 'Later']);
    Task::factory()->for($project)->assignedTo($employee)->todo()->dueOn(today()->addDay()->toDateString())->create(['title' => 'Sooner']);

    $titles = dashboardAs($employee, $organization)
        ->instance()
        ->myTasks()
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Sooner', 'Later', 'Undated']);
});

test('assigned to me shows an empty state when the viewer has nothing', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    dashboardAs($employee, $organization)
        ->assertSee('data-test="assigned-to-me-empty"', escape: false)
        ->assertSee('Nothing is assigned to you right now.');
});

test('a manager only sees the assigned to me panel when they have work', function () {
    $organization = Organization::factory()->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);
    $project = Project::factory()->for($organization)->create();

    dashboardAs($manager, $organization)->assertDontSee('data-test="assigned-to-me"', escape: false);

    Task::factory()->for($project)->assignedTo($manager)->todo()->create();

    dashboardAs($manager, $organization)->assertSee('data-test="assigned-to-me"', escape: false);
});

test('the personal counts only cover the viewer', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->create();

    Task::factory()->for($project)->assignedTo($employee)->todo()->count(2)->create();
    Task::factory()->for($project)->assignedTo($employee)->todo()->dueOn(today()->subDay()->toDateString())->create();
    Task::factory()->for($project)->assignedTo($colleague)->todo()->count(5)->create();

    $dashboard = dashboardAs($employee, $organization)->instance();

    expect($dashboard->myOpenTaskCount())->toBe(3)
        ->and($dashboard->myOverdueTaskCount())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Tenant isolation on every new query
|--------------------------------------------------------------------------
*/

test('every dashboard figure ignores another organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    $user = User::factory()->create();
    Membership::factory()->for($organization)->for($user)->owner()->create();
    Membership::factory()->for($other)->for($user)->owner()->create();

    $project = Project::factory()->for($organization)->active()->create();
    Task::factory()->for($project)->todo()->assignedTo($user)->create();

    $otherProject = Project::factory()->for($other)->active()->create();
    Task::factory()->for($otherProject)->todo()->assignedTo($user)->count(6)->create();
    Task::factory()->for($otherProject)->todo()->dueOn(today()->subDay()->toDateString())->count(4)->create();
    Task::factory()->for($otherProject)->done()->count(3)->create();
    Task::factory()->for($otherProject)->todo()->count(2)->create(['assigned_to_user_id' => null]);

    $dashboard = dashboardAs($user, $organization)->instance();

    expect($dashboard->activeProjectCount())->toBe(1)
        ->and($dashboard->projectCount())->toBe(1)
        ->and($dashboard->openTaskCount())->toBe(1)
        ->and($dashboard->completedTaskCount())->toBe(0)
        ->and($dashboard->overdueTaskCount())->toBe(0)
        ->and($dashboard->unassignedTaskCount())->toBe(0)
        ->and($dashboard->myOpenTaskCount())->toBe(1)
        ->and($dashboard->myOverdueTaskCount())->toBe(0)
        ->and($dashboard->myTasks())->toHaveCount(1)
        ->and($dashboard->dueSoonTaskCount())->toBe(0)
        ->and($dashboard->attentionCount())->toBe(0)
        ->and($dashboard->attentionTasks())->toHaveCount(0);
});

test('another tenant\'s task titles never reach the dashboard', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    $user = User::factory()->create();
    Membership::factory()->for($organization)->for($user)->owner()->create();
    Membership::factory()->for($other)->for($user)->owner()->create();

    Task::factory()
        ->for(Project::factory()->for($other))
        ->assignedTo($user)
        ->todo()
        ->dueOn(today()->subDay()->toDateString())
        ->create(['title' => 'Secret other tenant work']);

    dashboardAs($user, $organization)->assertDontSee('Secret other tenant work');
});

test('a non-member cannot mount the dashboard', function () {
    $organization = Organization::factory()->create();

    dashboardAs(User::factory()->create(), $organization)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Projects panel
|--------------------------------------------------------------------------
*/

test('managers see recent projects while employees see active ones', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    Project::factory()->for($organization)->active()->create(['name' => 'Live work']);
    Project::factory()->for($organization)->archived()->create(['name' => 'Shelved work']);

    dashboardAs($owner, $organization)
        ->assertSee('Recent projects')
        ->assertSee('Live work')
        ->assertSee('Shelved work');

    dashboardAs($employee, $organization)
        ->assertSee('Active projects')
        ->assertSee('Live work')
        ->assertDontSee('Shelved work');
});

test('the projects panel offers a create action only to those who may create', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    dashboardAs($owner, $organization)->assertSee('data-test="empty-create-project"', escape: false);
    dashboardAs($employee, $organization)->assertDontSee('data-test="empty-create-project"', escape: false);
});

test('no metric that the domain cannot support is displayed', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    dashboardAs($owner, $organization)
        ->assertDontSee('Tracked hours')
        ->assertDontSee('Recent activity')
        ->assertDontSee('Clients')
        ->assertDontSee('Revenue');
});
