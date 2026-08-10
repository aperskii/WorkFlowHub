<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('the members page lists every member with their name, email, and role', function () {
    $organization = Organization::factory()->create();

    $owner = User::factory()->create(['name' => 'Olivia Owner', 'email' => 'olivia@example.com']);
    $employee = User::factory()->create(['name' => 'Evan Employee', 'email' => 'evan@example.com']);

    Membership::factory()->for($organization)->for($owner)->owner()->create();
    Membership::factory()->for($organization)->for($employee)->employee()->create();

    $this->actingAs($owner);

    Livewire::test('pages::organizations.members', ['organization' => $organization])
        ->assertSee('Olivia Owner')
        ->assertSee('olivia@example.com')
        ->assertSee(OrganizationRole::Owner->label())
        ->assertSee('Evan Employee')
        ->assertSee('evan@example.com')
        ->assertSee(OrganizationRole::Employee->label());
});

test('the members page never leaks members of another organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = User::factory()->create(['name' => 'Alice InA', 'email' => 'alice@example.com']);
    $memberOfB = User::factory()->create(['name' => 'Bob InB', 'email' => 'bob@example.com']);

    Membership::factory()->for($organizationA)->for($memberOfA)->owner()->create();
    Membership::factory()->for($organizationB)->for($memberOfB)->owner()->create();

    $this->actingAs($memberOfA);

    Livewire::test('pages::organizations.members', ['organization' => $organizationA])
        ->assertSee('Alice InA')
        ->assertDontSee('Bob InB')
        ->assertDontSee('bob@example.com');
});

test('the member list is scoped through the organization relationship', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    User::factory()->count(3)->create();

    $this->actingAs($owner);

    $memberships = Livewire::test('pages::organizations.members', ['organization' => $organization])
        ->instance()
        ->memberships();

    expect($memberships)->toHaveCount(1)
        ->and($memberships->first()->organization_id)->toBe($organization->id);
});

test('a non-member cannot mount the members component', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    Livewire::test('pages::organizations.members', ['organization' => $organization])
        ->assertForbidden();
});

test('a member of another organization cannot mount the members component', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($memberOfA);

    Livewire::test('pages::organizations.members', ['organization' => $organizationB])
        ->assertForbidden();
});

test('the locked organization property cannot be swapped on the members page', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($memberOfA);

    $component = Livewire::test('pages::organizations.members', ['organization' => $organizationA]);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the dashboard locked organization property cannot be swapped', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($memberOfA);

    $component = Livewire::test('pages::organizations.dashboard', ['organization' => $organizationA]);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the dashboard reports the role of the authenticated user only', function () {
    $organization = Organization::factory()->create();

    memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $this->actingAs($employee);

    Livewire::test('pages::organizations.dashboard', ['organization' => $organization])
        ->assertSee(OrganizationRole::Employee->label())
        ->assertSee($organization->slug);
});
