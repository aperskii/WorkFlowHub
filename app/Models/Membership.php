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
