<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organization')] class extends Component {
    /**
     * The route-bound organization. Locked so the browser can never swap the
     * tenant context on a subsequent request.
     */
    #[Locked]
    public Organization $organization;

    /**
     * Mount the component.
     */
    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);

        $this->organization = $organization;
    }

    /**
     * Get the authenticated user's role within this organization.
     */
    #[Computed]
    public function role(): OrganizationRole
    {
        return Auth::user()->membershipFor($this->organization)->role;
    }

    /**
     * Get the number of members belonging to this organization.
     */
    #[Computed]
    public function memberCount(): int
    {
        return $this->organization->memberships()->count();
    }

    /**
     * Get the number of owners belonging to this organization.
     */
    #[Computed]
    public function ownerCount(): int
    {
        return $this->organization->owners()->count();
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('Overview')"
    :subheading="__('A summary of :name', ['name' => $organization->name])"
>
    <x-slot:actions>
        <flux:button
            :href="route('organizations.members', $organization)"
            variant="filled"
            icon="users"
            wire:navigate
        >
            {{ __('Members') }}
        </flux:button>

        @can('update', $organization)
            <flux:button
                :href="route('organizations.settings', $organization)"
                variant="primary"
                icon="cog-6-tooth"
                wire:navigate
            >
                {{ __('Settings') }}
            </flux:button>
        @endcan
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile
            :label="__('Members')"
            :value="$this->memberCount"
            icon="users"
            data-test="organization-member-count"
        />

        <x-stat-tile
            :label="__('Owners')"
            :value="$this->ownerCount"
            icon="shield-check"
            data-test="organization-owner-count"
        />

        <x-stat-tile
            :label="__('Your role')"
            :value="$this->role->label()"
            icon="user-circle"
            data-test="organization-role"
        />

        <x-stat-tile
            :label="__('Created')"
            :value="$organization->created_at->toFormattedDateString()"
            icon="calendar-days"
        />
    </div>

    <flux:card class="mt-6 space-y-3">
        <flux:heading size="lg">{{ __('Organization details') }}</flux:heading>

        <flux:separator variant="subtle" />

        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="min-w-0 space-y-1">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Name') }}</flux:subheading></dt>
                <dd><flux:text class="truncate" data-test="organization-name">{{ $organization->name }}</flux:text></dd>
            </div>

            <div class="min-w-0 space-y-1">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('URL') }}</flux:subheading></dt>
                <dd>
                    <flux:text class="truncate font-mono text-sm" data-test="organization-slug">
                        /o/{{ $organization->slug }}
                    </flux:text>
                </dd>
            </div>
        </dl>
    </flux:card>
</x-pages::organizations.layout>
