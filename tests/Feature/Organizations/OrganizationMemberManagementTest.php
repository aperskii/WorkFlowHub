<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the members page as the given user.
 */
function membersPageAs(User $user, Organization $organization): Testable
{
    return Livewire::actingAs($user)->test('pages::organizations.members', ['organization' => $organization]);
}

/**
 * Build the rendered data-test attribute for a role control.
 */
function roleControl(Membership $membership): string
{
    return 'data-test="change-role-'.$membership->id.'"';
}

/**
 * Build the rendered data-test attribute for a remove control.
 */
function removeControl(Membership $membership): string
{
    return 'data-test="remove-member-'.$membership->id.'"';
}

/*
|--------------------------------------------------------------------------
| Visibility
|--------------------------------------------------------------------------
*/

test('an owner sees management controls for every other member', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $secondOwner = Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($owner->user, $organization)
        ->assertSee(roleControl($employee), escape: false)
        ->assertSee(removeControl($employee), escape: false)
        ->assertSee(roleControl($manager), escape: false)
        ->assertSee(removeControl($manager), escape: false)
        ->assertSee(roleControl($secondOwner), escape: false)
        ->assertSee(removeControl($secondOwner), escape: false);
});

test('a manager sees management controls for managers and employees only', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $otherManager = Membership::factory()->for($organization)->manager()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($manager->user, $organization)
        ->assertSee(roleControl($employee), escape: false)
        ->assertSee(removeControl($employee), escape: false)
        ->assertSee(roleControl($otherManager), escape: false)
        ->assertSee(removeControl($otherManager), escape: false)
        ->assertDontSee(roleControl($owner), escape: false)
        ->assertDontSee(removeControl($owner), escape: false);
});

test('an employee sees no management controls at all', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($employee->user, $organization)
        ->assertDontSee(roleControl($manager), escape: false)
        ->assertDontSee(removeControl($manager), escape: false)
        ->assertDontSee(roleControl($employee), escape: false)
        ->assertDontSee(removeControl($employee), escape: false);
});

test('the sole owner has no remove control', function () {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create();

    membersPageAs($owner->user, $organization)
        ->assertDontSee(removeControl($owner), escape: false);
});

test('the members page shows each member joined date', function () {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create();

    membersPageAs($owner->user, $organization)
        ->assertSee($owner->created_at->toFormattedDateString());
});

test('a non-member cannot open the members page', function () {
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->owner()->create();

    membersPageAs(User::factory()->create(), $organization)->assertForbidden();
});

test('a member of another organization cannot open the members page', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    Membership::factory()->for($organizationB)->owner()->create();

    membersPageAs($memberOfA, $organizationB)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Role changes
|--------------------------------------------------------------------------
*/

test('an owner can change a member to any role', function (OrganizationRole $from, OrganizationRole $to) {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $target = Membership::factory()->for($organization)->create(['role' => $from]);

    membersPageAs($owner->user, $organization)
        ->call('updateRole', $target->id, $to->value)
        ->assertHasNoErrors();

    expect($target->refresh()->role)->toBe($to)
        ->and($organization->owners()->count())->toBeGreaterThanOrEqual(1);
})->with([
    'employee to manager' => [OrganizationRole::Employee, OrganizationRole::Manager],
    'employee to owner' => [OrganizationRole::Employee, OrganizationRole::Owner],
    'manager to employee' => [OrganizationRole::Manager, OrganizationRole::Employee],
    'manager to owner' => [OrganizationRole::Manager, OrganizationRole::Owner],
]);

test('an owner can change another owner\'s role while an owner remains', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $secondOwner = Membership::factory()->for($organization)->owner()->create();

    membersPageAs($owner->user, $organization)
        ->call('updateRole', $secondOwner->id, OrganizationRole::Manager->value)
        ->assertHasNoErrors();

    expect($secondOwner->refresh()->role)->toBe(OrganizationRole::Manager)
        ->and($organization->owners()->count())->toBe(1);
});

test('a manager can move members between manager and employee', function (OrganizationRole $from, OrganizationRole $to) {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $target = Membership::factory()->for($organization)->create(['role' => $from]);

    membersPageAs($manager->user, $organization)
        ->call('updateRole', $target->id, $to->value)
        ->assertHasNoErrors();

    expect($target->refresh()->role)->toBe($to);
})->with([
    'employee to manager' => [OrganizationRole::Employee, OrganizationRole::Manager],
    'manager to employee' => [OrganizationRole::Manager, OrganizationRole::Employee],
]);

