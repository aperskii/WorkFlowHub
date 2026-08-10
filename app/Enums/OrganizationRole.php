<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Employee = 'employee';

    /**
     * Get the human readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Manager => __('Manager'),
            self::Employee => __('Employee'),
        };
    }

    /**
     * Determine whether the role may update or delete the organization itself.
     */
    public function canManageOrganization(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Determine whether the role may invite, remove, or re-role members.
     */
    public function canManageMembers(): bool
    {
        return match ($this) {
            self::Owner, self::Manager => true,
            self::Employee => false,
        };
    }

    /**
     * Determine whether the role may create, update, and archive projects.
     */
    public function canManageProjects(): bool
    {
        return match ($this) {
            self::Owner, self::Manager => true,
            self::Employee => false,
        };
    }
}
