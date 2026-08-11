<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Removing a membership unassigns that person's tasks
|--------------------------------------------------------------------------
*/

test('deleting a membership unassigns that user\'s tasks in that organization', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $tasks = Task::factory()->for($project)->assignedTo($employee)->count(3)->create();

    $employee->membershipFor($organization)->delete();

    foreach ($tasks as $task) {
        $this->assertModelExists($task);

        expect($task->refresh()->assigned_to_user_id)->toBeNull();
    }
});

test('unassigning on removal keeps the task and every other field intact', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()
        ->for($project)
        ->assignedTo($employee)
        ->inProgress()
        ->dueOn('2026-09-01')
        ->create(['title' => 'Write the launch plan']);

    $employee->membershipFor($organization)->delete();

    $task->refresh();

    expect($task->assigned_to_user_id)->toBeNull()
        ->and($task->title)->toBe('Write the launch plan')
        ->and($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->due_date->toDateString())->toBe('2026-09-01')
        ->and($task->project_id)->toBe($project->id);
});

test('a removed member\'s assignments in other organizations are untouched', function () {
    $leftOrganization = Organization::factory()->create();
    $keptOrganization = Organization::factory()->create();

    $user = User::factory()->create();
    Membership::factory()->for($leftOrganization)->for($user)->employee()->create();
    Membership::factory()->for($keptOrganization)->for($user)->employee()->create();

    $leftTask = Task::factory()
        ->for(Project::factory()->for($leftOrganization))
        ->assignedTo($user)
        ->create();

    $keptTask = Task::factory()
        ->for(Project::factory()->for($keptOrganization))
        ->assignedTo($user)
        ->create();

    $user->membershipFor($leftOrganization)->delete();

    expect($leftTask->refresh()->assigned_to_user_id)->toBeNull()
        ->and($keptTask->refresh()->assigned_to_user_id)->toBe($user->id);
});

test('removing one member leaves other members\' assignments alone', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $leaver = memberWithRole($organization, OrganizationRole::Employee);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);

    $leaverTask = Task::factory()->for($project)->assignedTo($leaver)->create();
    $colleagueTask = Task::factory()->for($project)->assignedTo($colleague)->create();
    $unassignedTask = Task::factory()->for($project)->create(['assigned_to_user_id' => null]);

    $leaver->membershipFor($organization)->delete();

    expect($leaverTask->refresh()->assigned_to_user_id)->toBeNull()
        ->and($colleagueTask->refresh()->assigned_to_user_id)->toBe($colleague->id)
        ->and($unassignedTask->refresh()->assigned_to_user_id)->toBeNull()
        ->and(Task::count())->toBe(3);
});

test('removing a member through the members page unassigns their tasks', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($employee)->create();

    Livewire::actingAs($owner)
        ->test('pages::organizations.members', ['organization' => $organization])
        ->call('confirmRemoval', $employee->membershipFor($organization)->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertModelExists($task);

    expect($task->refresh()->assigned_to_user_id)->toBeNull();
});

test('deleting an organization does not leave assignments behind in another tenant', function () {
    $deletedOrganization = Organization::factory()->create();
    $survivingOrganization = Organization::factory()->create();

    $user = User::factory()->create();
    Membership::factory()->for($deletedOrganization)->for($user)->owner()->create();
    Membership::factory()->for($survivingOrganization)->for($user)->owner()->create();

    $survivingTask = Task::factory()
        ->for(Project::factory()->for($survivingOrganization))
        ->assignedTo($user)
        ->create();

    Task::factory()
        ->for(Project::factory()->for($deletedOrganization))
        ->assignedTo($user)
        ->create();

    $deletedOrganization->delete();

    expect(Task::count())->toBe(1)
        ->and($survivingTask->refresh()->assigned_to_user_id)->toBe($user->id);
});

/*
|--------------------------------------------------------------------------
| The assigned tasks relationship
|--------------------------------------------------------------------------
*/

test('a user\'s assigned tasks span every organization they belong to', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $user = User::factory()->create();
    Membership::factory()->for($organizationA)->for($user)->employee()->create();
    Membership::factory()->for($organizationB)->for($user)->employee()->create();

    Task::factory()->for(Project::factory()->for($organizationA))->assignedTo($user)->count(2)->create();
    Task::factory()->for(Project::factory()->for($organizationB))->assignedTo($user)->create();

    // Somebody else's work must never appear.
    Task::factory()->for(Project::factory()->for($organizationA))->create();

    expect($user->assignedTasks()->count())->toBe(3);
});

test('an unassigned task belongs to nobody', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    Task::factory()->for(Project::factory()->for($organization))->create(['assigned_to_user_id' => null]);

    expect($employee->assignedTasks()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Organization dashboard task metrics
|--------------------------------------------------------------------------
*/

test('the dashboard counts open and completed tasks across the organization', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $projectOne = Project::factory()->for($organization)->create();
    $projectTwo = Project::factory()->for($organization)->create();

    Task::factory()->for($projectOne)->todo()->create();
    Task::factory()->for($projectOne)->inProgress()->create();
    Task::factory()->for($projectTwo)->inReview()->create();
    Task::factory()->for($projectTwo)->done()->count(2)->create();

    $dashboard = Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->instance();

    expect($dashboard->openTaskCount())->toBe(3)
        ->and($dashboard->completedTaskCount())->toBe(2);
});

test('the dashboard task metrics ignore another organization\'s tasks', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Task::factory()->for(Project::factory()->for($organization))->todo()->create();

    $other = Organization::factory()->create();
    Task::factory()->for(Project::factory()->for($other))->todo()->count(4)->create();
    Task::factory()->for(Project::factory()->for($other))->done()->count(4)->create();

    $dashboard = Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->instance();

    expect($dashboard->openTaskCount())->toBe(1)
        ->and($dashboard->completedTaskCount())->toBe(0);
});

test('an organization with no tasks reports zero for both metrics', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->create();

    $dashboard = Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->instance();

    expect($dashboard->openTaskCount())->toBe(0)
        ->and($dashboard->completedTaskCount())->toBe(0);
});

test('completing a task moves it from the open metric to the completed metric', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    $task = Task::factory()->for($project)->todo()->create();

    $dashboard = fn () => Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->instance();

    expect($dashboard()->openTaskCount())->toBe(1)
        ->and($dashboard()->completedTaskCount())->toBe(0);

    $task->update(['status' => TaskStatus::Done]);

    expect($dashboard()->openTaskCount())->toBe(0)
        ->and($dashboard()->completedTaskCount())->toBe(1);
});