test('a manager cannot change an owner\'s role', function (OrganizationRole $to) {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();

    membersPageAs($manager->user, $organization)
        ->call('updateRole', $owner->id, $to->value)
        ->assertHasErrors('members');

    expect($owner->refresh()->role)->toBe(OrganizationRole::Owner);
})->with([
    'to manager' => OrganizationRole::Manager,
    'to employee' => OrganizationRole::Employee,
]);

test('a manager cannot grant the owner role to anyone', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($manager->user, $organization)
        ->call('updateRole', $employee->id, OrganizationRole::Owner->value)
        ->assertHasErrors('members');

    expect($employee->refresh()->role)->toBe(OrganizationRole::Employee)
        ->and($organization->owners()->count())->toBe(1);
});

test('a manager cannot promote themselves to owner', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();

    membersPageAs($manager->user, $organization)
        ->call('updateRole', $manager->id, OrganizationRole::Owner->value)
        ->assertHasErrors('members');

    expect($manager->refresh()->role)->toBe(OrganizationRole::Manager)
        ->and($organization->owners()->count())->toBe(1);
});

test('an employee cannot change any role', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();
    $target = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($employee->user, $organization)
        ->call('updateRole', $target->id, OrganizationRole::Manager->value)
        ->assertHasErrors('members');

    expect($target->refresh()->role)->toBe(OrganizationRole::Employee);
});

test('an unknown role value is rejected', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($owner->user, $organization)
        ->call('updateRole', $employee->id, 'superadmin')
        ->assertHasErrors('members');

    expect($employee->refresh()->role)->toBe(OrganizationRole::Employee);
});

/*
|--------------------------------------------------------------------------
| Tenant isolation / tampering
|--------------------------------------------------------------------------
*/

test('a membership from another organization cannot be modified', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = Membership::factory()->for($organizationA)->owner()->create();
    Membership::factory()->for($organizationB)->owner()->create();
    $employeeOfB = Membership::factory()->for($organizationB)->employee()->create();

    $component = membersPageAs($ownerOfA->user, $organizationA);

    expect(fn () => $component->call('updateRole', $employeeOfB->id, OrganizationRole::Manager->value))
        ->toThrow(ModelNotFoundException::class);

    expect($employeeOfB->refresh()->role)->toBe(OrganizationRole::Employee);
});

test('a membership from another organization cannot be removed', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = Membership::factory()->for($organizationA)->owner()->create();
    Membership::factory()->for($organizationB)->owner()->create();
    $employeeOfB = Membership::factory()->for($organizationB)->employee()->create();

    $component = membersPageAs($ownerOfA->user, $organizationA);

    expect(fn () => $component->call('confirmRemoval', $employeeOfB->id))
        ->toThrow(ModelNotFoundException::class);

    $this->assertModelExists($employeeOfB);
});

test('a non-existent membership identifier is rejected', function () {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create();

    $component = membersPageAs($owner->user, $organization);

    expect(fn () => $component->call('updateRole', 999_999, OrganizationRole::Manager->value))
        ->toThrow(ModelNotFoundException::class);
});

/*
|--------------------------------------------------------------------------
| Last owner protection
|--------------------------------------------------------------------------
*/

test('the sole owner cannot be demoted', function (OrganizationRole $to) {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create();

    membersPageAs($owner->user, $organization)
        ->call('updateRole', $owner->id, $to->value)
        ->assertHasErrors('members');

    expect($owner->refresh()->role)->toBe(OrganizationRole::Owner)
        ->and($organization->owners()->count())->toBe(1);
})->with([
    'to manager' => OrganizationRole::Manager,
    'to employee' => OrganizationRole::Employee,
]);

test('the sole owner cannot be removed', function () {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create();

    membersPageAs($owner->user, $organization)
        ->call('confirmRemoval', $owner->id)
        ->assertHasErrors('members');

    $this->assertModelExists($owner);
    expect($organization->owners()->count())->toBe(1);
});

test('one of two owners can be demoted', function () {
    $organization = Organization::factory()->create();

    $first = Membership::factory()->for($organization)->owner()->create();
    $second = Membership::factory()->for($organization)->owner()->create();

    membersPageAs($first->user, $organization)
        ->call('updateRole', $second->id, OrganizationRole::Employee->value)
        ->assertHasNoErrors();

    expect($second->refresh()->role)->toBe(OrganizationRole::Employee)
        ->and($organization->owners()->count())->toBe(1);
});

