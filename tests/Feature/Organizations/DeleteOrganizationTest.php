<?php

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the organization settings page as the given user.
 */
function settingsPageAs(User $user, Organization $organization): Testable
{
    return Livewire::actingAs($user)->test('pages::organizations.settings', ['organization' => $organization]);
}

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

test('an owner can delete their organization', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    settingsPageAs($owner, $organization)
        ->set('deleteConfirmation', 'Acme Agency')
        ->call('deleteOrganization')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertModelMissing($organization);
});

test('a manager cannot delete the organization', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    memberWithRole($organization, OrganizationRole::Owner);
    $manager = memberWithRole($organization, OrganizationRole::Manager);

    // The settings page itself is already owner-only.
    settingsPageAs($manager, $organization)->assertForbidden();

    expect($manager->can('delete', $organization))->toBeFalse();
    $this->assertModelExists($organization);
});

test('an employee cannot delete the organization', function () {
    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    settingsPageAs($employee, $organization)->assertForbidden();

    expect($employee->can('delete', $organization))->toBeFalse();
    $this->assertModelExists($organization);
});

test('a non-member cannot delete the organization', function () {
    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $outsider = User::factory()->create();

    settingsPageAs($outsider, $organization)->assertForbidden();

    expect($outsider->can('delete', $organization))->toBeFalse();
    $this->assertModelExists($organization);
});

test('a guest is redirected to login rather than reaching the settings page', function () {
    $organization = Organization::factory()->create();

    $this->get(route('organizations.settings', $organization))->assertRedirect(route('login'));

    $this->assertModelExists($organization);
});

test('the delete action re-authorizes and is not trusted from mount alone', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Owner);

    $component = settingsPageAs($owner, $organization)->set('deleteConfirmation', 'Acme Agency');

    $owner->membershipFor($organization)->update(['role' => OrganizationRole::Employee]);

    $component->call('deleteOrganization')->assertForbidden();

    $this->assertModelExists($organization);
});

test('the danger zone is only rendered for owners', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    settingsPageAs($owner, $organization)
        ->assertSee('data-test="danger-zone"', escape: false)
        ->assertSee('data-test="delete-organization-button"', escape: false);
});

/*
|--------------------------------------------------------------------------
| Confirmation
|--------------------------------------------------------------------------
*/

test('deletion requires the organization name typed exactly', function (string $typed) {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    settingsPageAs($owner, $organization)
        ->set('deleteConfirmation', $typed)
        ->call('deleteOrganization')
        ->assertHasErrors('deleteConfirmation')
        ->assertNoRedirect();

    $this->assertModelExists($organization);
})->with([
    'empty' => '',
    'wrong name' => 'Some Other Agency',
    'wrong case' => 'acme agency',
    'trailing space' => 'Acme Agency ',
]);

/*
|--------------------------------------------------------------------------
| Cascade behaviour
|--------------------------------------------------------------------------
*/

test('deleting an organization removes its memberships, projects, and invitations', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $membership = $organization->memberships()->first();
    $project = Project::factory()->for($organization)->create();
    $invitation = Invitation::factory()->for($organization)->create();

    settingsPageAs($owner, $organization)
        ->set('deleteConfirmation', 'Acme Agency')
        ->call('deleteOrganization')
        ->assertHasNoErrors();

    $this->assertModelMissing($organization);
    $this->assertModelMissing($membership);
    $this->assertModelMissing($project);
    $this->assertModelMissing($invitation);
});

test('user accounts survive organization deletion', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $manager = memberWithRole($organization, OrganizationRole::Manager);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    settingsPageAs($owner, $organization)
        ->set('deleteConfirmation', 'Acme Agency')
        ->call('deleteOrganization')
        ->assertHasNoErrors();

    $this->assertModelExists($owner);
    $this->assertModelExists($manager);
    $this->assertModelExists($employee);
    expect(User::count())->toBe(3);
});

test('a user who belonged only to the deleted organization keeps their account and loses membership', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    settingsPageAs($owner, $organization)
        ->set('deleteConfirmation', 'Acme Agency')
        ->call('deleteOrganization')
        ->assertHasNoErrors();

    expect($owner->refresh()->memberships()->count())->toBe(0)
        ->and($owner->organizations()->count())->toBe(0);

    $this->assertModelExists($owner);
});

test('deleting one organization never touches another', function () {
    $organizationA = Organization::factory()->create(['name' => 'Alpha Agency']);
    $organizationB = Organization::factory()->create(['name' => 'Beta Agency']);

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);
    $ownerOfB = memberWithRole($organizationB, OrganizationRole::Owner);

    $projectInB = Project::factory()->for($organizationB)->create();
    $invitationInB = Invitation::factory()->for($organizationB)->create();

    settingsPageAs($owner, $organizationA)
        ->set('deleteConfirmation', 'Alpha Agency')
        ->call('deleteOrganization')
        ->assertHasNoErrors();

    $this->assertModelMissing($organizationA);
    $this->assertModelExists($organizationB);
    $this->assertModelExists($projectInB);
    $this->assertModelExists($invitationInB);
    expect($organizationB->owners()->count())->toBe(1)
        ->and($ownerOfB->membershipFor($organizationB))->not->toBeNull();
});

test('a user who belongs to two organizations keeps the surviving membership', function () {
    $organizationA = Organization::factory()->create(['name' => 'Alpha Agency']);
    $organizationB = Organization::factory()->create();

    $user = User::factory()->create();
    Membership::factory()->for($organizationA)->for($user)->owner()->create();
    Membership::factory()->for($organizationB)->for($user)->employee()->create();

    settingsPageAs($user, $organizationA)
        ->set('deleteConfirmation', 'Alpha Agency')
        ->call('deleteOrganization')
        ->assertHasNoErrors();

    expect($user->refresh()->memberships()->count())->toBe(1)
        ->and($user->membershipFor($organizationB)->role)->toBe(OrganizationRole::Employee);
});

/*
|--------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------
*/

test('the locked organization property cannot be swapped before deleting', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $component = settingsPageAs($ownerOfA, $organizationA);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $this->assertModelExists($organizationB);
});

test('deleting a sole owner organization is allowed and unblocks the owner account', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    expect($owner->isSoleOwnerOfAnyOrganization())->toBeTrue();

    settingsPageAs($owner, $organization)
        ->set('deleteConfirmation', 'Acme Agency')
        ->call('deleteOrganization')
        ->assertHasNoErrors();

    expect($owner->refresh()->isSoleOwnerOfAnyOrganization())->toBeFalse();

    // With the organization gone the account deletion guard no longer applies.
    $owner->delete();
    $this->assertModelMissing($owner);
});
