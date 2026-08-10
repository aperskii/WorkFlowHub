<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::Employee,
        ];
    }

    /**
     * Indicate that the membership grants ownership of the organization.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => OrganizationRole::Owner,
        ]);
    }

    /**
     * Indicate that the membership grants the manager role.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => OrganizationRole::Manager,
        ]);
    }

    /**
     * Indicate that the membership grants the employee role.
     */
    public function employee(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => OrganizationRole::Employee,
        ]);
    }
}
