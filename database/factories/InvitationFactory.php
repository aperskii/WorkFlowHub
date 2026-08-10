<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * The raw token generated for the most recent factory state, so tests can
     * build invitation URLs without ever reading one back from the database.
     */
    public ?string $rawToken = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $this->rawToken = Invitation::generateToken();

        return [
            'organization_id' => Organization::factory(),
            'email' => Invitation::normalizeEmail(fake()->unique()->safeEmail()),
            'role' => OrganizationRole::Employee,
            'token_hash' => Invitation::hashToken($this->rawToken),
            'expires_at' => now()->addDays(config('auth.invitation_expires_after_days')),
            'accepted_at' => null,
            'revoked_at' => null,
            'invited_by_user_id' => User::factory(),
        ];
    }

    /**
     * Use a known raw token so a test can visit the invitation URL.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_hash' => Invitation::hashToken($token),
        ]);
    }

    /**
     * Indicate that the invitation grants the manager role.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => OrganizationRole::Manager,
        ]);
    }

    /**
     * Indicate that the invitation grants the employee role.
     */
    public function employee(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => OrganizationRole::Employee,
        ]);
    }

    /**
     * Indicate that the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the invitation has been revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }

    /**
     * Indicate that the invitation has already been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
        ]);
    }

    /**
     * Indicate that the invitation was sent to a specific address.
     */
    public function forEmail(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => Invitation::normalizeEmail($email),
        ]);
    }
}
