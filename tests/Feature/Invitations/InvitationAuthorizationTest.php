<?php

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Policies\InvitationPolicy;
use Illuminate\Support\Facades\Gate;

test('the invitation ability matrix is enforced for every organization role', function (
    OrganizationRole $role,
    bool $canManage
) {
    $organization = Organization::factory()->create();
    $actor = memberWithRole($organization, $role);
    $invitation = Invitation::factory()->for($organization)->create();

    expect($actor->can('viewAny', [Invitation::class, $organization]))->toBe($canManage)
        ->and($actor->can('create', [Invitation::class, $organization]))->toBe($canManage)
        ->and($actor->can('resend', $invitation))->toBe($canManage)
        ->and($actor->can('revoke', $invitation))->toBe($canManage);
})->with([
    'owner manages invitations' => [OrganizationRole::Owner, true],
    'manager manages invitations' => [OrganizationRole::Manager, true],
    'employee cannot manage invitations' => [OrganizationRole::Employee, false],
]);

test('a non-member is denied every invitation ability', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();
    $invitation = Invitation::factory()->for($organization)->create();

    expect($outsider->can('viewAny', [Invitation::class, $organization]))->toBeFalse()
        ->and($outsider->can('create', [Invitation::class, $organization]))->toBeFalse()
        ->and($outsider->can('resend', $invitation))->toBeFalse()
        ->and($outsider->can('revoke', $invitation))->toBeFalse();
});

test('a guest is denied every invitation ability', function () {
    $organization = Organization::factory()->create();
    $invitation = Invitation::factory()->for($organization)->create();

    expect(Gate::forUser(null)->allows('viewAny', [Invitation::class, $organization]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', [Invitation::class, $organization]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('resend', $invitation))->toBeFalse()
        ->and(Gate::forUser(null)->allows('revoke', $invitation))->toBeFalse();
});

test('an owner of one organization cannot manage another organization\'s invitations', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $invitationInB = Invitation::factory()->for($organizationB)->create();

    expect($ownerOfA->can('viewAny', [Invitation::class, $organizationB]))->toBeFalse()
        ->and($ownerOfA->can('create', [Invitation::class, $organizationB]))->toBeFalse()
        ->and($ownerOfA->can('resend', $invitationInB))->toBeFalse()
        ->and($ownerOfA->can('revoke', $invitationInB))->toBeFalse();
});

test('invitation management reuses the member management capability', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $actor = memberWithRole($organization, $role);

    expect($actor->can('create', [Invitation::class, $organization]))
        ->toBe($role->canManageMembers());
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('the invitation policy exposes no ability to grant ownership', function () {
    expect(get_class_methods(InvitationPolicy::class))
        ->toEqualCanonicalizing(['viewAny', 'create', 'resend', 'revoke']);
});
