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
        :description="__('An organization holds your projects, tasks, and team members')"
    >
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>
                    {{ __('Organizations') }}
                </flux:breadcrumbs.item>

                <flux:breadcrumbs.item>{{ __('Create organization') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-panel :title="__('Details')" :description="__('You can rename the organization later')">
                <form wire:submit="createOrganization" class="space-y-5">
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

                    <div class="flex items-center gap-2">
                        <flux:button
                            variant="primary"
                            size="sm"
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="createOrganization"
                            data-test="create-organization-button"
                        >
                            {{ __('Create organization') }}
                        </flux:button>

                        <flux:button :href="route('dashboard')" variant="ghost" size="sm" wire:navigate>
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </form>
            </x-panel>
        </div>

        <x-panel :title="__('What happens next')" class="self-start">
            <ul class="space-y-3 text-sm text-zinc-600 dark:text-zinc-300">
                <li class="flex items-start gap-2">
                    <flux:icon icon="shield-check" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    {{ __('You become the organization owner.') }}
                </li>

                <li class="flex items-start gap-2">
                    <flux:icon icon="users" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    {{ __('You can add members and assign roles.') }}
                </li>

                <li class="flex items-start gap-2">
                    <flux:icon icon="building-office-2" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    {{ __('You can belong to as many organizations as you need.') }}
                </li>
            </ul>
        </x-panel>
    </div>
</div>
