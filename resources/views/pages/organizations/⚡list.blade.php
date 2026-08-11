<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Get the authenticated user's memberships, eager loading each organization
     * together with its member count.
     *
     * Scoped through the user's own memberships relationship, so an organization
     * the user does not belong to can never appear here.
     *
     * @return Collection<int, Membership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return Auth::user()
            ->memberships()
            ->with(['organization' => fn (Relation $query) => $query->withCount('memberships')])
            ->oldest()
            ->get();
    }

    /**
     * Get the number of organizations the user owns.
     */
    #[Computed]
    public function ownedCount(): int
    {
        return $this->memberships
            ->filter(fn (Membership $membership) => $membership->role === OrganizationRole::Owner)
            ->count();
    }
}; ?>

<div class="w-full">
    <x-page-header
        :title="__('Your organizations')"
        :description="__('Every organization you belong to across WorkFlowHub')"
    >
        <x-slot:actions>
            <flux:button
                :href="route('organizations.create')"
                variant="primary"
                icon="plus"
                wire:navigate
                data-test="create-organization-link"
            >
                {{ __('Create organization') }}
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->memberships->isEmpty())
        <x-empty-state
            icon="building-office-2"
            :heading="__('You don\'t belong to any organizations yet.')"
            :description="__('An organization is where your projects, tasks, and team members live. Create one to get started — you\'ll become its owner.')"
            data-test="organizations-empty-state"
        >
            <x-slot:action>
                <flux:button
                    :href="route('organizations.create')"
                    variant="primary"
                    size="sm"
                    icon="plus"
                    wire:navigate
                    data-test="empty-create-organization"
                >
                    {{ __('Create your first organization') }}
                </flux:button>
            </x-slot:action>
        </x-empty-state>
    @else
        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:max-w-md">
            <x-metric-card
                :label="__('Organizations')"
                :value="$this->memberships->count()"
                icon="building-office-2"
            />

            <x-metric-card
                :label="__('Owned by you')"
                :value="$this->ownedCount"
                icon="shield-check"
                :context="__('You can rename or delete these')"
            />
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-test="organization-list">
            @foreach ($this->memberships as $membership)
                <a
                    href="{{ route('organizations.dashboard', $membership->organization) }}"
                    wire:navigate
                    wire:key="org-{{ $membership->id }}"
                    class="wfh-panel wfh-row group flex flex-col gap-3 p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md bg-zinc-800 text-xs font-semibold text-white dark:bg-white dark:text-zinc-900"
                                aria-hidden="true"
                            >
                                {{ str($membership->organization->name)->substr(0, 1)->upper() }}
                            </span>

                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $membership->organization->name }}
                                </p>

                                <p class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                    /o/{{ $membership->organization->slug }}
                                </p>
                            </div>
                        </div>

                        <flux:badge size="sm" inset="top bottom" :color="$membership->role->color()">
                            {{ $membership->role->label() }}
                        </flux:badge>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-zinc-200 pt-3 text-xs text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                        <span class="flex items-center gap-1.5">
                            <flux:icon icon="users" variant="outline" class="size-3.5" />
                            {{ trans_choice('{1} :count member|[2,*] :count members', $membership->organization->memberships_count, ['count' => $membership->organization->memberships_count]) }}
                        </span>

                        <span class="flex items-center gap-1 font-medium text-zinc-600 group-hover:text-zinc-900 dark:text-zinc-300 dark:group-hover:text-white">
                            {{ __('Open') }}
                            <flux:icon icon="arrow-right" variant="outline" class="size-3.5" />
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
