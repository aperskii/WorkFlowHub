<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Determine whether the user can view the organization.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization) !== null;
    }

    /**
     * Determine whether the user can update the organization.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization)?->role->canManageOrganization() ?? false;
    }

    /**
     * Determine whether the user can delete the organization.
     */
    public function delete(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization)?->role->canManageOrganization() ?? false;
    }

    /**
     * Determine whether the user can manage the organization's members.
     */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization)?->role->canManageMembers() ?? false;
    }
}
