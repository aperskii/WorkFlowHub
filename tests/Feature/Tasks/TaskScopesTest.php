<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->project = Project::factory()->for($this->organization)->create();
});

/*
|--------------------------------------------------------------------------
| Overdue
|--------------------------------------------------------------------------
*/

test('a task due before today is overdue', function () {
    $task = Task::factory()->for($this->project)->todo()->dueOn(today()->subDay()->toDateString())->create();

    expect($this->project->tasks()->overdue()->count())->toBe(1)
        ->and($task->isOverdue())->toBeTrue();
});

test('a task due today is not overdue', function () {
    $task = Task::factory()->for($this->project)->todo()->dueOn(today()->toDateString())->create();

    expect($this->project->tasks()->overdue()->count())->toBe(0)
        ->and($task->isOverdue())->toBeFalse();
});

test('a task due in the future is not overdue', function () {
    $task = Task::factory()->for($this->project)->todo()->dueOn(today()->addWeek()->toDateString())->create();

    expect($this->project->tasks()->overdue()->count())->toBe(0)
        ->and($task->isOverdue())->toBeFalse();
});

test('a task without a due date is never overdue', function () {
    $task = Task::factory()->for($this->project)->todo()->create(['due_date' => null]);

    expect($this->project->tasks()->overdue()->count())->toBe(0)
        ->and($task->isOverdue())->toBeFalse();
});

test('a completed task is never overdue however late it is', function () {
    $task = Task::factory()->for($this->project)->done()->dueOn(today()->subMonth()->toDateString())->create();

    expect($this->project->tasks()->overdue()->count())->toBe(0)
        ->and($task->isOverdue())->toBeFalse();
});

test('every unfinished status can be overdue', function (TaskStatus $status, bool $canBeOverdue) {
    Task::factory()->for($this->project)->status($status)->dueOn(today()->subDays(3)->toDateString())->create();

    expect($this->project->tasks()->overdue()->count())->toBe($canBeOverdue ? 1 : 0);
})->with([
    'todo' => [TaskStatus::Todo, true],
    'in progress' => [TaskStatus::InProgress, true],
    'in review' => [TaskStatus::InReview, true],
    'done' => [TaskStatus::Done, false],
]);

test('the overdue scope never reaches another project', function () {
    $otherProject = Project::factory()->for($this->organization)->create();

    Task::factory()->for($this->project)->todo()->dueOn(today()->subDay()->toDateString())->create();
    Task::factory()->for($otherProject)->todo()->dueOn(today()->subDay()->toDateString())->count(3)->create();

    expect($this->project->tasks()->overdue()->count())->toBe(1)
        ->and($otherProject->tasks()->overdue()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Days overdue
|--------------------------------------------------------------------------
*/

test('days overdue counts whole days past the due date', function (int $daysLate) {
    $task = Task::factory()
        ->for($this->project)
        ->todo()
        ->dueOn(today()->subDays($daysLate)->toDateString())
        ->create();

    expect($task->daysOverdue())->toBe($daysLate);
})->with([
    'one day' => 1,
    'three days' => 3,
    'two weeks' => 14,
]);

test('days overdue is zero for work that is not overdue', function () {
    $onTime = Task::factory()->for($this->project)->todo()->dueOn(today()->addDay()->toDateString())->create();
    $undated = Task::factory()->for($this->project)->todo()->create(['due_date' => null]);
    $finished = Task::factory()->for($this->project)->done()->dueOn(today()->subYear()->toDateString())->create();

    expect($onTime->daysOverdue())->toBe(0)
        ->and($undated->daysOverdue())->toBe(0)
        ->and($finished->daysOverdue())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Unassigned
|--------------------------------------------------------------------------
*/

test('the unassigned scope finds tasks nobody owns', function () {
    $member = memberWithRole($this->organization, OrganizationRole::Employee);

    Task::factory()->for($this->project)->count(2)->create(['assigned_to_user_id' => null]);
    Task::factory()->for($this->project)->assignedTo($member)->count(3)->create();

    expect($this->project->tasks()->unassigned()->count())->toBe(2);
});

test('the unassigned scope composes with the open scope', function () {
    Task::factory()->for($this->project)->todo()->create(['assigned_to_user_id' => null]);
    Task::factory()->for($this->project)->done()->create(['assigned_to_user_id' => null]);

    expect($this->project->tasks()->unassigned()->count())->toBe(2)
        ->and($this->project->tasks()->open()->unassigned()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Assigned to
|--------------------------------------------------------------------------
*/

test('the assignedTo scope finds only that user\'s tasks', function () {
    $member = memberWithRole($this->organization, OrganizationRole::Employee);
    $colleague = memberWithRole($this->organization, OrganizationRole::Employee);

    Task::factory()->for($this->project)->assignedTo($member)->count(2)->create();
    Task::factory()->for($this->project)->assignedTo($colleague)->count(4)->create();
    Task::factory()->for($this->project)->create(['assigned_to_user_id' => null]);

    expect($this->project->tasks()->assignedTo($member)->count())->toBe(2)
        ->and($this->project->tasks()->assignedTo($colleague)->count())->toBe(4);
});

test('the assignedTo scope returns nothing for a user with no work', function () {
    $stranger = User::factory()->create();

    Task::factory()->for($this->project)->count(3)->create(['assigned_to_user_id' => null]);

    expect($this->project->tasks()->assignedTo($stranger)->count())->toBe(0);
});

test('the scopes combine to answer my overdue work', function () {
    $member = memberWithRole($this->organization, OrganizationRole::Employee);
    $colleague = memberWithRole($this->organization, OrganizationRole::Employee);

    Task::factory()->for($this->project)->assignedTo($member)->todo()->dueOn(today()->subDay()->toDateString())->create();
    Task::factory()->for($this->project)->assignedTo($member)->todo()->dueOn(today()->addDay()->toDateString())->create();
    Task::factory()->for($this->project)->assignedTo($colleague)->todo()->dueOn(today()->subDay()->toDateString())->create();

    expect($this->project->tasks()->overdue()->assignedTo($member)->count())->toBe(1);
});
