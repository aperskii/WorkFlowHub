<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Planning => __('Planning'),
            self::Active => __('Active'),
            self::OnHold => __('On hold'),
            self::Completed => __('Completed'),
            self::Archived => __('Archived'),
        };
    }

    /**
     * Determine whether the status means the project is no longer worked on.
     */
    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    /**
     * Get the Flux badge colour used to present the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Planning => 'sky',
            self::Active => 'lime',
            self::OnHold => 'amber',
            self::Completed => 'zinc',
            self::Archived => 'zinc',
        };
    }
}
