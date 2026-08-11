<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Role matrix
|--------------------------------------------------------------------------
*/

test('the task ability matrix is enforced for every organization role', function (
    OrganizationRole $role,
    bool $canView,
    bool $canManage
) {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $actor = memberWithRole($organization, $role);

    // A task assigned to somebody else, so the assignee rule cannot apply.
    $task = Task::factory()->for($project)->assignedTo(memberWithRole($organization, OrganizationRole::Employee))->create();

    expect($actor->can('viewAny', [Task::class, $project]))->toBe($canView)
        ->and($actor->can('view', $task))->toBe($canView)
        ->and($actor->can('create', [Task::class, $project]))->toBe($canManage)
        ->and($actor->can('update', $task))->toBe($canManage)
        ->and($actor->can('assign', $task))->toBe($canManage)
        ->and($actor->can('changeStatus', $task))->toBe($canManage);
})->with([
    'owner manages everything' => [OrganizationRole::Owner, true, true],
    'manager manages everything' => [OrganizationRole::Manager, true, true],
    'employee views only' => [OrganizationRole::Employee, true, false],
]);

/*
|--------------------------------------------------------------------------
| The assignee rule
|--------------------------------------------------------------------------
*/

test('an employee can change the status of a task assigned to them', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($employee)->create();

    expect($employee->can('changeStatus', $task))->toBeTrue();
});

test('an employee cannot change the status of another user\'s task', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($colleague)->create();

    expect($employee->can('changeStatus', $task))->toBeFalse();
});

test('an employee cannot change the status of an unassigned task', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->create(['assigned_to_user_id' => null]);

    expect($employee->can('changeStatus', $task))->toBeFalse();
});

test('being the assignee grants status changes and nothing else', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($employee)->create();

    expect($employee->can('changeStatus', $task))->toBeTrue()
        ->and($employee->can('update', $task))->toBeFalse()
        ->and($employee->can('assign', $task))->toBeFalse()
        ->and($employee->can('create', [Task::class, $project]))->toBeFalse();
});

test('assignment alone does not grant access to a non-member', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $outsider = User::factory()->create();
    $task = Task::factory()->for($project)->create(['assigned_to_user_id' => $outsider->id]);

    expect($outsider->can('changeStatus', $task))->toBeFalse()
        ->and($outsider->can('view', $task))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Non-members, guests, and cross-tenant denial
|--------------------------------------------------------------------------
*/

test('a non-member is denied every task ability', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $task = Task::factory()->for($project)->create();
    $outsider = User::factory()->create();

    expect($outsider->can('viewAny', [Task::class, $project]))->toBeFalse()
        ->and($outsider->can('view', $task))->toBeFalse()
        ->and($outsider->can('create', [Task::class, $project]))->toBeFalse()
        ->and($outsider->can('update', $task))->toBeFalse()
        ->and($outsider->can('assign', $task))->toBeFalse()
        ->and($outsider->can('changeStatus', $task))->toBeFalse();
});

test('a guest is denied every task ability', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $task = Task::factory()->for($project)->create();

    expect(Gate::forUser(null)->allows('viewAny', [Task::class, $project]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $task))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', [Task::class, $project]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('update', $task))->toBeFalse()
        ->and(Gate::forUser(null)->allows('changeStatus', $task))->toBeFalse();
});

test('an owner of one organization has no rights over another organization\'s tasks', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $projectInB = Project::factory()->for($organizationB)->create();
    $taskInB = Task::factory()->for($projectInB)->create();

    expect($ownerOfA->can('view', $taskInB))->toBeFalse()
        ->and($ownerOfA->can('update', $taskInB))->toBeFalse()
        ->and($ownerOfA->can('assign', $taskInB))->toBeFalse()
        ->and($ownerOfA->can('changeStatus', $taskInB))->toBeFalse()
        ->and($ownerOfA->can('viewAny', [Task::class, $projectInB]))->toBeFalse()
        ->and($ownerOfA->can('create', [Task::class, $projectInB]))->toBeFalse();
});

test('a user with different roles in two organizations gets each organization\'s task rights', function () {
    $ownedOrganization = Organization::factory()->create();
    $employedOrganization = Organization::factory()->create();

    $user = User::factory()->create();
    Membership::factory()->for($ownedOrganization)->for($user)->owner()->create();
    Membership::factory()->for($employedOrganization)->for($user)->employee()->create();

    $ownedTask = Task::factory()->for(Project::factory()->for($ownedOrganization))->create();
    $employedTask = Task::factory()->for(Project::factory()->for($employedOrganization))->create();

    expect($user->can('update', $ownedTask))->toBeTrue()
        ->and($user->can('update', $employedTask))->toBeFalse()
        ->and($user->can('view', $ownedTask))->toBeTrue()
        ->and($user->can('view', $employedTask))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| No deletion anywhere
|--------------------------------------------------------------------------
*/

test('the task policy exposes no deletion ability', function () {
    expect(get_class_methods(TaskPolicy::class))
        ->toEqualCanonicalizing(['viewAny', 'view', 'create', 'update', 'assign', 'changeStatus']);
});

test('nobody is granted permission to delete a task', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $actor = memberWithRole($organization, $role);
    $task = Task::factory()->for($project)->assignedTo($actor)->create();

    expect($actor->can('delete', $task))->toBeFalse()
        ->and($actor->can('forceDelete', $task))->toBeFalse();
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('no task routes exist at all', function () {
    $taskRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'task'));

    expect($taskRoutes)->toBeEmpty();
});
