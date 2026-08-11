<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, Organization> $organizations
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Guard the organization ownership invariant: deleting a user cascades their
     * memberships, so a sole owner must never be deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->isSoleOwnerOfAnyOrganization()) {
                throw new RuntimeException(
                    "Cannot delete user [{$user->id}] because they are the sole owner of at least one organization."
                );
            }
        });
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Get the memberships joining this user to organizations.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the organizations this user belongs to.
     *
     * Roles are deliberately not exposed through this pivot; read them from the
     * Membership model so they are cast to OrganizationRole.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')->withTimestamps();
    }

    /**
     * Get the tasks currently assigned to this user.
     *
     * @return HasMany<Task, $this>
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to_user_id');
    }

    /**
     * Get this user's membership of the given organization, if any.
     */
    public function membershipFor(Organization $organization): ?Membership
    {
        return $this->memberships()->whereBelongsTo($organization)->first();
    }

    /**
     * Determine whether this user is the only remaining owner of any organization.
     */
    public function isSoleOwnerOfAnyOrganization(): bool
    {
        return Organization::query()
            ->whereHas('memberships', fn (Builder $query): Builder => $query
                ->whereBelongsTo($this)
                ->where('role', OrganizationRole::Owner))
            ->has('owners', '=', 1)
            ->exists();
    }
}
