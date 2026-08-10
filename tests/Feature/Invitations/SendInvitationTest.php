<?php

use App\Actions\Invitations\SendInvitation;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->action = new SendInvitation;
    $this->organization = Organization::factory()->create();
    $this->inviter = memberWithRole($this->organization, OrganizationRole::Owner);
});

test('an invitation is created for the organization', function () {
    Notification::fake();

    $invitation = $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        OrganizationRole::Employee,
    );

    $this->assertModelExists($invitation);

    expect($invitation->organization_id)->toBe($this->organization->id)
        ->and($invitation->email)->toBe('teammate@example.com')
        ->and($invitation->role)->toBe(OrganizationRole::Employee)
        ->and($invitation->invited_by_user_id)->toBe($this->inviter->id)
        ->and($invitation->status())->toBe(InvitationStatus::Pending);
});

test('the email address is normalized to lowercase', function () {
    Notification::fake();

    $invitation = $this->action->handle(
        $this->organization,
        $this->inviter,
        '  TeamMate@Example.COM  ',
        OrganizationRole::Employee,
    );

    expect($invitation->email)->toBe('teammate@example.com');
});

test('the invitation expires after the configured window', function () {
    Notification::fake();
    config(['auth.invitation_expires_after_days' => 3]);

    $invitation = $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        OrganizationRole::Employee,
    );

    expect($invitation->expires_at->isAfter(now()->addDays(2)))->toBeTrue()
        ->and($invitation->expires_at->isBefore(now()->addDays(4)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Token security
|--------------------------------------------------------------------------
*/

test('only a sha256 hash of the token is stored', function () {
    Notification::fake();

    $invitation = $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        OrganizationRole::Employee,
    );

    expect($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->token_hash)->toMatch('/^[a-f0-9]{64}$/');

    expect(array_keys($invitation->getAttributes()))->not->toContain('token');
});

test('the raw token never appears anywhere in the invitations table', function () {
    $captured = null;

    Notification::fake();
    Notification::assertNothingSent();

    $invitation = $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        OrganizationRole::Employee,
    );

    Notification::assertSentOnDemand(
        OrganizationInvitation::class,
        function (OrganizationInvitation $notification) use (&$captured) {
            $captured = (new ReflectionProperty($notification, 'token'))->getValue($notification);

            return true;
        }
    );

    expect($captured)->toBeString()->toHaveLength(64);

    $row = json_encode(Invitation::find($invitation->id)->getAttributes());

    expect($row)->not->toContain($captured)
        ->and($invitation->token_hash)->toBe(hash('sha256', $captured));
});

/*
|--------------------------------------------------------------------------
| Email delivery
|--------------------------------------------------------------------------
*/

test('the invitation email is sent on demand to the invited address', function () {
    Notification::fake();

    $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        OrganizationRole::Manager,
    );

    Notification::assertSentOnDemand(OrganizationInvitation::class);
});

test('the invitation email names the organization, inviter, role, and expiry', function () {
    Notification::fake();

    $invitation = $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        OrganizationRole::Manager,
    );

    Notification::assertSentOnDemand(
        OrganizationInvitation::class,
        function (OrganizationInvitation $notification) use ($invitation) {
            $mail = $notification->toMail(new AnonymousNotifiable);
            $rendered = implode(' ', array_merge([$mail->subject], $mail->introLines, $mail->outroLines));

            return str_contains($mail->subject, $this->organization->name)
                && str_contains($rendered, $this->inviter->name)
                && str_contains($rendered, OrganizationRole::Manager->label())
                && str_contains($rendered, $invitation->expires_at->toDayDateTimeString())
                && str_contains($mail->actionUrl, '/invitations/');
        }
    );
});

/*
|--------------------------------------------------------------------------
| Role restrictions
|--------------------------------------------------------------------------
*/

test('the owner role cannot be granted through an invitation', function () {
    Notification::fake();

    expect(fn () => $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        OrganizationRole::Owner,
    ))->toThrow(ValidationException::class);

    expect(Invitation::count())->toBe(0);
    Notification::assertNothingSent();
});

test('manager and employee roles are accepted', function (OrganizationRole $role) {
    Notification::fake();

    $invitation = $this->action->handle(
        $this->organization,
        $this->inviter,
        'teammate@example.com',
        $role,
    );

    expect($invitation->role)->toBe($role);
})->with([
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

/*
|--------------------------------------------------------------------------
| Validation and duplicates
|--------------------------------------------------------------------------
*/

test('an invalid email address is rejected', function (string $email) {
    Notification::fake();

    expect(fn () => $this->action->handle($this->organization, $this->inviter, $email, OrganizationRole::Employee))
        ->toThrow(ValidationException::class);

    expect(Invitation::count())->toBe(0);
})->with([
    'empty' => '',
    'not an email' => 'nonsense',
]);

test('an existing member cannot be invited again', function () {
    Notification::fake();

    $member = User::factory()->create(['email' => 'member@example.com']);
    Membership::factory()->for($this->organization)->for($member)->employee()->create();

    expect(fn () => $this->action->handle(
        $this->organization,
        $this->inviter,
        'member@example.com',
        OrganizationRole::Employee,
    ))->toThrow(ValidationException::class);

    expect(Invitation::count())->toBe(0);
});

test('re-inviting the same address updates the existing row instead of duplicating', function () {
    Notification::fake();

    $first = $this->action->handle($this->organization, $this->inviter, 'teammate@example.com', OrganizationRole::Employee);
    $firstHash = $first->token_hash;

    $second = $this->action->handle($this->organization, $this->inviter, 'teammate@example.com', OrganizationRole::Manager);

    expect(Invitation::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->role)->toBe(OrganizationRole::Manager)
        ->and($second->token_hash)->not->toBe($firstHash);
});

test('re-inviting a revoked address makes the invitation pending again', function () {
    Notification::fake();

    $invitation = Invitation::factory()
        ->for($this->organization)
        ->forEmail('teammate@example.com')
        ->revoked()
        ->create();

    $reissued = $this->action->handle($this->organization, $this->inviter, 'teammate@example.com', OrganizationRole::Employee);

    expect($reissued->id)->toBe($invitation->id)
        ->and($reissued->status())->toBe(InvitationStatus::Pending)
        ->and(Invitation::count())->toBe(1);
});

test('the same address can be invited to two different organizations', function () {
    Notification::fake();

    $other = Organization::factory()->create();
    $otherInviter = memberWithRole($other, OrganizationRole::Owner);

    $this->action->handle($this->organization, $this->inviter, 'teammate@example.com', OrganizationRole::Employee);
    $this->action->handle($other, $otherInviter, 'teammate@example.com', OrganizationRole::Manager);

    expect(Invitation::count())->toBe(2)
        ->and($this->organization->invitations()->count())->toBe(1)
        ->and($other->invitations()->count())->toBe(1);
});

test('deleting an organization cascades its invitations', function () {
    Invitation::factory()->for($this->organization)->count(3)->create();
    $survivor = Invitation::factory()->create();

    expect(Invitation::count())->toBe(4);

    $this->organization->delete();

    expect(Invitation::count())->toBe(1);
    $this->assertModelExists($survivor);
});
