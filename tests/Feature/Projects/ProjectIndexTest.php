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
 * Open the project index as the given user.
 */
function projectIndexAs(User $user, Organization $organization): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.index', ['organization' => $organization]);
}

test('the index lists only the current organization\'s projects', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);

    Project::factory()->for($organizationA)->create(['name' => 'Alpha Project']);
    Project::factory()->for($organizationB)->create(['name' => 'Beta Project']);

    projectIndexAs($owner, $organizationA)
        ->assertSee('Alpha Project')
        ->assertDontSee('Beta Project');
});

test('the index is scoped through the organization relationship', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->create();
    Project::factory()->count(3)->create();

    $projects = projectIndexAs($owner, $organization)->instance()->projects();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->organization_id)->toBe($organization->id);
});

test('the index reports real total and active counts', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->active()->count(2)->create();
    Project::factory()->for($organization)->planning()->create();
    Project::factory()->for($organization)->archived()->create();
    Project::factory()->active()->count(5)->create();

    $component = projectIndexAs($owner, $organization);

    expect($component->instance()->totalCount())->toBe(4)
        ->and($component->instance()->activeCount())->toBe(2);
});

test('the status filter narrows the list', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->active()->create(['name' => 'Active Work']);
    Project::factory()->for($organization)->archived()->create(['name' => 'Archived Work']);

    projectIndexAs($owner, $organization)
        ->assertSee('Active Work')
        ->assertSee('Archived Work')
        ->set('status', ProjectStatus::Active->value)
        ->assertSee('Active Work')
        ->assertDontSee('Archived Work')
        ->set('status', ProjectStatus::Archived->value)
        ->assertSee('Archived Work')
        ->assertDontSee('Active Work');
});

test('an unknown status filter value is ignored rather than trusted', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->active()->create(['name' => 'Active Work']);

    projectIndexAs($owner, $organization)
        ->set('status', 'nonsense')
        ->assertHasNoErrors()
        ->assertSee('Active Work');
});

test('clearing the filter shows everything again', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->active()->create(['name' => 'Active Work']);
    Project::factory()->for($organization)->completed()->create(['name' => 'Completed Work']);

    projectIndexAs($owner, $organization)
        ->set('status', ProjectStatus::Active->value)
        ->assertDontSee('Completed Work')
        ->set('status', '')
        ->assertSee('Active Work')
        ->assertSee('Completed Work');
});

test('the empty state is shown when the organization has no projects', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    projectIndexAs($owner, $organization)
        ->assertSee('No projects yet.')
        ->assertSee('data-test="projects-empty-state"', escape: false);
});

test('a filtered empty state is distinguished from having no projects at all', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->active()->create();

    projectIndexAs($owner, $organization)
        ->set('status', ProjectStatus::Completed->value)
        ->assertSee('No projects match this filter.')
        ->assertDontSee('No projects yet.');
});

test('the new project button is only rendered for users who can create projects', function (OrganizationRole $role, bool $visible) {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);

    $component = projectIndexAs($member, $organization);

    $visible
        ? $component->assertSee('data-test="new-project-link"', escape: false)
        : $component->assertDontSee('data-test="new-project-link"', escape: false);
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'manager' => [OrganizationRole::Manager, true],
    'employee' => [OrganizationRole::Employee, false],
]);

test('a non-member cannot mount the index', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    projectIndexAs($outsider, $organization)->assertForbidden();
});

test('a member of another organization cannot mount the index', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    projectIndexAs($memberOfA, $organizationB)->assertForbidden();
});

test('the locked organization property cannot be swapped on the index', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $component = projectIndexAs($memberOfA, $organizationA);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('project links on the index use slugs rather than ids', function () {
    $organization = Organization::factory()->create(['slug' => 'acme-agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['slug' => 'website-redesign']);

    projectIndexAs($owner, $organization)
        ->assertSee('/o/acme-agency/projects/website-redesign', escape: false)
        ->assertDontSee("/o/acme-agency/projects/{$project->id}", escape: false);
});
