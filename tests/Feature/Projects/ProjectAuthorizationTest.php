<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Role matrix
|--------------------------------------------------------------------------
*/

test('the project ability matrix is enforced for every organization role', function (
    OrganizationRole $role,
    bool $canView,
    bool $canManage
) {
    $organization = Organization::factory()->create();
    $actor = memberWithRole($organization, $role);
    $project = Project::factory()->for($organization)->create();

    expect($actor->can('viewAny', [Project::class, $organization]))->toBe($canView)
        ->and($actor->can('view', $project))->toBe($canView)
        ->and($actor->can('create', [Project::class, $organization]))->toBe($canManage)
        ->and($actor->can('update', $project))->toBe($canManage)
        ->and($actor->can('changeStatus', $project))->toBe($canManage)
        ->and($actor->can('archive', $project))->toBe($canManage)
        // Running an AI risk analysis spends money, so it tracks management
        // rights rather than read access (ADR-011).
        ->and($actor->can('analyzeRisk', $project))->toBe($canManage);
})->with([
    'owner manages everything' => [OrganizationRole::Owner, true, true],
    'manager manages everything' => [OrganizationRole::Manager, true, true],
    'employee views only' => [OrganizationRole::Employee, true, false],
]);

test('a user with no membership is denied every project ability', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->for($organization)->create();

    expect($outsider->can('viewAny', [Project::class, $organization]))->toBeFalse()
        ->and($outsider->can('view', $project))->toBeFalse()
        ->and($outsider->can('create', [Project::class, $organization]))->toBeFalse()
        ->and($outsider->can('update', $project))->toBeFalse()
        ->and($outsider->can('changeStatus', $project))->toBeFalse()
        ->and($outsider->can('archive', $project))->toBeFalse()
        ->and($outsider->can('analyzeRisk', $project))->toBeFalse();
});

test('a guest is denied every project ability', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    expect(Gate::forUser(null)->allows('viewAny', [Project::class, $organization]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $project))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', [Project::class, $organization]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser(null)->allows('archive', $project))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Cross-tenant denial
|--------------------------------------------------------------------------
*/

test('an owner of one organization has no rights over another organization\'s project', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $projectInB = Project::factory()->for($organizationB)->create();

    expect($ownerOfA->can('view', $projectInB))->toBeFalse()
        ->and($ownerOfA->can('update', $projectInB))->toBeFalse()
        ->and($ownerOfA->can('changeStatus', $projectInB))->toBeFalse()
        ->and($ownerOfA->can('archive', $projectInB))->toBeFalse()
        ->and($ownerOfA->can('analyzeRisk', $projectInB))->toBeFalse()
        ->and($ownerOfA->can('viewAny', [Project::class, $organizationB]))->toBeFalse()
        ->and($ownerOfA->can('create', [Project::class, $organizationB]))->toBeFalse();
});

test('a user holding different roles in two organizations gets each organization\'s rights', function () {
    $ownerOrganization = Organization::factory()->create();
    $employeeOrganization = Organization::factory()->create();

    $user = User::factory()->create();

    Membership::factory()->for($ownerOrganization)->for($user)->owner()->create();
    Membership::factory()->for($employeeOrganization)->for($user)->employee()->create();

    $ownedProject = Project::factory()->for($ownerOrganization)->create();
    $employedProject = Project::factory()->for($employeeOrganization)->create();

    expect($user->can('update', $ownedProject))->toBeTrue()
        ->and($user->can('update', $employedProject))->toBeFalse()
        ->and($user->can('view', $ownedProject))->toBeTrue()
        ->and($user->can('view', $employedProject))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| No deletion in the MVP
|--------------------------------------------------------------------------
*/

test('the project policy exposes no delete ability', function () {
    expect(method_exists(ProjectPolicy::class, 'delete'))->toBeFalse()
        ->and(method_exists(ProjectPolicy::class, 'forceDelete'))->toBeFalse();
});

test('nobody is granted permission to delete a project', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    expect($owner->can('delete', $project))->toBeFalse();
});
