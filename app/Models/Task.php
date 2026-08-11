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
}
