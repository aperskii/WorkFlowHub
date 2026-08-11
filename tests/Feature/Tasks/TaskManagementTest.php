<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the tasks section of a project as the given user.
 */
function tasksPanelAs(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.tasks', ['project' => $project]);
}

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

test('an owner or manager can create a task', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $actor = memberWithRole($organization, $role);

    tasksPanelAs($actor, $project)
        ->call('createTask')
        ->set('title', 'Write the launch plan')
        ->set('priority', TaskPriority::High->value)
        ->call('saveTask')
        ->assertHasNoErrors();

    $task = $project->tasks()->sole();

    expect($task->title)->toBe('Write the launch plan')
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->status)->toBe(TaskStatus::Todo);
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
]);

test('a task can be created with an assignee from the organization', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    tasksPanelAs($owner, $project)
        ->set('title', 'Write the launch plan')
        ->set('assigneeId', (string) $employee->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($project->tasks()->sole()->assigned_to_user_id)->toBe($employee->id);
});

test('a user outside the organization cannot be set as assignee', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $outsider = User::factory()->create();

    tasksPanelAs($owner, $project)
        ->set('title', 'Write the launch plan')
        ->set('assigneeId', (string) $outsider->id)
        ->call('saveTask')
        ->assertHasErrors('assigneeId');

    expect(Task::count())->toBe(0);
});

test('an employee cannot create a task', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    tasksPanelAs($employee, $project)->call('createTask')->assertForbidden();

    tasksPanelAs($employee, $project)
        ->set('title', 'Sneaky task')
        ->call('saveTask')
        ->assertForbidden();

    expect(Task::count())->toBe(0);
});

test('the title is required and length limited', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    tasksPanelAs($owner, $project)->set('title', '')->call('saveTask')->assertHasErrors(['title' => 'required']);
    tasksPanelAs($owner, $project)->set('title', str_repeat('a', 256))->call('saveTask')->assertHasErrors(['title' => 'max']);

    expect(Task::count())->toBe(0);
});

test('an unknown status or priority is rejected', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    tasksPanelAs($owner, $project)
        ->set('title', 'Valid')
        ->set('status', 'archived')
        ->call('saveTask')
        ->assertHasErrors('status');

    expect(Task::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

test('an owner can edit every task field', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->create(['title' => 'Old title']);

    tasksPanelAs($owner, $project)
        ->call('editTask', $task->id)
        ->assertSet('title', 'Old title')
        ->set('title', 'New title')
        ->set('description', 'Now with detail.')
        ->set('priority', TaskPriority::Urgent->value)
        ->set('status', TaskStatus::InReview->value)
        ->set('dueDate', '2026-09-01')
        ->set('assigneeId', (string) $employee->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->title)->toBe('New title')
        ->and($task->description)->toBe('Now with detail.')
        ->and($task->priority)->toBe(TaskPriority::Urgent)
        ->and($task->status)->toBe(TaskStatus::InReview)
        ->and($task->due_date->toDateString())->toBe('2026-09-01')
        ->and($task->assigned_to_user_id)->toBe($employee->id);
});

test('an employee cannot edit a task, even one assigned to them', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($employee)->create(['title' => 'Old title']);

    tasksPanelAs($employee, $project)->call('editTask', $task->id)->assertForbidden();

    expect($task->refresh()->title)->toBe('Old title');
});

test('the edit action re-authorizes and is not trusted from mount alone', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Owner);

    $task = Task::factory()->for($project)->create(['title' => 'Old title']);

    $component = tasksPanelAs($owner, $project)->call('editTask', $task->id)->set('title', 'New title');

    $owner->membershipFor($organization)->update(['role' => OrganizationRole::Employee]);

    $component->call('saveTask')->assertForbidden();

    expect($task->refresh()->title)->toBe('Old title');
});

/*
|--------------------------------------------------------------------------
| Status changes
|--------------------------------------------------------------------------
*/

test('an owner or manager can move any task to any status', function (TaskStatus $status) {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);

    $task = Task::factory()->for($project)->todo()->create();

    tasksPanelAs($manager, $project)
        ->call('changeStatus', $task->id, $status->value)
        ->assertHasNoErrors();

    expect($task->refresh()->status)->toBe($status);
})->with([
    'todo' => TaskStatus::Todo,
    'in progress' => TaskStatus::InProgress,
    'in review' => TaskStatus::InReview,
    'done' => TaskStatus::Done,
]);

test('an employee can move their own task to done', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($employee)->todo()->create();

    tasksPanelAs($employee, $project)
        ->call('changeStatus', $task->id, TaskStatus::Done->value)
        ->assertHasNoErrors();

    expect($task->refresh()->status)->toBe(TaskStatus::Done);
});

test('an employee cannot move a colleague\'s task', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($colleague)->todo()->create();

    tasksPanelAs($employee, $project)
        ->call('changeStatus', $task->id, TaskStatus::Done->value)
        ->assertForbidden();

    expect($task->refresh()->status)->toBe(TaskStatus::Todo);
});

test('an employee cannot move an unassigned task', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->todo()->create(['assigned_to_user_id' => null]);

    tasksPanelAs($employee, $project)
        ->call('changeStatus', $task->id, TaskStatus::Done->value)
        ->assertForbidden();

    expect($task->refresh()->status)->toBe(TaskStatus::Todo);
});

test('the status change action re-authorizes after the assignee changes', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);

    $task = Task::factory()->for($project)->assignedTo($employee)->todo()->create();

    $component = tasksPanelAs($employee, $project);

    $task->update(['assigned_to_user_id' => $colleague->id]);

    $component->call('changeStatus', $task->id, TaskStatus::Done->value)->assertForbidden();

    expect($task->refresh()->status)->toBe(TaskStatus::Todo);
});

