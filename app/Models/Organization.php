<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Policies\OrganizationPolicy;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug'])]
#[RouteKey('slug')]
#[UsePolicy(OrganizationPolicy::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Get the memberships that join users to this organization.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the users belonging to this organization.
     *
     * Roles are deliberately not exposed through this pivot; read them from the
     * Membership model so they are cast to OrganizationRole.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')->withTimestamps();
    }

    /**
     * Get the memberships that grant ownership of this organization.
     *
     * @return HasMany<Membership, $this>
     */
    public function owners(): HasMany
    {
        return $this->memberships()->where('role', OrganizationRole::Owner);
    }

    /**
     * Determine whether the given user is a member of this organization.
     */
    public function hasMember(User $user): bool
    {
        return $this->memberships()->whereBelongsTo($user)->exists();
    }
}
