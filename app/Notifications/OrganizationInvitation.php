<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitation extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * The raw token is passed in rather than read from the invitation, because
     * only its hash is ever persisted.
     */
    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $token,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invitation->organization;
        $inviter = $this->invitation->invitedBy?->name;

        return (new MailMessage)
            ->subject(__('You have been invited to join :organization', [
                'organization' => $organization->name,
            ]))
            ->greeting(__('You have been invited!'))
            ->line($inviter === null
                ? __('You have been invited to join the :organization organization on WorkFlowHub.', [
                    'organization' => $organization->name,
                ])
                : __(':inviter has invited you to join the :organization organization on WorkFlowHub.', [
                    'inviter' => $inviter,
                    'organization' => $organization->name,
                ]))
            ->line(__('Your role will be: :role', ['role' => $this->invitation->role->label()]))
            ->action(__('Accept invitation'), route('invitations.show', $this->token))
            ->line(__('This invitation expires on :date.', [
                'date' => $this->invitation->expires_at->toDayDateTimeString(),
            ]))
            ->line(__('If you were not expecting this invitation, you can safely ignore this email.'));
    }
}
