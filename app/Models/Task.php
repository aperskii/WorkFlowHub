<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Policies\TaskPolicy;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property Carbon|null $due_date
 * @property int|null $assigned_to_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $assignee
 */
#[Fillable([
    'title',
    'description',
    'status',
    'priority',
    'due_date',
    'assigned_to_user_id',
])]
#[UsePolicy(TaskPolicy::class)]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
        ];
    }

    /**
     * Get the project this task belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the member this task is assigned to, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Determine whether the given user is the assignee of this task.
     */
    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_to_user_id !== null
            && $this->assigned_to_user_id === $user->id;
    }

    /**
     * Determine whether this task's due date has passed while it is still open.
     *
     * Overdue is always derived from the due date and the status; it is never
     * stored, so it cannot drift away from the task's real state.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->status !== TaskStatus::Done
            && $this->due_date->isBefore(today());
    }

    /**
     * Get the number of whole days this task is past its due date.
     *
     * Returns zero when the task is not overdue, so callers never have to guard
     * the sign of the result.
     */
    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->due_date->startOfDay()->diffInDays(today());
    }

    /**
     * Scope the query to tasks that are not yet done.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', '!=', TaskStatus::Done);
    }

    /**
     * Scope the query to tasks that are done.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', TaskStatus::Done);
    }

    /**
     * Scope the query to open tasks whose due date has already passed.
     *
     * A finished task is never overdue, so this deliberately implies `open()`
     * rather than composing with it.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->where('status', '!=', TaskStatus::Done);
    }

    /**
     * Scope the query to tasks nobody is responsible for.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function unassigned(Builder $query): void
    {
        $query->whereNull('assigned_to_user_id');
    }

    /**
     * Scope the query to tasks assigned to the given user.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function assignedTo(Builder $query, User $user): void
    {
        $query->where('assigned_to_user_id', $user->id);
    }
}
