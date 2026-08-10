<?php

use App\Enums\OrganizationRole;
use App\Enums\ProjectStatus;
use Tests\TestCase;

uses(TestCase::class);

test('statuses are backed by stable lowercase string values', function () {
    expect(ProjectStatus::Planning->value)->toBe('planning')
        ->and(ProjectStatus::Active->value)->toBe('active')
        ->and(ProjectStatus::OnHold->value)->toBe('on_hold')
        ->and(ProjectStatus::Completed->value)->toBe('completed')
        ->and(ProjectStatus::Archived->value)->toBe('archived');
});

test('every status exposes a label and a colour', function (ProjectStatus $status) {
    expect($status->label())->not->toBeEmpty()
        ->and($status->color())->not->toBeEmpty();
})->with([
    'planning' => ProjectStatus::Planning,
    'active' => ProjectStatus::Active,
    'on hold' => ProjectStatus::OnHold,
    'completed' => ProjectStatus::Completed,
    'archived' => ProjectStatus::Archived,
]);

test('only the archived status reports as archived', function (ProjectStatus $status, bool $isArchived) {
    expect($status->isArchived())->toBe($isArchived);
})->with([
    'planning' => [ProjectStatus::Planning, false],
    'active' => [ProjectStatus::Active, false],
    'on hold' => [ProjectStatus::OnHold, false],
    'completed' => [ProjectStatus::Completed, false],
    'archived' => [ProjectStatus::Archived, true],
]);

test('there are exactly five statuses in the mvp', function () {
    expect(ProjectStatus::cases())->toHaveCount(5);
});

test('unknown status values are rejected', function () {
    expect(ProjectStatus::tryFrom('deleted'))->toBeNull()
        ->and(ProjectStatus::tryFrom(''))->toBeNull();
});

test('organization role project capability matrix', function (OrganizationRole $role, bool $canManageProjects) {
    expect($role->canManageProjects())->toBe($canManageProjects);
})->with([
    'owner manages projects' => [OrganizationRole::Owner, true],
    'manager manages projects' => [OrganizationRole::Manager, true],
    'employee does not manage projects' => [OrganizationRole::Employee, false],
]);
