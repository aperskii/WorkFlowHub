<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

test('the statuses are exactly the four documented in the mvp', function () {
    expect(array_map(fn (TaskStatus $s) => $s->value, TaskStatus::cases()))
        ->toBe(['todo', 'in_progress', 'in_review', 'done']);
});

test('there is no archived or cancelled status', function () {
    expect(TaskStatus::tryFrom('archived'))->toBeNull()
        ->and(TaskStatus::tryFrom('cancelled'))->toBeNull()
        ->and(TaskStatus::tryFrom('deleted'))->toBeNull();
});

test('every status exposes a label and a colour', function (TaskStatus $status) {
    expect($status->label())->not->toBeEmpty()
        ->and($status->color())->not->toBeEmpty();
})->with([
    'todo' => TaskStatus::Todo,
    'in progress' => TaskStatus::InProgress,
    'in review' => TaskStatus::InReview,
    'done' => TaskStatus::Done,
]);

test('only done is closed work', function (TaskStatus $status, bool $isOpen) {
    expect($status->isOpen())->toBe($isOpen);
})->with([
    'todo is open' => [TaskStatus::Todo, true],
    'in progress is open' => [TaskStatus::InProgress, true],
    'in review is open' => [TaskStatus::InReview, true],
    'done is closed' => [TaskStatus::Done, false],
]);

/*
|--------------------------------------------------------------------------
| Priority
|--------------------------------------------------------------------------
*/

test('the priorities are exactly the four documented in the mvp', function () {
    expect(array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases()))
        ->toBe(['low', 'medium', 'high', 'urgent']);
});

test('every priority exposes a label and a colour', function (TaskPriority $priority) {
    expect($priority->label())->not->toBeEmpty()
        ->and($priority->color())->not->toBeEmpty();
})->with([
    'low' => TaskPriority::Low,
    'medium' => TaskPriority::Medium,
    'high' => TaskPriority::High,
    'urgent' => TaskPriority::Urgent,
]);

test('an unknown priority value is rejected', function () {
    expect(TaskPriority::tryFrom('critical'))->toBeNull()
        ->and(TaskPriority::tryFrom(''))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Capability
|--------------------------------------------------------------------------
*/

test('the task management capability matrix', function (OrganizationRole $role, bool $canManageTasks) {
    expect($role->canManageTasks())->toBe($canManageTasks);
})->with([
    'owner manages tasks' => [OrganizationRole::Owner, true],
    'manager manages tasks' => [OrganizationRole::Manager, true],
    'employee does not manage tasks' => [OrganizationRole::Employee, false],
]);

test('task management is a capability of its own, separate from projects', function () {
    expect(method_exists(OrganizationRole::class, 'canManageTasks'))->toBeTrue()
        ->and(method_exists(OrganizationRole::class, 'canManageProjects'))->toBeTrue();
});
