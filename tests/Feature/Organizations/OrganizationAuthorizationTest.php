<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('a guest cannot view an organization', function () {
    $organization = Organization::factory()->create();

    expect(Gate::forUser(null)->allows('view', $organization))->toBeFalse();
});

test('a guest cannot update or delete an organization', function () {
    $organization = Organization::factory()->create();

    expect(Gate::forUser(null)->allows('update', $organization))->toBeFalse()
        ->and(Gate::forUser(null)->allows('delete', $organization))->toBeFalse()
        ->and(Gate::forUser(null)->allows('manageMembers', $organization))->toBeFalse();
});

test('a user with no membership cannot view an organization', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    expect($outsider->can('view', $organization))->toBeFalse();
});

test('a member of one organization cannot view another organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $member = memberWithRole($organizationA, OrganizationRole::Employee);

    expect($member->can('view', $organizationA))->toBeTrue()
        ->and($member->can('view', $organizationB))->toBeFalse();
});

test('an owner of one organization cannot mutate another organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);

    expect($owner->can('update', $organizationB))->toBeFalse()
        ->and($owner->can('delete', $organizationB))->toBeFalse()
        ->and($owner->can('manageMembers', $organizationB))->toBeFalse();
});

test('an owner can view, update, delete, and manage members of their organization', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    expect($owner->can('view', $organization))->toBeTrue()
        ->and($owner->can('update', $organization))->toBeTrue()
        ->and($owner->can('delete', $organization))->toBeTrue()
        ->and($owner->can('manageMembers', $organization))->toBeTrue();
});

test('a manager can view and manage members but cannot update or delete the organization', function () {
    $organization = Organization::factory()->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);

    expect($manager->can('view', $organization))->toBeTrue()
        ->and($manager->can('manageMembers', $organization))->toBeTrue()
        ->and($manager->can('update', $organization))->toBeFalse()
        ->and($manager->can('delete', $organization))->toBeFalse();
});

test('an employee can only view the organization', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    expect($employee->can('view', $organization))->toBeTrue()
        ->and($employee->can('manageMembers', $organization))->toBeFalse()
        ->and($employee->can('update', $organization))->toBeFalse()
        ->and($employee->can('delete', $organization))->toBeFalse();
});

test('a user can belong to multiple organizations', function () {
    $user = User::factory()->create();
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    Membership::factory()->for($organizationA)->for($user)->owner()->create();
    Membership::factory()->for($organizationB)->for($user)->employee()->create();

    expect($user->organizations()->count())->toBe(2)
        ->and($user->memberships()->count())->toBe(2);
});

test('a user can hold different roles in different organizations', function () {
    $user = User::factory()->create();
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    Membership::factory()->for($organizationA)->for($user)->owner()->create();
    Membership::factory()->for($organizationB)->for($user)->employee()->create();

    expect($user->membershipFor($organizationA)->role)->toBe(OrganizationRole::Owner)
        ->and($user->membershipFor($organizationB)->role)->toBe(OrganizationRole::Employee)
        ->and($user->can('update', $organizationA))->toBeTrue()
        ->and($user->can('update', $organizationB))->toBeFalse();
});

test('membershipFor returns null when the user has no membership', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    expect($outsider->membershipFor($organization))->toBeNull();
});
