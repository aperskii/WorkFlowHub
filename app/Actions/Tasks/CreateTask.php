<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateTask
{
    /**
     * Create a task inside the given project.
     *
     * The assignee is validated against the project's organization membership,
     * so a task can never be handed to somebody outside the tenant.
     */
    public function handle(
        Project $project,
        string $title,
        ?string $description = null,
        TaskStatus $status = TaskStatus::Todo,
        TaskPriority $priority = TaskPriority::Medium,
        ?string $dueDate = null,
        ?User $assignee = null,
    ): Task {
        Validator::make([
            'title' => $title,
            'description' => $description,
            'due_date' => $dueDate,
        ], [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date'],
        ])->validate();

        $this->ensureAssigneeBelongsToOrganization($project, $assignee);

        return $project->tasks()->create([
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'due_date' => $dueDate,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }

    /**
     * Reject an assignee who is not a member of the project's organization.
     */
    private function ensureAssigneeBelongsToOrganization(Project $project, ?User $assignee): void
    {
        if ($assignee === null) {
            return;
        }

        if (! $project->organization->hasMember($assignee)) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => __('That person is not a member of this organization.'),
            ]);
        }
    }
}
