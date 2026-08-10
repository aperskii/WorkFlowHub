<?php

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Create an invitation together with the raw token needed to visit it.
 *
 * @return array{0: Invitation, 1: string}
 */
function invitationWithToken(Organization $organization, string $email, array $state = []): array
{
    $token = Invitation::generateToken();

    $invitation = Invitation::factory()
        ->for($organization)
        ->forEmail($email)
        ->withToken($token)
        ->create($state);

    return [$invitation, $token];
}

/*
|--------------------------------------------------------------------------
| Token lookup
|--------------------------------------------------------------------------
*/

test('an unknown token returns 404', function () {
    $this->get(route('invitations.show', Invitation::generateToken()))->assertNotFound();
    $this->get('/invitations/garbage')->assertNotFound();
});

test('a token belonging to a deleted organization returns 404', function () {
    $organization = Organization::factory()->create();
    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com');

    $organization->delete();

    $this->get(route('invitations.show', $token))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/

test('a guest can view the invitation context', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $inviter = memberWithRole($organization, OrganizationRole::Owner);

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com', [
        'role' => OrganizationRole::Manager,
        'invited_by_user_id' => $inviter->id,
    ]);

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee('Acme Agency')
        ->assertSee($inviter->name)
        ->assertSee(OrganizationRole::Manager->label())
        ->assertSee('invitee@example.com');
});

test('a guest is offered sign in and registration links', function () {
    $organization = Organization::factory()->create();
    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com');

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee('data-test="invitation-guest-actions"', escape: false)
        ->assertSee(route('login', absolute: false), escape: false)
        ->assertSee(route('register', absolute: false), escape: false)
        ->assertDontSee('data-test="accept-invitation-button"', escape: false);
});

test('a guest cannot accept the invitation', function () {
    $organization = Organization::factory()->create();
    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com');

    Livewire::test('pages::invitations.show', ['token' => $token])
        ->call('accept')
        ->assertForbidden();

    expect($invitation->refresh()->accepted_at)->toBeNull()
        ->and(Membership::count())->toBe(0);
});

test('remembering the intended url returns the guest to the invitation', function () {
    $organization = Organization::factory()->create();
    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com');

    Livewire::test('pages::invitations.show', ['token' => $token])->call('rememberIntendedUrl');

    expect(session('url.intended'))->toBe(route('invitations.show', $token));
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

test('the invited user can accept and becomes a member with the invited role', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com', [
        'role' => OrganizationRole::Manager,
    ]);

    Livewire::actingAs($user)
        ->test('pages::invitations.show', ['token' => $token])
        ->assertSee('data-test="accept-invitation-button"', escape: false)
        ->call('accept')
        ->assertRedirect(route('organizations.dashboard', $organization));

    expect($user->membershipFor($organization)->role)->toBe(OrganizationRole::Manager)
        ->and($invitation->refresh()->status())->toBe(InvitationStatus::Accepted);
});

test('a user signed in with a different email sees a denial and cannot accept', function () {
    $organization = Organization::factory()->create();
    $other = User::factory()->create(['email' => 'someone-else@example.com']);

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com');

    $component = Livewire::actingAs($other)->test('pages::invitations.show', ['token' => $token]);

    $component->assertSee('data-test="invitation-email-mismatch"', escape: false)
        ->assertDontSee('data-test="accept-invitation-button"', escape: false);

    $component->call('accept')->assertHasErrors('invitation');

    expect(Membership::count())->toBe(0)
        ->and($invitation->refresh()->accepted_at)->toBeNull();
});

test('an existing member sees a friendly already-a-member state', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    Membership::factory()->for($organization)->for($user)->employee()->create();

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com');

    Livewire::actingAs($user)
        ->test('pages::invitations.show', ['token' => $token])
        ->assertSee('data-test="invitation-already-member"', escape: false)
        ->assertDontSee('data-test="accept-invitation-button"', escape: false);

    expect($organization->memberships()->count())->toBe(1);
});

test('an unverified user is asked to verify before accepting', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->unverified()->create(['email' => 'invitee@example.com']);

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com');

    $component = Livewire::actingAs($user)->test('pages::invitations.show', ['token' => $token]);

    $component->assertSee('data-test="invitation-unverified"', escape: false)
        ->assertDontSee('data-test="accept-invitation-button"', escape: false);

    $component->call('accept')->assertForbidden();

    expect(Membership::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Unusable states
|--------------------------------------------------------------------------
*/

test('an expired invitation shows an expired state and cannot be accepted', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com', [
        'expires_at' => now()->subDay(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::invitations.show', ['token' => $token]);

    $component->assertSee('data-test="invitation-expired"', escape: false)
        ->assertDontSee('data-test="accept-invitation-button"', escape: false);

    $component->call('accept')->assertHasErrors('invitation');

    expect(Membership::count())->toBe(0);
});

test('a revoked invitation shows a revoked state and cannot be accepted', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com', [
        'revoked_at' => now(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::invitations.show', ['token' => $token]);

    $component->assertSee('data-test="invitation-revoked"', escape: false);

    $component->call('accept')->assertHasErrors('invitation');

    expect(Membership::count())->toBe(0);
});

test('an already accepted invitation cannot be used again', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    [$invitation, $token] = invitationWithToken($organization, 'invitee@example.com', [
        'accepted_at' => now(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::invitations.show', ['token' => $token]);

    $component->assertSee('data-test="invitation-accepted"', escape: false);

    $component->call('accept')->assertHasErrors('invitation');

    expect(Membership::count())->toBe(0);
});

test('the token property cannot be swapped by the browser', function () {
    $organization = Organization::factory()->create();
    [$first, $firstToken] = invitationWithToken($organization, 'first@example.com');
    [$second, $secondToken] = invitationWithToken($organization, 'second@example.com');

    $component = Livewire::test('pages::invitations.show', ['token' => $firstToken]);

    expect(fn () => $component->set('token', $secondToken))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
