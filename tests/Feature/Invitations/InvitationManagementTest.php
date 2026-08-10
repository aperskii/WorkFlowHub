<?php

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the members page as the given user.
 */
function membersPageFor(User $user, Organization $organization): Testable
{
    return Livewire::actingAs($user)->test('pages::organizations.members', ['organization' => $organization]);
}

/*
|--------------------------------------------------------------------------
| Sending from the members page
|--------------------------------------------------------------------------
*/

test('an owner or manager can invite a manager or an employee', function (
    OrganizationRole $actorRole,
    OrganizationRole $invitedRole
) {
    Notification::fake();

    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $actor = memberWithRole($organization, $actorRole);

    membersPageFor($actor, $organization)
        ->set('inviteEmail', 'teammate@example.com')
        ->set('inviteRole', $invitedRole->value)
        ->call('sendInvitation')
        ->assertHasNoErrors();

    $invitation = $organization->invitations()->sole();

    expect($invitation->email)->toBe('teammate@example.com')
        ->and($invitation->role)->toBe($invitedRole)
        ->and($invitation->invited_by_user_id)->toBe($actor->id);

    Notification::assertSentOnDemand(OrganizationInvitation::class);
})->with([
    'owner invites manager' => [OrganizationRole::Owner, OrganizationRole::Manager],
    'owner invites employee' => [OrganizationRole::Owner, OrganizationRole::Employee],
    'manager invites manager' => [OrganizationRole::Manager, OrganizationRole::Manager],
    'manager invites employee' => [OrganizationRole::Manager, OrganizationRole::Employee],
]);

test('nobody can invite an owner through the members page', function (OrganizationRole $actorRole) {
    Notification::fake();

    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $actor = memberWithRole($organization, $actorRole);

    membersPageFor($actor, $organization)
        ->set('inviteEmail', 'teammate@example.com')
        ->set('inviteRole', OrganizationRole::Owner->value)
        ->call('sendInvitation')
        ->assertHasErrors('inviteRole');

    expect(Invitation::count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
]);

test('an employee cannot invite anyone', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    membersPageFor($employee, $organization)
        ->set('inviteEmail', 'teammate@example.com')
        ->set('inviteRole', OrganizationRole::Employee->value)
        ->call('sendInvitation')
        ->assertForbidden();

    expect(Invitation::count())->toBe(0);
    Notification::assertNothingSent();
});

test('a non-member cannot invite anyone', function () {
    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);

    membersPageFor(User::factory()->create(), $organization)->assertForbidden();
});

test('the send action re-authorizes and is not trusted from mount alone', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    $actor = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Owner);

    $component = membersPageFor($actor, $organization)
        ->set('inviteEmail', 'teammate@example.com')
        ->set('inviteRole', OrganizationRole::Employee->value);

    $actor->membershipFor($organization)->update(['role' => OrganizationRole::Employee]);

    $component->call('sendInvitation')->assertForbidden();

    expect(Invitation::count())->toBe(0);
});

test('inviting an existing member is rejected with a friendly error', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $member = User::factory()->create(['email' => 'member@example.com']);
    Membership::factory()->for($organization)->for($member)->employee()->create();

    membersPageFor($owner, $organization)
        ->set('inviteEmail', 'member@example.com')
        ->set('inviteRole', OrganizationRole::Employee->value)
        ->call('sendInvitation')
        ->assertHasErrors('inviteEmail');

    expect(Invitation::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Visibility
|--------------------------------------------------------------------------
*/

test('invitation controls are only rendered for users who can manage members', function (
    OrganizationRole $role,
    bool $visible
) {
    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $actor = memberWithRole($organization, $role);
    Invitation::factory()->for($organization)->create();

    $component = membersPageFor($actor, $organization);

    foreach (['invite-member-button', 'pending-invitations'] as $control) {
        $visible
            ? $component->assertSee('data-test="'.$control.'"', escape: false)
            : $component->assertDontSee('data-test="'.$control.'"', escape: false);
    }
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'manager' => [OrganizationRole::Manager, true],
    'employee' => [OrganizationRole::Employee, false],
]);

test('an employee never sees invited email addresses', function () {
    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    Invitation::factory()->for($organization)->forEmail('secret-invitee@example.com')->create();

    membersPageFor($employee, $organization)->assertDontSee('secret-invitee@example.com');
});

test('the pending list only shows outstanding invitations for this organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Invitation::factory()->for($organization)->forEmail('pending@example.com')->create();
    Invitation::factory()->for($organization)->forEmail('accepted@example.com')->accepted()->create();
    Invitation::factory()->for($organization)->forEmail('revoked@example.com')->revoked()->create();
    Invitation::factory()->for($other)->forEmail('other-org@example.com')->create();

    membersPageFor($owner, $organization)
        ->assertSee('pending@example.com')
        ->assertDontSee('accepted@example.com')
        ->assertDontSee('revoked@example.com')
        ->assertDontSee('other-org@example.com');
});

/*
|--------------------------------------------------------------------------
| Resend
|--------------------------------------------------------------------------
*/

test('resending rotates the token and the old link stops working', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $originalToken = Invitation::generateToken();
    $invitation = Invitation::factory()
        ->for($organization)
        ->forEmail('invitee@example.com')
        ->withToken($originalToken)
        ->create();

    $this->get(route('invitations.show', $originalToken))->assertOk();

    membersPageFor($owner, $organization)
        ->call('resendInvitation', $invitation->id)
        ->assertHasNoErrors();

    expect($invitation->refresh()->token_hash)->not->toBe(Invitation::hashToken($originalToken));

    $this->get(route('invitations.show', $originalToken))->assertNotFound();

    Notification::assertSentOnDemand(OrganizationInvitation::class);
});

