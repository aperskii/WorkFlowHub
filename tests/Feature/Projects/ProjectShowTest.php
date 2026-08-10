<?php

use App\Enums\OrganizationRole;
use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open a project page as the given user.
 */
function projectShowAs(User $user, Organization $organization, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.show', [
        'organization' => $organization,
        'project' => $project,
    ]);
}

/*
|--------------------------------------------------------------------------
| Rendering
|--------------------------------------------------------------------------
*/

test('the page shows the project name, status, and description', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $project = Project::factory()->for($organization)->active()->create([
        'name' => 'Website Redesign',
        'description' => 'Rebuild the marketing site.',
    ]);

    projectShowAs($owner, $organization, $project)
        ->assertSee('Website Redesign')
        ->assertSee(ProjectStatus::Active->label())
        ->assertSee('Rebuild the marketing site.');
});

test('a project without a description shows an empty description state', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['description' => null]);

    projectShowAs($owner, $organization, $project)->assertSee('No description yet.');
});

test('an archived project shows an archive notice', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->archived()->create();

    projectShowAs($owner, $organization, $project)->assertSee('This project is archived.');
});

/*
|--------------------------------------------------------------------------
| Control visibility (cosmetic only)
|--------------------------------------------------------------------------
*/

test('management controls are only rendered for users who can manage projects', function (OrganizationRole $role, bool $visible) {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);
    $project = Project::factory()->for($organization)->create();

    $component = projectShowAs($member, $organization, $project);

    foreach (['edit-project-button', 'change-status-button', 'archive-project-button'] as $control) {
        $visible
            ? $component->assertSee('data-test="'.$control.'"', escape: false)
            : $component->assertDontSee('data-test="'.$control.'"', escape: false);
    }
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'manager' => [OrganizationRole::Manager, true],
    'employee' => [OrganizationRole::Employee, false],
]);

test('no delete control is ever rendered', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    projectShowAs($owner, $organization, $project)
        ->assertDontSee('delete-project')
        ->assertDontSeeText('Delete project');
});

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

test('an owner can update the project name and description', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['name' => 'Old Name']);

    projectShowAs($owner, $organization, $project)
        ->call('edit')
        ->assertSet('editing', true)
        ->set('name', 'New Name')
        ->set('description', 'Updated description.')
        ->call('updateProject')
        ->assertHasNoErrors()
        ->assertSet('editing', false)
        ->assertNoRedirect();

    $project->refresh();

    expect($project->name)->toBe('New Name')
        ->and($project->description)->toBe('Updated description.');
});

test('renaming a project does not change its slug', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['slug' => 'old-name']);

    projectShowAs($owner, $organization, $project)
        ->set('name', 'Completely Different')
        ->call('updateProject')
        ->assertHasNoErrors();

    expect($project->refresh()->slug)->toBe('old-name');
});

test('clearing the description stores null rather than an empty string', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['description' => 'Something']);

    projectShowAs($owner, $organization, $project)
        ->set('description', '')
        ->call('updateProject')
        ->assertHasNoErrors();

    expect($project->refresh()->description)->toBeNull();
});

test('the project name is required and length limited', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['name' => 'Old Name']);

    projectShowAs($owner, $organization, $project)
        ->set('name', '')
        ->call('updateProject')
        ->assertHasErrors(['name' => 'required']);

    projectShowAs($owner, $organization, $project)
        ->set('name', str_repeat('a', 256))
        ->call('updateProject')
        ->assertHasErrors(['name' => 'max']);

    expect($project->refresh()->name)->toBe('Old Name');
});

test('an employee cannot update a project', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->create(['name' => 'Old Name']);

    projectShowAs($employee, $organization, $project)
        ->set('name', 'Hacked')
        ->call('updateProject')
        ->assertForbidden();

    expect($project->refresh()->name)->toBe('Old Name');
});

test('the update action re-authorizes and is not trusted from mount alone', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['name' => 'Old Name']);

    $component = projectShowAs($owner, $organization, $project)->set('name', 'New Name');

    $owner->membershipFor($organization)->update(['role' => OrganizationRole::Employee]);

    $component->call('updateProject')->assertForbidden();

    expect($project->refresh()->name)->toBe('Old Name');
});

