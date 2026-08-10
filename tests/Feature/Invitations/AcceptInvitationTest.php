<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->action = new AcceptInvitation;
    $this->organization = Organization::factory()->create();
});

test('accepting creates a membership with the invited role', function (OrganizationRole $role) {
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    $invitation = Invitation::factory()
        ->for($this->organization)
        ->forEmail('invitee@example.com')
        ->create(['role' => $role]);

    $membership = $this->action->handle($invitation, $user);

    expect($membership->role)->toBe($role)
        ->and($membership->organization_id)->toBe($this->organization->id)
        ->and($membership->user_id)->toBe($user->id)
        ->and($user->membershipFor($this->organization)->role)->toBe($role);
})->with([
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('accepting marks the invitation as accepted', function () {
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->create();

    $this->action->handle($invitation, $user);

    expect($invitation->refresh()->accepted_at)->not->toBeNull()
        ->and($invitation->status())->toBe(InvitationStatus::Accepted);
});

test('an invitation cannot be accepted twice', function () {
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->create();

    $this->action->handle($invitation, $user);

    expect(fn () => $this->action->handle($invitation->refresh(), $user))
        ->toThrow(ValidationException::class);

    expect($this->organization->memberships()->count())->toBe(1);
});

test('an expired invitation cannot be accepted', function () {
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->expired()->create();

    expect(fn () => $this->action->handle($invitation, $user))->toThrow(ValidationException::class);

    expect($this->organization->memberships()->count())->toBe(0);
});

test('a revoked invitation cannot be accepted', function () {
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->revoked()->create();

    expect(fn () => $this->action->handle($invitation, $user))->toThrow(ValidationException::class);

    expect($this->organization->memberships()->count())->toBe(0);
});

test('a user with a different email cannot accept the invitation', function () {
    $other = User::factory()->create(['email' => 'someone-else@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->create();

    expect(fn () => $this->action->handle($invitation, $other))->toThrow(ValidationException::class);

    expect($this->organization->memberships()->count())->toBe(0)
        ->and($invitation->refresh()->accepted_at)->toBeNull();
});

test('the email match is case insensitive', function () {
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('INVITEE@EXAMPLE.COM')->create();

    $membership = $this->action->handle($invitation, $user);

    expect($membership->user_id)->toBe($user->id);
});

test('an existing member consumes the invitation without a duplicate membership', function () {
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $existing = Membership::factory()->for($this->organization)->for($user)->employee()->create();

    $invitation = Invitation::factory()
        ->for($this->organization)
        ->forEmail('invitee@example.com')
        ->create(['role' => OrganizationRole::Manager]);

    $membership = $this->action->handle($invitation, $user);

    expect($membership->id)->toBe($existing->id)
        ->and($this->organization->memberships()->count())->toBe(1)
        ->and($membership->refresh()->role)->toBe(OrganizationRole::Employee)
        ->and($invitation->refresh()->status())->toBe(InvitationStatus::Accepted);
});

test('accepting one organization\'s invitation grants no access to another', function () {
    $other = Organization::factory()->create();

    $user = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->create();

    $this->action->handle($invitation, $user);

    expect($user->membershipFor($this->organization))->not->toBeNull()
        ->and($user->membershipFor($other))->toBeNull()
        ->and($user->can('view', $other))->toBeFalse();
});

test('accepting never marks the user email as verified', function () {
    $user = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->create();

    $this->action->handle($invitation, $user);

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('a failed acceptance leaves no membership behind', function () {
    $other = User::factory()->create(['email' => 'someone-else@example.com']);
    $invitation = Invitation::factory()->for($this->organization)->forEmail('invitee@example.com')->create();

    try {
        $this->action->handle($invitation, $other);
    } catch (ValidationException) {
        // expected
    }

    expect(Membership::count())->toBe(0);
});

test('accepting an invitation for the manager role does not make an owner', function () {
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    $invitation = Invitation::factory()
        ->for($this->organization)
        ->forEmail('invitee@example.com')
        ->manager()
        ->create();

    $membership = $this->action->handle($invitation, $user);

    expect($membership->role)->toBe(OrganizationRole::Manager)
        ->and($this->organization->owners()->count())->toBe(0);
});
