<?php

use App\Actions\Organizations\CreateOrganization;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create organization')] class extends Component {
    public string $name = '';

    /**
     * Create a new organization owned by the authenticated user.
     *
     * Slug generation, the owner membership, and transactional integrity all
     * live in the action; this component only supplies the submitted name and
     * the server-resolved creator.
     */
    public function createOrganization(CreateOrganization $action): void
    {
        $organization = $action->handle(Auth::user(), $this->name);

        $this->redirect(route('organizations.dashboard', $organization), navigate: true);
    }
}; ?>

<div class="w-full">
    <x-page-header
        :title="__('Create organization')"
        :description="__('An organization holds your projects, clients, and team members')"
    >
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:breadcrumbs.item>

                <flux:breadcrumbs.item>{{ __('Create organization') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <flux:card class="space-y-6">
                <form wire:submit="createOrganization" class="space-y-6">
                    <flux:input
                        wire:model="name"
                        :label="__('Organization name')"
                        :description="__('A URL is generated automatically from the name.')"
                        :placeholder="__('Acme Agency')"
                        type="text"
                        required
                        autofocus
                        autocomplete="organization"
                    />

                    <div class="flex items-center gap-3">
                        <flux:button variant="primary" type="submit" data-test="create-organization-button">
                            {{ __('Create organization') }}
                        </flux:button>

                        <flux:button :href="route('dashboard')" variant="ghost" wire:navigate>
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        </div>

        <flux:card class="space-y-3 self-start">
            <flux:heading size="lg">{{ __('What happens next') }}</flux:heading>

            <flux:separator variant="subtle" />

            <ul class="space-y-3">
                <li class="flex items-start gap-2">
                    <flux:icon icon="shield-check" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <flux:text class="text-sm">{{ __('You become the organization owner.') }}</flux:text>
                </li>

                <li class="flex items-start gap-2">
                    <flux:icon icon="users" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <flux:text class="text-sm">{{ __('You can add members and assign roles.') }}</flux:text>
                </li>

                <li class="flex items-start gap-2">
                    <flux:icon icon="building-office-2" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <flux:text class="text-sm">{{ __('You can belong to as many organizations as you need.') }}</flux:text>
                </li>
            </ul>
        </flux:card>
    </div>
</div>
