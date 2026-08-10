<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

test('an owner can change another member\'s role', function () {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create()->user;
    $employeeMembership = Membership::factory()->for($organization)->employee()->create();

    expect($owner->can('updateRole', [$employeeMembership, OrganizationRole::Manager]))->toBeTrue();
});

test('an owner can remove another member', function () {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create()->user;
    $employeeMembership = Membership::factory()->for($organization)->employee()->create();

    expect($owner->can('delete', $employeeMembership))->toBeTrue();
});

test('the last owner cannot be demoted', function () {
    $organization = Organization::factory()->create();
    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $owner = $ownerMembership->user;

    expect($ownerMembership->isLastOwner())->toBeTrue()
        ->and($owner->can('updateRole', [$ownerMembership, OrganizationRole::Manager]))->toBeFalse()
        ->and($owner->can('updateRole', [$ownerMembership, OrganizationRole::Employee]))->toBeFalse();
});

test('the last owner cannot be removed', function () {
    $organization = Organization::factory()->create();
    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $owner = $ownerMembership->user;

    expect($owner->can('delete', $ownerMembership))->toBeFalse();
});

test('keeping the last owner as owner is not treated as a demotion', function () {
    $organization = Organization::factory()->create();
    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $owner = $ownerMembership->user;

    expect($owner->can('updateRole', [$ownerMembership, OrganizationRole::Owner]))->toBeTrue();
});

test('an owner can be demoted or removed while another owner remains', function () {
    $organization = Organization::factory()->create();
    $firstOwnerMembership = Membership::factory()->for($organization)->owner()->create();
    $secondOwnerMembership = Membership::factory()->for($organization)->owner()->create();

    $actor = $secondOwnerMembership->user;

    expect($firstOwnerMembership->isLastOwner())->toBeFalse()
        ->and($actor->can('updateRole', [$firstOwnerMembership, OrganizationRole::Manager]))->toBeTrue()
        ->and($actor->can('delete', $firstOwnerMembership))->toBeTrue();
});

test('a manager can manage non-owner memberships', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create()->user;
    $employeeMembership = Membership::factory()->for($organization)->employee()->create();

    expect($manager->can('updateRole', [$employeeMembership, OrganizationRole::Manager]))->toBeTrue()
        ->and($manager->can('delete', $employeeMembership))->toBeTrue();
});

test('a manager cannot demote or remove an owner', function () {
    $organization = Organization::factory()->create();
    $firstOwnerMembership = Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create()->user;

    expect($firstOwnerMembership->isLastOwner())->toBeFalse()
        ->and($manager->can('updateRole', [$firstOwnerMembership, OrganizationRole::Employee]))->toBeFalse()
        ->and($manager->can('delete', $firstOwnerMembership))->toBeFalse();
});

test('an employee cannot manage memberships', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create()->user;
    $otherMembership = Membership::factory()->for($organization)->employee()->create();

    expect($employee->can('updateRole', [$otherMembership, OrganizationRole::Manager]))->toBeFalse()
        ->and($employee->can('delete', $otherMembership))->toBeFalse();
});

test('a user with no membership cannot manage memberships', function () {
    $organization = Organization::factory()->create();
    $membership = Membership::factory()->for($organization)->employee()->create();
    $outsider = User::factory()->create();

    expect($outsider->can('updateRole', [$membership, OrganizationRole::Manager]))->toBeFalse()
        ->and($outsider->can('delete', $membership))->toBeFalse();
});

test('an owner cannot manage memberships in another organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = Membership::factory()->for($organizationA)->owner()->create()->user;

    Membership::factory()->for($organizationB)->owner()->create();
    $membershipInB = Membership::factory()->for($organizationB)->employee()->create();

    expect($ownerOfA->can('updateRole', [$membershipInB, OrganizationRole::Manager]))->toBeFalse()
        ->and($ownerOfA->can('delete', $membershipInB))->toBeFalse();
});
