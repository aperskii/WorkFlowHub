<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;

class InvitationPolicy
{
    /**
     * Determine whether the user can see an organization's pending invitations.
     *
     * The list exposes invited email addresses, so it follows the same
     * capability as the rest of member management.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization)?->role->canManageMembers() ?? false;
    }

    /**
     * Determine whether the user can invite people into the organization.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization)?->role->canManageMembers() ?? false;
    }

    /**
     * Determine whether the user can resend the given invitation.
     */
    public function resend(User $user, Invitation $invitation): bool
    {
        return $user->membershipFor($invitation->organization)?->role->canManageMembers() ?? false;
    }

    /**
     * Determine whether the user can revoke the given invitation.
     */
    public function revoke(User $user, Invitation $invitation): bool
    {
        return $user->membershipFor($invitation->organization)?->role->canManageMembers() ?? false;
    }
}
