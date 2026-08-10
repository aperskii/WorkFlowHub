<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ResendInvitation
{
    /**
     * Resend an invitation with a freshly rotated token.
     *
     * The previous token stops working immediately. Reusing it would leave every
     * older copy of the email live, which defeats the point of resending when the
     * first message reached the wrong inbox.
     */
    public function handle(Invitation $invitation, User $inviter): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitations' => __('That invitation has already been accepted.'),
            ]);
        }

        $alreadyMember = $invitation->organization->users()
            ->where('email', $invitation->email)
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'invitations' => __('That person is already a member of this organization.'),
            ]);
        }

        $token = Invitation::generateToken();

        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(config('auth.invitation_expires_after_days')),
            'accepted_at' => null,
            'revoked_at' => null,
            'invited_by_user_id' => $inviter->id,
        ])->save();

        Notification::route('mail', $invitation->email)
            ->notify(new OrganizationInvitation($invitation, $token));

        return $invitation;
    }
}
