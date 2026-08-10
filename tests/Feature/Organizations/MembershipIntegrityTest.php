<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('the database rejects a duplicate membership for the same user and organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->for($organization)->for($user)->employee()->create();

    expect($organization->memberships()->count())->toBe(1);

    expect(fn () => Membership::factory()->for($organization)->for($user)->manager()->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the same user may hold memberships in different organizations', function () {
    $user = User::factory()->create();

    Membership::factory()->for(Organization::factory())->for($user)->employee()->create();
    Membership::factory()->for(Organization::factory())->for($user)->employee()->create();

    expect($user->memberships()->count())->toBe(2);
});

test('an organization may have multiple owners', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();

    expect($organization->owners()->count())->toBe(2);
});

test('deleting an organization cascades its memberships', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->employee()->create();

    expect(Membership::count())->toBe(2);

    $organization->delete();

    expect(Membership::count())->toBe(0)
        ->and(User::count())->toBe(2);
});

test('deleting a user who is not a sole owner cascades their memberships', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $employeeMembership = Membership::factory()->for($organization)->employee()->create();
    $employee = $employeeMembership->user;

    $employee->delete();

    $this->assertModelMissing($employee);
    $this->assertModelMissing($employeeMembership);
    expect($organization->memberships()->count())->toBe(1);
});

test('other members remain intact when another user is deleted', function () {
    $organization = Organization::factory()->create();

    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $managerMembership = Membership::factory()->for($organization)->manager()->create();
    $employeeMembership = Membership::factory()->for($organization)->employee()->create();

    $employeeMembership->user->delete();

    $this->assertModelExists($ownerMembership);
    $this->assertModelExists($managerMembership);
    expect($organization->memberships()->count())->toBe(2)
        ->and($organization->owners()->count())->toBe(1);
});

test('deleting the sole owner of an organization is blocked', function () {
    $organization = Organization::factory()->create();
    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $owner = $ownerMembership->user;

    expect(fn () => $owner->delete())->toThrow(RuntimeException::class);

    $this->assertModelExists($owner);
    $this->assertModelExists($ownerMembership);
    expect($organization->owners()->count())->toBe(1);
});

test('deleting an owner is allowed when another owner remains', function () {
    $organization = Organization::factory()->create();

    $firstOwnerMembership = Membership::factory()->for($organization)->owner()->create();
    $secondOwnerMembership = Membership::factory()->for($organization)->owner()->create();

    $firstOwnerMembership->user->delete();

    $this->assertModelMissing($firstOwnerMembership);
    $this->assertModelExists($secondOwnerMembership);
    expect($organization->owners()->count())->toBe(1);
});

test('a user who is sole owner of one organization is protected even when they belong to others', function () {
    $soleOwnedOrganization = Organization::factory()->create();
    $sharedOrganization = Organization::factory()->create();

    $user = User::factory()->create();

    Membership::factory()->for($soleOwnedOrganization)->for($user)->owner()->create();
    Membership::factory()->for($sharedOrganization)->for($user)->employee()->create();

    expect($user->isSoleOwnerOfAnyOrganization())->toBeTrue()
        ->and(fn () => $user->delete())->toThrow(RuntimeException::class);

    $this->assertModelExists($user);
});

test('a user who owns nothing solely is not flagged as a sole owner', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->for($user)->manager()->create();

    expect($user->isSoleOwnerOfAnyOrganization())->toBeFalse();
});

test('an organization exposes its users and reports membership correctly', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create()->user;
    $employee = Membership::factory()->for($organization)->employee()->create()->user;

    expect($organization->users()->count())->toBe(2)
        ->and($organization->users->pluck('id')->all())
        ->toEqualCanonicalizing([$owner->id, $employee->id])
        ->and($organization->hasMember($owner))->toBeTrue()
        ->and($organization->hasMember($employee))->toBeTrue()
        ->and($organization->hasMember($outsider))->toBeFalse();
});

test('the role column is cast to the organization role enum', function () {
    $membership = Membership::factory()->owner()->create();

    expect($membership->refresh()->role)->toBe(OrganizationRole::Owner);
});