test('resending an expired invitation makes it pending again', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $invitation = Invitation::factory()->for($organization)->expired()->create();

    expect($invitation->status())->toBe(InvitationStatus::Expired);

    membersPageFor($owner, $organization)
        ->call('resendInvitation', $invitation->id)
        ->assertHasNoErrors();

    expect($invitation->refresh()->status())->toBe(InvitationStatus::Pending);
});

test('an employee cannot resend an invitation', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $invitation = Invitation::factory()->for($organization)->create();
    $originalHash = $invitation->token_hash;

    membersPageFor($employee, $organization)
        ->call('resendInvitation', $invitation->id)
        ->assertForbidden();

    expect($invitation->refresh()->token_hash)->toBe($originalHash);
    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Revoke
|--------------------------------------------------------------------------
*/

test('an owner can revoke an invitation and its link stops working', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    $token = Invitation::generateToken();
    $invitation = Invitation::factory()
        ->for($organization)
        ->forEmail('invitee@example.com')
        ->withToken($token)
        ->create();

    membersPageFor($owner, $organization)
        ->call('confirmRevoke', $invitation->id)
        ->assertSet('revokingInvitationId', $invitation->id)
        ->call('revokeInvitation')
        ->assertHasNoErrors();

    expect($invitation->refresh()->status())->toBe(InvitationStatus::Revoked);

    Livewire::actingAs($user)
        ->test('pages::invitations.show', ['token' => $token])
        ->call('accept')
        ->assertHasErrors('invitation');

    expect(Membership::where('organization_id', $organization->id)->count())->toBe(1);
});

test('a revocation can be cancelled', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $invitation = Invitation::factory()->for($organization)->create();

    membersPageFor($owner, $organization)
        ->call('confirmRevoke', $invitation->id)
        ->call('cancelRevoke')
        ->assertSet('revokingInvitationId', null);

    expect($invitation->refresh()->status())->toBe(InvitationStatus::Pending);
});

test('an employee cannot revoke an invitation', function () {
    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $invitation = Invitation::factory()->for($organization)->create();

    membersPageFor($employee, $organization)
        ->call('confirmRevoke', $invitation->id)
        ->assertForbidden();

    expect($invitation->refresh()->status())->toBe(InvitationStatus::Pending);
});

/*
|--------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------
*/

test('an invitation from another organization cannot be resent or revoked', function () {
    Notification::fake();

    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $invitationInB = Invitation::factory()->for($organizationB)->create();
    $originalHash = $invitationInB->token_hash;

    $component = membersPageFor($ownerOfA, $organizationA);

    expect(fn () => $component->call('resendInvitation', $invitationInB->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => $component->call('confirmRevoke', $invitationInB->id))
        ->toThrow(ModelNotFoundException::class);

    expect($invitationInB->refresh()->token_hash)->toBe($originalHash)
        ->and($invitationInB->status())->toBe(InvitationStatus::Pending);
});

test('the locked revoking invitation id cannot be set from the browser', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $invitation = Invitation::factory()->for($organization)->create();

    $component = membersPageFor($owner, $organization);

    expect(fn () => $component->set('revokingInvitationId', $invitation->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('inviting always targets the route bound organization', function () {
    Notification::fake();

    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    membersPageFor($owner, $organizationA)
        ->set('inviteEmail', 'teammate@example.com')
        ->set('inviteRole', OrganizationRole::Employee->value)
        ->call('sendInvitation')
        ->assertHasNoErrors();

    expect($organizationA->invitations()->count())->toBe(1)
        ->and($organizationB->invitations()->count())->toBe(0);
});
