<?php

use App\Actions\Tasks\CreateTask;
use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->action = new CreateTask;
    $this->organization = Organization::factory()->create();
    $this->project = Project::factory()->for($this->organization)->create();
});

test('a task is created inside the given project', function () {
    $task = $this->action->handle($this->project, 'Write the launch plan');

    $this->assertModelExists($task);

    expect($task->project_id)->toBe($this->project->id)
        ->and($task->title)->toBe('Write the launch plan')
        ->and($this->project->tasks()->count())->toBe(1);
});

test('a new task defaults to todo, medium priority, and no assignee', function () {
    $task = $this->action->handle($this->project, 'Write the launch plan');

    expect($task->status)->toBe(TaskStatus::Todo)
        ->and($task->priority)->toBe(TaskPriority::Medium)
        ->and($task->assigned_to_user_id)->toBeNull()
        ->and($task->due_date)->toBeNull()
        ->and($task->description)->toBeNull();
});

test('a task can be created with every documented field', function () {
    $member = memberWithRole($this->organization, OrganizationRole::Employee);

    $task = $this->action->handle(
        $this->project,
        'Write the launch plan',
        'Cover positioning and pricing.',
        TaskStatus::InProgress,
        TaskPriority::Urgent,
        '2026-09-01',
        $member,
    );

    expect($task->description)->toBe('Cover positioning and pricing.')
        ->and($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->priority)->toBe(TaskPriority::Urgent)
        ->and($task->due_date->toDateString())->toBe('2026-09-01')
        ->and($task->assigned_to_user_id)->toBe($member->id);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

test('the title is required', function () {
    expect(fn () => $this->action->handle($this->project, ''))
        ->toThrow(ValidationException::class);

    expect(Task::count())->toBe(0);
});

test('the title may not exceed 255 characters', function () {
    expect(fn () => $this->action->handle($this->project, str_repeat('a', 256)))
        ->toThrow(ValidationException::class);

    expect(Task::count())->toBe(0);
});

test('the description may not exceed 2000 characters', function () {
    expect(fn () => $this->action->handle($this->project, 'Valid', str_repeat('a', 2001)))
        ->toThrow(ValidationException::class);

    expect(Task::count())->toBe(0);
});

test('the due date must be a real date', function () {
    expect(fn () => $this->action->handle(
        $this->project,
        'Valid',
        null,
        TaskStatus::Todo,
        TaskPriority::Medium,
        'not-a-date',
    ))->toThrow(ValidationException::class);

    expect(Task::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Assignee membership
|--------------------------------------------------------------------------
*/

test('a member of the organization can be assigned', function (OrganizationRole $role) {
    $member = memberWithRole($this->organization, $role);

    $task = $this->action->handle(
        $this->project,
        'Valid',
        null,
        TaskStatus::Todo,
        TaskPriority::Medium,
        null,
        $member,
    );

    expect($task->assigned_to_user_id)->toBe($member->id);
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('a user who is not a member cannot be assigned', function () {
    $outsider = User::factory()->create();

    expect(fn () => $this->action->handle(
        $this->project,
        'Valid',
        null,
        TaskStatus::Todo,
        TaskPriority::Medium,
        null,
        $outsider,
    ))->toThrow(ValidationException::class);

    expect(Task::count())->toBe(0);
});

test('a member of another organization cannot be assigned', function () {
    $other = Organization::factory()->create();
    $memberOfOther = memberWithRole($other, OrganizationRole::Owner);

    expect(fn () => $this->action->handle(
        $this->project,
        'Valid',
        null,
        TaskStatus::Todo,
        TaskPriority::Medium,
        null,
        $memberOfOther,
    ))->toThrow(ValidationException::class);

    expect(Task::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Schema, casts, and cascades
|--------------------------------------------------------------------------
*/

test('status and priority are cast to their enums', function () {
    $task = Task::factory()->for($this->project)->inReview()->priority(TaskPriority::High)->create();

    expect($task->refresh()->status)->toBe(TaskStatus::InReview)
        ->and($task->priority)->toBe(TaskPriority::High);
});

test('the due date is cast to a date', function () {
    $task = Task::factory()->for($this->project)->dueOn('2026-09-01')->create();

    expect($task->refresh()->due_date->toDateString())->toBe('2026-09-01');
});

test('deleting a project cascades its tasks', function () {
    Task::factory()->for($this->project)->count(3)->create();
    $survivor = Task::factory()->create();

    expect(Task::count())->toBe(4);

    $this->project->delete();

    expect(Task::count())->toBe(1);
    $this->assertModelExists($survivor);
});

test('deleting an organization cascades its projects and therefore its tasks', function () {
    Task::factory()->for($this->project)->count(2)->create();

    $other = Organization::factory()->create();
    $otherTask = Task::factory()->for(Project::factory()->for($other))->create();

    $this->organization->delete();

    expect(Project::count())->toBe(1)
        ->and(Task::count())->toBe(1);
    $this->assertModelExists($otherTask);
});

test('deleting a user keeps the task and clears the assignment', function () {
    $member = memberWithRole($this->organization, OrganizationRole::Employee);
    $task = Task::factory()->for($this->project)->assignedTo($member)->create();

    $member->delete();

    $this->assertModelExists($task);
    expect($task->refresh()->assigned_to_user_id)->toBeNull();
});

test('the open and completed scopes split tasks correctly', function () {
    Task::factory()->for($this->project)->todo()->create();
    Task::factory()->for($this->project)->inProgress()->create();
    Task::factory()->for($this->project)->inReview()->create();
    Task::factory()->for($this->project)->done()->count(2)->create();

    expect($this->project->tasks()->open()->count())->toBe(3)
        ->and($this->project->tasks()->completed()->count())->toBe(2)
        ->and($this->project->tasks()->count())->toBe(5);
});

test('a task belongs to its project and reaches the organization through it', function () {
    $task = Task::factory()->for($this->project)->create();

    expect($task->project->id)->toBe($this->project->id)
        ->and($task->project->organization->id)->toBe($this->organization->id);
});

test('tasks are never shared between projects', function () {
    $otherProject = Project::factory()->for($this->organization)->create();

    Task::factory()->for($this->project)->count(2)->create();
    Task::factory()->for($otherProject)->count(3)->create();

    expect($this->project->tasks()->count())->toBe(2)
        ->and($otherProject->tasks()->count())->toBe(3);
});
