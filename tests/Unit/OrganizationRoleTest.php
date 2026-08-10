<?php

use App\Enums\OrganizationRole;
use Tests\TestCase;

uses(TestCase::class);

test('roles are backed by stable lowercase string values', function () {
    expect(OrganizationRole::Owner->value)->toBe('owner')
        ->and(OrganizationRole::Manager->value)->toBe('manager')
        ->and(OrganizationRole::Employee->value)->toBe('employee');
});

test('every role exposes a label', function (OrganizationRole $role) {
    expect($role->label())->not->toBeEmpty();
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('role capability matrix', function (
    OrganizationRole $role,
    bool $canManageOrganization,
    bool $canManageMembers
) {
    expect($role->canManageOrganization())->toBe($canManageOrganization)
        ->and($role->canManageMembers())->toBe($canManageMembers);
})->with([
    'owner manages the organization and its members' => [OrganizationRole::Owner, true, true],
    'manager manages members only' => [OrganizationRole::Manager, false, true],
    'employee manages nothing' => [OrganizationRole::Employee, false, false],
]);