test('an unknown status value is rejected', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $task = Task::factory()->for($project)->todo()->create();

    tasksPanelAs($owner, $project)
        ->call('changeStatus', $task->id, 'archived')
        ->assertHasErrors('tasks');

    expect($task->refresh()->status)->toBe(TaskStatus::Todo);
});

/*
|--------------------------------------------------------------------------
| Listing, filtering, and visibility
|--------------------------------------------------------------------------
*/

test('the panel lists only this project\'s tasks', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $otherProject = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Task::factory()->for($project)->create(['title' => 'Ours']);
    Task::factory()->for($otherProject)->create(['title' => 'Theirs']);

    tasksPanelAs($owner, $project)->assertSee('Ours')->assertDontSee('Theirs');
});

test('the task list is scoped through the project relationship', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Task::factory()->for($project)->create();
    Task::factory()->count(3)->create();

    $tasks = tasksPanelAs($owner, $project)->instance()->tasks();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->project_id)->toBe($project->id);
});

test('the status filter narrows the list', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Task::factory()->for($project)->todo()->create(['title' => 'Not started']);
    Task::factory()->for($project)->done()->create(['title' => 'Finished']);

    tasksPanelAs($owner, $project)
        ->assertSee('Not started')
        ->assertSee('Finished')
        ->set('statusFilter', TaskStatus::Done->value)
        ->assertSee('Finished')
        ->assertDontSee('Not started')
        ->set('statusFilter', '')
        ->assertSee('Not started');
});

test('an unknown status filter is ignored rather than trusted', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Task::factory()->for($project)->todo()->create(['title' => 'Not started']);

    tasksPanelAs($owner, $project)
        ->set('statusFilter', 'nonsense')
        ->assertHasNoErrors()
        ->assertSee('Not started');
});

test('the empty state distinguishes no tasks from no matches', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    tasksPanelAs($owner, $project)->assertSee('No tasks yet.');

    Task::factory()->for($project)->todo()->create();

    tasksPanelAs($owner, $project)
        ->set('statusFilter', TaskStatus::Done->value)
        ->assertSee('No tasks match this filter.');
});

test('management controls are only rendered for owners and managers', function (OrganizationRole $role, bool $visible) {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $actor = memberWithRole($organization, $role);

    $task = Task::factory()->for($project)->create();

    $component = tasksPanelAs($actor, $project);

    $visible
        ? $component->assertSee('data-test="new-task-button"', escape: false)
        : $component->assertDontSee('data-test="new-task-button"', escape: false);

    $visible
        ? $component->assertSee('data-test="edit-task-'.$task->id.'"', escape: false)
        : $component->assertDontSee('data-test="edit-task-'.$task->id.'"', escape: false);
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'manager' => [OrganizationRole::Manager, true],
    'employee' => [OrganizationRole::Employee, false],
]);

test('an employee only sees a status control on their own task', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);

    $own = Task::factory()->for($project)->assignedTo($employee)->create();
    $theirs = Task::factory()->for($project)->assignedTo($colleague)->create();

    tasksPanelAs($employee, $project)
        ->assertSee('data-test="change-task-status-'.$own->id.'"', escape: false)
        ->assertDontSee('data-test="change-task-status-'.$theirs->id.'"', escape: false);
});

test('no delete control is ever rendered', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Task::factory()->for($project)->create();

    tasksPanelAs($owner, $project)
        ->assertDontSee('delete-task')
        ->assertDontSeeText('Delete task');
});

/*
|--------------------------------------------------------------------------
| Tenant isolation and tampering
|--------------------------------------------------------------------------
*/

test('a non-member cannot mount the tasks panel', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    tasksPanelAs(User::factory()->create(), $project)->assertForbidden();
});

test('a member of another organization cannot mount the tasks panel', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    tasksPanelAs($memberOfA, Project::factory()->for($organizationB)->create())->assertForbidden();
});

test('a task id from another project cannot be edited or moved', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $otherProject = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $foreignTask = Task::factory()->for($otherProject)->todo()->create(['title' => 'Untouched']);

    $component = tasksPanelAs($owner, $project);

    expect(fn () => $component->call('editTask', $foreignTask->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('changeStatus', $foreignTask->id, TaskStatus::Done->value))
        ->toThrow(ModelNotFoundException::class);

    $foreignTask->refresh();

    expect($foreignTask->title)->toBe('Untouched')
        ->and($foreignTask->status)->toBe(TaskStatus::Todo);
});

test('a task id from another organization cannot be reached', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $projectInA = Project::factory()->for($organizationA)->create();
    $taskInB = Task::factory()->for(Project::factory()->for($organizationB))->todo()->create();

    $component = tasksPanelAs($ownerOfA, $projectInA);

    expect(fn () => $component->call('changeStatus', $taskInB->id, TaskStatus::Done->value))
        ->toThrow(ModelNotFoundException::class);

    expect($taskInB->refresh()->status)->toBe(TaskStatus::Todo);
});

test('the locked project property cannot be swapped', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $component = tasksPanelAs($ownerOfA, Project::factory()->for($organizationA)->create());

    expect(fn () => $component->set('project', Project::factory()->for($organizationB)->create()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the locked editing task id cannot be set from the browser', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $task = Task::factory()->for($project)->create();

    $component = tasksPanelAs($owner, $project);

    expect(fn () => $component->set('editingTaskId', $task->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a task is always created inside the mounted project', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $otherProject = Project::factory()->for($organization)->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    tasksPanelAs($owner, $project)
        ->set('title', 'Belongs here')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($project->tasks()->count())->toBe(1)
        ->and($otherProject->tasks()->count())->toBe(0);
});
