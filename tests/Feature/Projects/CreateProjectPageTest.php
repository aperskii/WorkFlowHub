<?php

use App\Enums\OrganizationRole;
use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the create project page as the given user.
 */
function createProjectPageAs(User $user, Organization $organization): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.create', ['organization' => $organization]);
}

test('the status field defaults to planning', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->assertSet('status', ProjectStatus::Planning->value);
});

test('an owner can create a project and is redirected to it', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->set('name', 'Website Redesign')
        ->set('description', 'Rebuild the marketing site.')
        ->call('createProject')
        ->assertHasNoErrors();

    $project = $organization->projects()->sole();

    expect($project->name)->toBe('Website Redesign')
        ->and($project->slug)->toBe('website-redesign')
        ->and($project->description)->toBe('Rebuild the marketing site.')
        ->and($project->status)->toBe(ProjectStatus::Planning);
});

test('the redirect targets the new project page', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->set('name', 'Website Redesign')
        ->call('createProject')
        ->assertRedirect(route('organizations.projects.show', [$organization, $organization->projects()->sole()]));
});

test('a manager can create a project', function () {
    $organization = Organization::factory()->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);

    createProjectPageAs($manager, $organization)
        ->set('name', 'Website Redesign')
        ->call('createProject')
        ->assertHasNoErrors();

    expect($organization->projects()->count())->toBe(1);
});

test('a project can be created with an explicit status', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->set('name', 'Website Redesign')
        ->set('status', ProjectStatus::Active->value)
        ->call('createProject')
        ->assertHasNoErrors();

    expect($organization->projects()->sole()->status)->toBe(ProjectStatus::Active);
});

test('an empty description is stored as null', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->set('name', 'Website Redesign')
        ->call('createProject')
        ->assertHasNoErrors();

    expect($organization->projects()->sole()->description)->toBeNull();
});

test('the project name is required', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->set('name', '')
        ->call('createProject')
        ->assertHasErrors(['name' => 'required'])
        ->assertNoRedirect();

    expect(Project::count())->toBe(0);
});

test('the project name may not exceed 255 characters', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->set('name', str_repeat('a', 256))
        ->call('createProject')
        ->assertHasErrors(['name' => 'max']);

    expect(Project::count())->toBe(0);
});

test('an unknown status value is rejected', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    createProjectPageAs($owner, $organization)
        ->set('name', 'Website Redesign')
        ->set('status', 'deleted')
        ->call('createProject')
        ->assertHasErrors('status');

    expect(Project::count())->toBe(0);
});

test('an employee cannot mount the create page', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    createProjectPageAs($employee, $organization)->assertForbidden();
});

test('a non-member cannot mount the create page', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    createProjectPageAs($outsider, $organization)->assertForbidden();
});

test('the create action re-authorizes and is not trusted from mount alone', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Owner);

    $component = createProjectPageAs($owner, $organization)->set('name', 'Website Redesign');

    $owner->membershipFor($organization)->update(['role' => OrganizationRole::Employee]);

    $component->call('createProject')->assertForbidden();

    expect(Project::count())->toBe(0);
});

test('the locked organization property cannot be swapped on the create page', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $component = createProjectPageAs($ownerOfA, $organizationA);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a project is always created inside the route-bound organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    createProjectPageAs($owner, $organizationA)
        ->set('name', 'Website Redesign')
        ->call('createProject')
        ->assertHasNoErrors();

    expect($organizationA->projects()->count())->toBe(1)
        ->and($organizationB->projects()->count())->toBe(0);
});