test('one of two owners can be removed', function () {
    $organization = Organization::factory()->create();

    $first = Membership::factory()->for($organization)->owner()->create();
    $second = Membership::factory()->for($organization)->owner()->create();

    membersPageAs($first->user, $organization)
        ->call('confirmRemoval', $second->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertModelMissing($second);
    expect($organization->owners()->count())->toBe(1);
});

test('an owner can demote themselves while another owner remains', function () {
    $organization = Organization::factory()->create();

    $first = Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();

    membersPageAs($first->user, $organization)
        ->call('updateRole', $first->id, OrganizationRole::Employee->value)
        ->assertHasNoErrors();

    expect($first->refresh()->role)->toBe(OrganizationRole::Employee)
        ->and($organization->owners()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Removal
|--------------------------------------------------------------------------
*/

test('an owner can remove a manager or an employee', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $target = Membership::factory()->for($organization)->create(['role' => $role]);

    membersPageAs($owner->user, $organization)
        ->call('confirmRemoval', $target->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertModelMissing($target);
    expect($organization->memberships()->count())->toBe(1);
})->with([
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('a manager can remove an employee', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($manager->user, $organization)
        ->call('confirmRemoval', $employee->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertModelMissing($employee);
});

test('a manager cannot remove an owner', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();

    membersPageAs($manager->user, $organization)
        ->call('confirmRemoval', $owner->id)
        ->assertHasErrors('members');

    $this->assertModelExists($owner);
});

test('an employee cannot remove anyone', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();
    $target = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($employee->user, $organization)
        ->call('confirmRemoval', $target->id)
        ->assertHasErrors('members');

    $this->assertModelExists($target);
});

test('removing a member deletes only that membership', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $manager = Membership::factory()->for($organization)->manager()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($owner->user, $organization)
        ->call('confirmRemoval', $employee->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertModelMissing($employee);
    $this->assertModelExists($owner);
    $this->assertModelExists($manager);
    expect($organization->memberships()->count())->toBe(2);
});

test('removing a member does not delete their user account', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();
    $employeeUser = $employee->user;

    membersPageAs($owner->user, $organization)
        ->call('confirmRemoval', $employee->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertModelMissing($employee);
    $this->assertModelExists($employeeUser);
});

test('removing a member from one organization keeps their other memberships', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $owner = Membership::factory()->for($organizationA)->owner()->create();

    $sharedUser = User::factory()->create();
    $membershipInA = Membership::factory()->for($organizationA)->for($sharedUser)->employee()->create();
    $membershipInB = Membership::factory()->for($organizationB)->for($sharedUser)->owner()->create();

    membersPageAs($owner->user, $organizationA)
        ->call('confirmRemoval', $membershipInA->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertModelMissing($membershipInA);
    $this->assertModelExists($membershipInB);
    expect($sharedUser->refresh()->organizations()->count())->toBe(1)
        ->and($organizationB->owners()->count())->toBe(1);
});

test('the removal can be cancelled without deleting anything', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($owner->user, $organization)
        ->call('confirmRemoval', $employee->id)
        ->assertSet('removingMembershipId', $employee->id)
        ->call('cancelRemoval')
        ->assertSet('removingMembershipId', null);

    $this->assertModelExists($employee);
});

test('the member list refreshes after a successful mutation', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();
    $employeeName = $employee->user->name;

    $component = membersPageAs($owner->user, $organization)->assertSee($employeeName);

    $component->call('confirmRemoval', $employee->id)
        ->call('removeMember')
        ->assertDontSee($employeeName);
});

test('a successful role change is reflected in the rendered list', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    membersPageAs($owner->user, $organization)
        ->call('updateRole', $employee->id, OrganizationRole::Manager->value)
        ->assertHasNoErrors()
        ->assertSee(OrganizationRole::Manager->label());

    expect($employee->refresh()->role)->toBe(OrganizationRole::Manager);
});

test('the locked organization property cannot be swapped on the members page', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = Membership::factory()->for($organizationA)->owner()->create();
    Membership::factory()->for($organizationB)->owner()->create();

    $component = membersPageAs($ownerOfA->user, $organizationA);

    expect(fn () => $component->set('organization', $organizationB))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the queued removal identifier cannot be set from the browser', function () {
    $organization = Organization::factory()->create();

    $owner = Membership::factory()->for($organization)->owner()->create();
    $employee = Membership::factory()->for($organization)->employee()->create();

    $component = membersPageAs($owner->user, $organization);

    expect(fn () => $component->set('removingMembershipId', $employee->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $this->assertModelExists($employee);
});
