<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'status' => ProjectStatus::Planning,
        ];
    }

    /**
     * Indicate that the project is still being planned.
     */
    public function planning(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Planning,
        ]);
    }

    /**
     * Indicate that the project is actively being worked on.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Active,
        ]);
    }

    /**
     * Indicate that the project is paused.
     */
    public function onHold(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::OnHold,
        ]);
    }

    /**
     * Indicate that the project has been completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Completed,
        ]);
    }

    /**
     * Indicate that the project has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Archived,
        ]);
    }
}
