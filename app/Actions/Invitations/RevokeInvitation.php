<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use Illuminate\Validation\ValidationException;

class RevokeInvitation
{
    /**
     * Revoke an invitation so its token can no longer be accepted.
     */
    public function handle(Invitation $invitation): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitations' => __('That invitation has already been accepted.'),
            ]);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        return $invitation;
    }
}
