<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Model-level invariant: enforced for every caller, not just the policies
|--------------------------------------------------------------------------
*/

test('the last owner membership cannot be deleted directly through the model', function () {
    $organization = Organization::factory()->create();
    $membership = Membership::factory()->for($organization)->owner()->create();

    expect(fn () => $membership->delete())->toThrow(RuntimeException::class);

    $this->assertModelExists($membership);
    expect($organization->owners()->count())->toBe(1);
});

test('the last owner cannot be demoted directly through the model', function (OrganizationRole $target) {
    $organization = Organization::factory()->create();
    $membership = Membership::factory()->for($organization)->owner()->create();

    expect(fn () => $membership->update(['role' => $target]))->toThrow(RuntimeException::class);

    expect($membership->refresh()->role)->toBe(OrganizationRole::Owner)
        ->and($organization->owners()->count())->toBe(1);
})->with([
    'to manager' => OrganizationRole::Manager,
    'to employee' => OrganizationRole::Employee,
]);

test('the last owner cannot be demoted through a mass update either', function () {
    $organization = Organization::factory()->create();
    $membership = Membership::factory()->for($organization)->owner()->create();

    expect(fn () => $membership->fill(['role' => OrganizationRole::Employee])->save())
        ->toThrow(RuntimeException::class);

    expect($organization->owners()->count())->toBe(1);
});

test('the last owner cannot remove themselves', function () {
    $organization = Organization::factory()->create();
    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $owner = $ownerMembership->user;

    expect($owner->can('delete', $ownerMembership))->toBeFalse();
    expect(fn () => $ownerMembership->delete())->toThrow(RuntimeException::class);

    expect($organization->owners()->count())->toBe(1);
});

test('the last owner cannot change their own role away from owner', function () {
    $organization = Organization::factory()->create();
    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $owner = $ownerMembership->user;

    expect($owner->can('updateRole', [$ownerMembership, OrganizationRole::Manager]))->toBeFalse();
    expect(fn () => $ownerMembership->update(['role' => OrganizationRole::Manager]))
        ->toThrow(RuntimeException::class);

    expect($organization->owners()->count())->toBe(1);
});

test('an organization can never reach zero owners through any membership path', function () {
    $organization = Organization::factory()->create();
    $first = Membership::factory()->for($organization)->owner()->create();
    $second = Membership::factory()->for($organization)->owner()->create();

    // Removing one owner is fine while another remains.
    $first->delete();
    expect($organization->owners()->count())->toBe(1);

    // The survivor is now protected by both paths.
    expect(fn () => $second->refresh()->delete())->toThrow(RuntimeException::class);
    expect(fn () => $second->refresh()->update(['role' => OrganizationRole::Employee]))
        ->toThrow(RuntimeException::class);

    expect($organization->owners()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Normal behaviour with two or more owners is unchanged
|--------------------------------------------------------------------------
*/

test('one of two owners can be demoted', function () {
    $organization = Organization::factory()->create();
    $first = Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();

    $first->update(['role' => OrganizationRole::Employee]);

    expect($first->refresh()->role)->toBe(OrganizationRole::Employee)
        ->and($organization->owners()->count())->toBe(1);
});

test('one of two owners can be removed', function () {
    $organization = Organization::factory()->create();
    $first = Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();

    $first->delete();

    $this->assertModelMissing($first);
    expect($organization->owners()->count())->toBe(1);
});

test('promoting a member to owner is never blocked', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    $employee->update(['role' => OrganizationRole::Owner]);

    expect($employee->refresh()->role)->toBe(OrganizationRole::Owner)
        ->and($organization->owners()->count())->toBe(2);
});

test('keeping the last owner as owner is not treated as a change', function () {
    $organization = Organization::factory()->create();
    $membership = Membership::factory()->for($organization)->owner()->create();

    $membership->update(['role' => OrganizationRole::Owner]);

    expect($membership->refresh()->role)->toBe(OrganizationRole::Owner);
});

test('non-owner memberships are unaffected by the guard', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    $manager->update(['role' => OrganizationRole::Employee]);
    $employee->delete();

    expect($manager->refresh()->role)->toBe(OrganizationRole::Employee);
    $this->assertModelMissing($employee);
});

test('the guard is scoped to each organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = Membership::factory()->for($organizationA)->owner()->create();
    Membership::factory()->for($organizationB)->owner()->create();

    // Organization B having an owner does not make A's owner removable.
    expect(fn () => $ownerOfA->delete())->toThrow(RuntimeException::class);

    expect($organizationA->owners()->count())->toBe(1)
        ->and($organizationB->owners()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The guard must not block legitimate cascades
|--------------------------------------------------------------------------
*/

test('deleting an organization still cascades its sole owner membership', function () {
    $organization = Organization::factory()->create();
    $membership = Membership::factory()->for($organization)->owner()->create();
    $user = $membership->user;

    $organization->delete();

    $this->assertModelMissing($organization);
    $this->assertModelMissing($membership);
    $this->assertModelExists($user);
});

test('deleting a user who is not a sole owner still cascades their membership', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();
    $employeeMembership = Membership::factory()->for($organization)->employee()->create();
    $employee = $employeeMembership->user;

    $employee->delete();

    $this->assertModelMissing($employeeMembership);
    expect($organization->owners()->count())->toBe(1);
});

test('deleting a sole owner user is still blocked', function () {
    $organization = Organization::factory()->create();
    $membership = Membership::factory()->for($organization)->owner()->create();
    $owner = $membership->user;

    expect(fn () => $owner->delete())->toThrow(RuntimeException::class);

    $this->assertModelExists($owner);
    expect($organization->owners()->count())->toBe(1);
});

test('an accepted invitation never removes an owner', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();

    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($organization)->forEmail('invitee@example.com')->create();

    (new AcceptInvitation)->handle($invitation, $user);

    expect($organization->owners()->count())->toBe(1)
        ->and($organization->memberships()->count())->toBe(2);
});

test('project work never affects organization ownership', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();

    Project::factory()->for($organization)->count(2)->create();
    $organization->projects()->first()->delete();

    expect($organization->owners()->count())->toBe(1);
});
