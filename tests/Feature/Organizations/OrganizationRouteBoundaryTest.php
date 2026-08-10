<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

test('the organization routes are bound by slug rather than id', function () {
    $organization = Organization::factory()->create(['slug' => 'acme-agency']);

    expect(route('organizations.dashboard', $organization, absolute: false))->toBe('/o/acme-agency')
        ->and(route('organizations.members', $organization, absolute: false))->toBe('/o/acme-agency/members')
        ->and(route('organizations.settings', $organization, absolute: false))->toBe('/o/acme-agency/settings');
});

test('a guest is redirected to login', function (string $routeName) {
    $organization = Organization::factory()->create();

    $this->get(route($routeName, $organization))->assertRedirect(route('login'));
})->with([
    'dashboard' => 'organizations.dashboard',
    'members' => 'organizations.members',
    'settings' => 'organizations.settings',
]);

test('an unknown organization slug results in a 404', function (string $routeName) {
    $user = User::factory()->create();

    $path = $routeName === 'dashboard' ? '/o/does-not-exist' : "/o/does-not-exist/{$routeName}";

    $this->actingAs($user)->get($path)->assertNotFound();
})->with(['dashboard', 'members', 'settings']);

test('a member can view their organization dashboard', function () {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, OrganizationRole::Employee);

    $this->actingAs($member)
        ->get(route('organizations.dashboard', $organization))
        ->assertOk()
        ->assertSee($organization->name)
        ->assertSee($organization->slug)
        ->assertSee(OrganizationRole::Employee->label());
});

test('the dashboard shows the member count', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Employee);

    $this->actingAs($owner)
        ->get(route('organizations.dashboard', $organization))
        ->assertOk()
        ->assertSeeText('2');
});

test('a user with no membership cannot view an organization dashboard', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('organizations.dashboard', $organization))
        ->assertForbidden();
});

test('a member of one organization cannot access another organization dashboard', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $member = memberWithRole($organizationA, OrganizationRole::Employee);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($member)
        ->get(route('organizations.dashboard', $organizationA))
        ->assertOk();

    $this->actingAs($member)
        ->get(route('organizations.dashboard', $organizationB))
        ->assertForbidden();
});

test('an owner of one organization cannot reach another organization settings', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($ownerOfA)
        ->get(route('organizations.settings', $organizationB))
        ->assertForbidden();
});

test('an owner can open organization settings', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner)
        ->get(route('organizations.settings', $organization))
        ->assertOk()
        ->assertSee($organization->name);
});

test('a manager cannot open organization settings', function () {
    $organization = Organization::factory()->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);

    $this->actingAs($manager)
        ->get(route('organizations.settings', $organization))
        ->assertForbidden();
});

test('an employee cannot open organization settings', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $this->actingAs($employee)
        ->get(route('organizations.settings', $organization))
        ->assertForbidden();
});

test('any organization member can view the members page', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);

    $this->actingAs($member)
        ->get(route('organizations.members', $organization))
        ->assertOk();
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('a non-member cannot view the members page of another organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $member = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($member)
        ->get(route('organizations.members', $organizationB))
        ->assertForbidden();
});

test('an unverified user is redirected to email verification', function () {
    $organization = Organization::factory()->create();

    $user = User::factory()->unverified()->create();
    Membership::factory()->for($organization)->for($user)->owner()->create();

    $this->actingAs($user)
        ->get(route('organizations.dashboard', $organization))
        ->assertRedirect(route('verification.notice'));
});

test('the settings navigation link is only rendered for users who can manage the organization', function () {
    $organization = Organization::factory()->create();

    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $settingsUrl = route('organizations.settings', $organization, absolute: false);

    $this->actingAs($owner)
        ->get(route('organizations.dashboard', $organization))
        ->assertSee($settingsUrl, escape: false);

    $this->actingAs($employee)
        ->get(route('organizations.dashboard', $organization))
        ->assertDontSee($settingsUrl, escape: false);
});
