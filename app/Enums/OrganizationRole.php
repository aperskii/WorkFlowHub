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
     * Get the Flux badge colour used to present the role.
     */
    public function color(): string
    {
        return match ($this) {
            self::Owner => 'lime',
            self::Manager => 'sky',
            self::Employee => 'zinc',
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
     * Get the roles that may be granted through an organization invitation.
     *
     * Ownership is deliberately excluded: it is granted only through the member
     * role management flow, so an invitation can never mint an owner.
     *
     * @return array<int, self>
     */
    public static function invitable(): array
    {
        return [self::Manager, self::Employee];
    }

    /**
     * Determine whether this role may be granted through an invitation.
     */
    public function isInvitable(): bool
    {
        return in_array($this, self::invitable(), strict: true);
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

    /**
     * Determine whether the role may create, update, and assign tasks.
     *
     * Kept separate from project management so task rules can change without
     * touching who may archive a project.
     */
    public function canManageTasks(): bool
    {
        return match ($this) {
            self::Owner, self::Manager => true,
            self::Employee => false,
        };
    }
}
