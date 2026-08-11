<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Determine whether the user can list a project's tasks.
     *
     * Task visibility follows organization membership, as decided in ADR-003.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $user->membershipFor($project->organization) !== null;
    }

    /**
     * Determine whether the user can view the task.
     */
    public function view(User $user, Task $task): bool
    {
        return $user->membershipFor($task->project->organization) !== null;
    }

    /**
     * Determine whether the user can create tasks in the project.
     */
    public function create(User $user, Project $project): bool
    {
        return $user->membershipFor($project->organization)?->role->canManageTasks() ?? false;
    }

    /**
     * Determine whether the user can edit the task's details.
     */
    public function update(User $user, Task $task): bool
    {
        return $user->membershipFor($task->project->organization)?->role->canManageTasks() ?? false;
    }

    /**
     * Determine whether the user can change who the task is assigned to.
     */
    public function assign(User $user, Task $task): bool
    {
        return $user->membershipFor($task->project->organization)?->role->canManageTasks() ?? false;
    }

    /**
     * Determine whether the user can change the task's status.
     *
     * Managers and owners may move any task. An employee may move only a task
     * that is assigned to them, so an assignee can complete their own work
     * without gaining any other task permission.
     */
    public function changeStatus(User $user, Task $task): bool
    {
        $membership = $user->membershipFor($task->project->organization);

        if ($membership === null) {
            return false;
        }

        return $membership->role->canManageTasks() || $task->isAssignedTo($user);
    }
}
