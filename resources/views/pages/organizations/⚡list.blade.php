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
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-tile :label="__('Organizations')" :value="$this->memberships->count()" icon="building-office-2" />
            <x-stat-tile :label="__('Owned by you')" :value="$this->ownedCount" icon="shield-check" />
        </div>

        <flux:heading size="lg" class="mb-3">{{ __('Organizations') }}</flux:heading>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="organization-list">
            @foreach ($this->memberships as $membership)
                <flux:card :key="$membership->id" class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <flux:heading size="lg" class="truncate">
                                {{ $membership->organization->name }}
                            </flux:heading>

                            <flux:text class="truncate font-mono text-xs">
                                /o/{{ $membership->organization->slug }}
                            </flux:text>
                        </div>

                        <flux:badge size="sm" inset="top bottom" :color="$membership->role->color()">
                            {{ $membership->role->label() }}
                        </flux:badge>
                    </div>

                    <flux:separator variant="subtle" />

                    <div class="flex items-center gap-4">
                        <flux:text class="flex items-center gap-1.5 text-xs">
                            <flux:icon icon="users" variant="outline" class="size-4" />
                            {{ trans_choice('{1} :count member|[2,*] :count members', $membership->organization->memberships_count, ['count' => $membership->organization->memberships_count]) }}
                        </flux:text>

                        <flux:text class="flex items-center gap-1.5 text-xs">
                            <flux:icon icon="calendar-days" variant="outline" class="size-4" />
                            {{ __('Joined :date', ['date' => $membership->created_at->toFormattedDateString()]) }}
                        </flux:text>
                    </div>

                    <flux:spacer />

                    <flux:button
                        :href="route('organizations.dashboard', $membership->organization)"
                        variant="filled"
                        size="sm"
                        icon-trailing="arrow-right"
                        wire:navigate
                        class="w-full"
                    >
                        {{ __('Open') }}
                    </flux:button>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
