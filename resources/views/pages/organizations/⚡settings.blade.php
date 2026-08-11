<?php

use App\Models\Organization;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
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
     * The organization name typed to confirm a deletion.
     */
    public string $deleteConfirmation = '';

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

    /**
     * Permanently delete the organization.
     *
     * Re-authorized here rather than trusting the page that rendered the button,
     * and the typed confirmation is checked server-side rather than relying on a
     * disabled control in the browser.
     */
    public function deleteOrganization(): void
    {
        $this->authorize('delete', $this->organization);

        if ($this->deleteConfirmation !== $this->organization->name) {
            throw ValidationException::withMessages([
                'deleteConfirmation' => __('Type the organization name exactly to confirm.'),
            ]);
        }

        // Memberships, projects, and invitations are removed by their database
        // cascades. User accounts are untouched.
        $this->organization->delete();

        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('Organization settings')"
    :subheading="__('Manage how this organization appears across WorkFlowHub')"
>
    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-panel
                :title="__('General')"
                :description="__('The name your team sees throughout the application')"
            >
                <form wire:submit="updateOrganization" class="space-y-5">
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

                    <div class="flex items-center gap-2">
                        <flux:button
                            variant="primary"
                            size="sm"
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="updateOrganization"
                            data-test="update-organization-button"
                        >
                            {{ __('Save changes') }}
                        </flux:button>

                        <flux:button
                            :href="route('organizations.dashboard', $organization)"
                            variant="ghost"
                            size="sm"
                            wire:navigate
                        >
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </form>
            </x-panel>

            <x-panel :title="__('Members')" :description="__('Who belongs to this organization and what they may do')">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Only owners can rename or delete an organization. Managers can invite and manage members, and employees have read access.') }}
                </p>

                <flux:button
                    :href="route('organizations.members', $organization)"
                    variant="filled"
                    size="sm"
                    icon="users"
                    wire:navigate
                    class="mt-4"
                >
                    {{ __('Manage members') }}
                </flux:button>
            </x-panel>
        </div>

        <x-panel :title="__('At a glance')" class="self-start">
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Members') }}</dt>
                    <dd class="tabular font-medium text-zinc-900 dark:text-white">{{ $organization->memberships()->count() }}</dd>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Projects') }}</dt>
                    <dd class="tabular font-medium text-zinc-900 dark:text-white">{{ $organization->projects()->count() }}</dd>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Created') }}</dt>
                    <dd class="tabular font-medium text-zinc-900 dark:text-white">{{ $organization->created_at->format('M j, Y') }}</dd>
                </div>
            </dl>
        </x-panel>
    </div>

    @can('delete', $organization)
        <section
            class="mt-5 overflow-hidden rounded-lg border border-red-200 bg-white dark:border-red-500/30 dark:bg-zinc-900"
            data-test="danger-zone"
        >
            <header class="border-b border-red-200 bg-red-50/60 px-4 py-3 dark:border-red-500/30 dark:bg-red-500/5">
                <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">{{ __('Danger zone') }}</h2>

                <p class="mt-0.5 text-xs text-red-700 dark:text-red-400">
                    {{ __('Irreversible actions that affect everyone in this organization') }}
                </p>
            </header>

            <div class="space-y-4 p-4">
                <div class="space-y-1">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Delete organization') }}</h3>

                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('This permanently deletes :name and everything inside it.', ['name' => $organization->name]) }}
                    </p>
                </div>

                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('This cannot be undone.') }}</flux:callout.heading>

                    <flux:callout.text>
                        <ul class="list-inside list-disc space-y-1">
                            <li>{{ trans_choice('{1} :count project will be deleted|[0,*] :count projects will be deleted', $organization->projects()->count(), ['count' => $organization->projects()->count()]) }}</li>
                            <li>{{ trans_choice('{1} :count member will lose access|[0,*] :count members will lose access', $organization->memberships()->count(), ['count' => $organization->memberships()->count()]) }}</li>
                            <li>{{ __('Pending invitations will stop working') }}</li>
                            <li>{{ __('User accounts themselves are not deleted') }}</li>
                        </ul>
                    </flux:callout.text>
                </flux:callout>

                <flux:modal.trigger name="confirm-organization-deletion">
                    <flux:button variant="danger" size="sm" icon="trash" data-test="delete-organization-button">
                        {{ __('Delete this organization') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>
        </section>

        <flux:modal name="confirm-organization-deletion" class="max-w-lg">
            <form wire:submit="deleteOrganization" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :name?', ['name' => $organization->name]) }}</flux:heading>

                    <flux:subheading>
                        {{ __('Everything in this organization is removed permanently. This cannot be undone.') }}
                    </flux:subheading>
                </div>

                <flux:input
                    wire:model="deleteConfirmation"
                    :label="__('Type :name to confirm', ['name' => $organization->name])"
                    type="text"
                    autocomplete="off"
                    data-test="delete-confirmation-field"
                />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button
                        variant="danger"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="deleteOrganization"
                        data-test="confirm-delete-organization-button"
                    >
                        {{ __('Delete organization') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</x-pages::organizations.layout>
