<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MembershipPolicy
{
    /**
     * Determine whether the user can change the given membership's role.
     *
     * Denials carry a message so callers can surface why an action was refused
     * without inventing a second set of rules.
     */
    public function updateRole(User $user, Membership $membership, OrganizationRole $role): Response
    {
        $actor = $user->membershipFor($membership->organization);

        if ($actor === null || ! $actor->role->canManageMembers()) {
            return $this->cannotManageMembers();
        }

        if (! $actor->role->canManageOrganization()
            && ($membership->role === OrganizationRole::Owner || $role === OrganizationRole::Owner)) {
            return Response::deny(__('Only an owner can grant or change the owner role.'));
        }

        if ($role !== OrganizationRole::Owner && $membership->isLastOwner()) {
            return $this->wouldLeaveOrganizationOwnerless();
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can remove the given membership.
     */
    public function delete(User $user, Membership $membership): Response
    {
        $actor = $user->membershipFor($membership->organization);

        if ($actor === null || ! $actor->role->canManageMembers()) {
            return $this->cannotManageMembers();
        }

        if ($membership->role === OrganizationRole::Owner && ! $actor->role->canManageOrganization()) {
            return Response::deny(__('Only an owner can remove another owner.'));
        }

        if ($membership->isLastOwner()) {
            return $this->wouldLeaveOrganizationOwnerless();
        }

        return Response::allow();
    }

    /**
     * Deny an actor who has no member management rights in the organization.
     */
    private function cannotManageMembers(): Response
    {
        return Response::deny(__('You are not allowed to manage this organization\'s members.'));
    }

    /**
     * Deny an action that would remove the organization's final owner.
     */
    private function wouldLeaveOrganizationOwnerless(): Response
    {
        return Response::deny(__('An organization must always have at least one owner.'));
    }
}
