<?php

use App\Models\Organization;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organization settings')] class extends Component {
    /**
     * The route-bound organization. Locked so the browser can never swap the
     * tenant context on a subsequent request.
     */
    #[Locked]
    public Organization $organization;

    public string $name = '';

    /**
     * Mount the component.
     */
    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);

        $this->organization = $organization;
        $this->name = $organization->name;
    }

    /**
     * Update the organization's profile.
     */
    public function updateOrganization(): void
    {
        $this->authorize('update', $this->organization);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->organization->update($validated);

        Flux::toast(variant: 'success', text: __('Organization updated.'));
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('Organization settings')"
    :subheading="__('Manage how this organization appears across WorkFlowHub')"
>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <flux:card class="space-y-6">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('General') }}</flux:heading>
                    <flux:subheading>{{ __('The name your team sees throughout the application') }}</flux:subheading>
                </div>

                <flux:separator variant="subtle" />

                <form wire:submit="updateOrganization" class="space-y-6">
                    <flux:input
                        wire:model="name"
                        :label="__('Name')"
                        type="text"
                        required
                        autocomplete="organization"
                    />

                    <flux:input
                        :label="__('URL')"
                        type="text"
                        :value="'/o/'.$organization->slug"
                        :description="__('The organization URL is generated from its name and cannot be changed yet.')"
                        readonly
                        disabled
                        class="font-mono"
                    />

                    <div class="flex items-center gap-3">
                        <flux:button variant="primary" type="submit" data-test="update-organization-button">
                            {{ __('Save changes') }}
                        </flux:button>

                        <flux:button
                            :href="route('organizations.dashboard', $organization)"
                            variant="ghost"
                            wire:navigate
                        >
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        </div>

        <flux:card class="space-y-3 self-start">
            <flux:heading size="lg">{{ __('Who can change this') }}</flux:heading>

            <flux:separator variant="subtle" />

            <flux:text class="text-sm">
                {{ __('Only owners can rename or delete an organization. Managers can invite and manage members, and employees have read access.') }}
            </flux:text>

            <flux:button
                :href="route('organizations.members', $organization)"
                variant="filled"
                size="sm"
                icon="users"
                wire:navigate
                class="w-full"
            >
                {{ __('Manage members') }}
            </flux:button>
        </flux:card>
    </div>
</x-pages::organizations.layout>
