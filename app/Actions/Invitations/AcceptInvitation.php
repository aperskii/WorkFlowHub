<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    /**
     * Accept an invitation on behalf of the authenticated user.
     *
     * Every precondition is re-checked here rather than trusted from the page
     * that rendered the accept button.
     */
    public function handle(Invitation $invitation, User $user): Membership
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer valid.'),
            ]);
        }

        if (! $invitation->isFor($user->email)) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation was sent to a different email address.'),
            ]);
        }

        return DB::transaction(function () use ($invitation, $user): Membership {
            $existing = $user->membershipFor($invitation->organization);

            // Already a member: consume the invitation without creating a second
            // membership, which the database would reject anyway.
            $membership = $existing ?? $invitation->organization->memberships()->create([
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $membership;
        });
    }
}
