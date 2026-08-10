<?php

namespace App\Actions\Invitations;

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SendInvitation
{
    /**
     * Invite an email address to join an organization with the given role.
     *
     * There is one invitation row per address per organization, so re-inviting
     * somebody rotates the existing row's token rather than creating a second
     * outstanding invitation.
     */
    public function handle(
        Organization $organization,
        User $inviter,
        string $email,
        OrganizationRole $role,
    ): Invitation {
        // Normalized before validation so a padded or upper-case address is
        // judged, stored, and matched in exactly the same form.
        $email = Invitation::normalizeEmail($email);

        Validator::make(['email' => $email, 'role' => $role->value], [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_column(OrganizationRole::invitable(), 'value'))],
        ], [
            'role.in' => __('That role cannot be granted through an invitation.'),
        ])->validate();

        $this->ensureNotAlreadyAMember($organization, $email);

        $token = Invitation::generateToken();

        $invitation = $organization->invitations()->updateOrCreate(
            ['email' => $email],
            [
                'role' => $role,
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(config('auth.invitation_expires_after_days')),
                'accepted_at' => null,
                'revoked_at' => null,
                'invited_by_user_id' => $inviter->id,
            ],
        );

        Notification::route('mail', $email)
            ->notify(new OrganizationInvitation($invitation, $token));

        return $invitation;
    }

    /**
     * Reject an invitation for somebody who already belongs to the organization.
     */
    private function ensureNotAlreadyAMember(Organization $organization, string $email): void
    {
        $alreadyMember = $organization->users()->where('email', $email)->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => __('That person is already a member of this organization.'),
            ]);
        }
    }
}