/*
|--------------------------------------------------------------------------
| Status changes
|--------------------------------------------------------------------------
*/

test('a manager can move a project to any status', function (ProjectStatus $status) {
    $organization = Organization::factory()->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);
    $project = Project::factory()->for($organization)->planning()->create();

    projectShowAs($manager, $organization, $project)
        ->call('changeStatus', $status->value)
        ->assertHasNoErrors();

    expect($project->refresh()->status)->toBe($status);
})->with([
    'planning' => ProjectStatus::Planning,
    'active' => ProjectStatus::Active,
    'on hold' => ProjectStatus::OnHold,
    'completed' => ProjectStatus::Completed,
    'archived' => ProjectStatus::Archived,
]);

test('an archived project can be moved back to active', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->archived()->create();

    projectShowAs($owner, $organization, $project)
        ->call('changeStatus', ProjectStatus::Active->value)
        ->assertHasNoErrors();

    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});

test('an unknown status value is rejected', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->planning()->create();

    projectShowAs($owner, $organization, $project)
        ->call('changeStatus', 'deleted')
        ->assertHasErrors('status');

    expect($project->refresh()->status)->toBe(ProjectStatus::Planning);
});

test('an employee cannot change a project status', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->planning()->create();

    projectShowAs($employee, $organization, $project)
        ->call('changeStatus', ProjectStatus::Active->value)
        ->assertForbidden();

    expect($project->refresh()->status)->toBe(ProjectStatus::Planning);
});

/*
|--------------------------------------------------------------------------
| Archive
|--------------------------------------------------------------------------
*/

test('an owner can archive a project without deleting it', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->active()->create();

    projectShowAs($owner, $organization, $project)
        ->call('archiveProject')
        ->assertHasNoErrors();

    $this->assertModelExists($project);
    expect($project->refresh()->status)->toBe(ProjectStatus::Archived)
        ->and($organization->projects()->count())->toBe(1);
});

test('an employee cannot archive a project', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->active()->create();

    projectShowAs($employee, $organization, $project)
        ->call('archiveProject')
        ->assertForbidden();

    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});

test('the archive action re-authorizes at action time', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->active()->create();

    $component = projectShowAs($owner, $organization, $project);

    $owner->membershipFor($organization)->update(['role' => OrganizationRole::Employee]);

    $component->call('archiveProject')->assertForbidden();

    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});

/*
|--------------------------------------------------------------------------
| Tenant isolation and tampering
|--------------------------------------------------------------------------
*/

test('a non-member cannot mount the project page', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->for($organization)->create();

    projectShowAs($outsider, $organization, $project)->assertForbidden();
});

test('the locked organization and project properties cannot be swapped', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $projectInA = Project::factory()->for($organizationA)->create();
    $projectInB = Project::factory()->for($organizationB)->create();

    $component = projectShowAs($ownerOfA, $organizationA, $projectInA);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $component->set('project', $projectInB))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('mounting with a project from another organization is forbidden', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $projectInB = Project::factory()->for($organizationB)->create(['name' => 'Untouched']);

    Livewire::actingAs($ownerOfA)
        ->test('pages::projects.show', [
            'organization' => $organizationA,
            'project' => $projectInB,
        ])
        ->assertForbidden();

    expect($projectInB->refresh()->name)->toBe('Untouched');
});

test('a mutation re-resolves the project through the organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    $project = Project::factory()->for($organizationA)->create(['name' => 'Original']);

    $component = projectShowAs($ownerOfA, $organizationA, $project)->set('name', 'Renamed');

    // The project leaves organization A after the component mounted. Because every
    // mutation re-resolves through $organization->projects(), it is no longer found.
    $project->forceFill(['organization_id' => $organizationB->id])->save();

    expect(fn () => $component->call('updateProject'))
        ->toThrow(ModelNotFoundException::class);

    expect($project->refresh()->name)->toBe('Original');
});

test('archiving re-resolves the project through the organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    $project = Project::factory()->for($organizationA)->active()->create();

    $component = projectShowAs($ownerOfA, $organizationA, $project);

    $project->forceFill(['organization_id' => $organizationB->id])->save();

    expect(fn () => $component->call('archiveProject'))
        ->toThrow(ModelNotFoundException::class);

    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});
