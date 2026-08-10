<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('an owner can update the organization name', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner);

    Livewire::test('pages::organizations.settings', ['organization' => $organization])
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->call('updateOrganization')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect($organization->refresh()->name)->toBe('New Name');
});

test('updating the name does not change the slug', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner);

    Livewire::test('pages::organizations.settings', ['organization' => $organization])
        ->set('name', 'Completely Different')
        ->call('updateOrganization')
        ->assertHasNoErrors();

    expect($organization->refresh()->slug)->toBe('old-name');
});

test('a member without manage rights cannot mount the settings component', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);

    $this->actingAs($member);

    Livewire::test('pages::organizations.settings', ['organization' => $organization])
        ->assertForbidden();
})->with([
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('a non-member cannot mount the settings component', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    Livewire::test('pages::organizations.settings', ['organization' => $organization])
        ->assertForbidden();
});

test('the update action re-authorizes and is not trusted from mount alone', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);

    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner);

    $component = Livewire::test('pages::organizations.settings', ['organization' => $organization])
        ->set('name', 'New Name');

    $owner->membershipFor($organization)->update(['role' => OrganizationRole::Manager]);

    $component->call('updateOrganization')->assertForbidden();

    expect($organization->refresh()->name)->toBe('Old Name');
});

test('the locked organization property cannot be swapped by the browser', function () {
    $organizationA = Organization::factory()->create(['name' => 'Organization A']);
    $organizationB = Organization::factory()->create(['name' => 'Organization B']);

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($owner);

    $component = Livewire::test('pages::organizations.settings', ['organization' => $organizationA]);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($organizationB->refresh()->name)->toBe('Organization B');
});

test('updating one organization never modifies another', function () {
    $organizationA = Organization::factory()->create(['name' => 'Organization A']);
    $organizationB = Organization::factory()->create(['name' => 'Organization B']);

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);

    $this->actingAs($owner);

    Livewire::test('pages::organizations.settings', ['organization' => $organizationA])
        ->set('name', 'Renamed A')
        ->call('updateOrganization')
        ->assertHasNoErrors();

    expect($organizationA->refresh()->name)->toBe('Renamed A')
        ->and($organizationB->refresh()->name)->toBe('Organization B');
});

test('the organization name is required', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner);

    Livewire::test('pages::organizations.settings', ['organization' => $organization])
        ->set('name', '')
        ->call('updateOrganization')
        ->assertHasErrors(['name' => 'required']);

    expect($organization->refresh()->name)->toBe('Old Name');
});

test('the organization name may not exceed 255 characters', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner);

    Livewire::test('pages::organizations.settings', ['organization' => $organization])
        ->set('name', str_repeat('a', 256))
        ->call('updateOrganization')
        ->assertHasErrors(['name' => 'max']);

    expect($organization->refresh()->name)->toBe('Old Name');
});
