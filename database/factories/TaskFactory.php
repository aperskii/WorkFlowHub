<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->unique()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_date' => null,
            'assigned_to_user_id' => null,
        ];
    }

    /**
     * Indicate the task's status.
     */
    public function status(TaskStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * Indicate that the task is still to be started.
     */
    public function todo(): static
    {
        return $this->status(TaskStatus::Todo);
    }

    /**
     * Indicate that the task is being worked on.
     */
    public function inProgress(): static
    {
        return $this->status(TaskStatus::InProgress);
    }

    /**
     * Indicate that the task is awaiting review.
     */
    public function inReview(): static
    {
        return $this->status(TaskStatus::InReview);
    }

    /**
     * Indicate that the task is finished.
     */
    public function done(): static
    {
        return $this->status(TaskStatus::Done);
    }

    /**
     * Indicate the task's priority.
     */
    public function priority(TaskPriority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    /**
     * Assign the task to the given user.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_to_user_id' => $user->id,
        ]);
    }

    /**
     * Give the task a due date.
     */
    public function dueOn(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_date' => $date,
        ]);
    }
}
