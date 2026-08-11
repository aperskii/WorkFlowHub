<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Policies\MembershipPolicy;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property OrganizationRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 */
#[Fillable(['organization_id', 'user_id', 'role'])]
#[UsePolicy(MembershipPolicy::class)]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
        ];
    }

    /**
     * Guard the ownership invariant at the model layer.
     *
     * The policies already refuse to demote or remove a final owner, but they
     * only run where they are consulted. Enforcing it here makes it a real
     * invariant for every caller: actions, console commands, and future code.
     *
     * Deleting an organization or a user removes memberships through a database
     * cascade rather than Eloquent, so these hooks never block those paths.
     */
    protected static function booted(): void
    {
        static::updating(function (Membership $membership): void {
            if (! $membership->isDirty('role')) {
                return;
            }

            if ($membership->role !== OrganizationRole::Owner && $membership->wasLastOwner()) {
                throw new RuntimeException(
                    "Cannot change membership [{$membership->id}] because it is the last owner of its organization."
                );
            }
        });

        static::deleting(function (Membership $membership): void {
            if ($membership->wasLastOwner()) {
                throw new RuntimeException(
                    "Cannot remove membership [{$membership->id}] because it is the last owner of its organization."
                );
            }
        });

        // Somebody who is no longer a member must not stay assigned to that
        // organization's work. Their assignments elsewhere are untouched.
        static::deleted(function (Membership $membership): void {
            Task::query()
                ->where('assigned_to_user_id', $membership->user_id)
                ->whereHas(
                    'project',
                    fn (Builder $query) => $query->where('organization_id', $membership->organization_id)
                )
                ->update(['assigned_to_user_id' => null]);
        });
    }

    /**
     * Determine whether this membership is the organization's only owner as
     * currently stored, ignoring any unsaved change to its role.
     */
    public function wasLastOwner(): bool
    {
        return $this->getRawOriginal('role') === OrganizationRole::Owner->value
            && $this->organization->owners()->count() === 1;
    }

    /**
     * Get the organization this membership belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user this membership belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope the query to memberships that grant ownership.
     *
     * @param  Builder<Membership>  $query
     */
    #[Scope]
    protected function owners(Builder $query): void
    {
        $query->where('role', OrganizationRole::Owner);
    }

    /**
     * Determine whether this membership is the last remaining ownership of its
     * organization, meaning removing or demoting it would leave the
     * organization without an owner.
     */
    public function isLastOwner(): bool
    {
        return $this->role === OrganizationRole::Owner
            && $this->organization->owners()->count() === 1;
    }
}
