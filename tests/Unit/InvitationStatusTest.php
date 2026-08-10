<?php

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use Tests\TestCase;

uses(TestCase::class);

test('statuses are backed by stable lowercase string values', function () {
    expect(InvitationStatus::Pending->value)->toBe('pending')
        ->and(InvitationStatus::Accepted->value)->toBe('accepted')
        ->and(InvitationStatus::Revoked->value)->toBe('revoked')
        ->and(InvitationStatus::Expired->value)->toBe('expired');
});

test('every status exposes a label and a colour', function (InvitationStatus $status) {
    expect($status->label())->not->toBeEmpty()
        ->and($status->color())->not->toBeEmpty();
})->with([
    'pending' => InvitationStatus::Pending,
    'accepted' => InvitationStatus::Accepted,
    'revoked' => InvitationStatus::Revoked,
    'expired' => InvitationStatus::Expired,
]);

test('only a pending invitation is acceptable', function (InvitationStatus $status, bool $acceptable) {
    expect($status->isAcceptable())->toBe($acceptable);
})->with([
    'pending' => [InvitationStatus::Pending, true],
    'accepted' => [InvitationStatus::Accepted, false],
    'revoked' => [InvitationStatus::Revoked, false],
    'expired' => [InvitationStatus::Expired, false],
]);

test('only manager and employee may be granted through an invitation', function () {
    expect(OrganizationRole::invitable())
        ->toEqualCanonicalizing([OrganizationRole::Manager, OrganizationRole::Employee]);
});

test('the owner role is never invitable', function (OrganizationRole $role, bool $invitable) {
    expect($role->isInvitable())->toBe($invitable);
})->with([
    'owner is not invitable' => [OrganizationRole::Owner, false],
    'manager is invitable' => [OrganizationRole::Manager, true],
    'employee is invitable' => [OrganizationRole::Employee, true],
]);

test('the invitation expiry window is configurable', function () {
    expect(config('auth.invitation_expires_after_days'))->toBe(7);

    config(['auth.invitation_expires_after_days' => 14]);

    expect(config('auth.invitation_expires_after_days'))->toBe(14);
});
