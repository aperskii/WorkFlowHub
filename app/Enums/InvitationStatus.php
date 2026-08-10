<?php

namespace App\Enums;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Accepted => __('Accepted'),
            self::Revoked => __('Revoked'),
            self::Expired => __('Expired'),
        };
    }

    /**
     * Determine whether an invitation in this state may still be accepted.
     */
    public function isAcceptable(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Get the Flux badge colour used to present the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Accepted => 'lime',
            self::Revoked => 'red',
            self::Expired => 'zinc',
        };
    }
}
