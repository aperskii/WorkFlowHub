<?php

use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Link-only organization switcher for the application sidebar.
 *
 * It exposes no actions, so it is only ever rendered during a full page request
 * and can safely read the route-bound organization. Tenancy stays route-bound:
 * every entry is a slug URL and no "current organization" is stored in session.
 */
new class extends Component {
    /**
     * Get the authenticated user's memberships, newest organizations last.
     *
     * @return Collection<int, Membership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return Auth::user()->memberships()->with('organization')->oldest()->get();
    }

    /**
     * Get the organization the current route is scoped to, if any.
     */
    #[Computed]
    public function current(): ?Organization
    {
        $organization = request()->route('organization');

        return $organization instanceof Organization ? $organization : null;
    }
}; ?>

<div class="px-1">
    <flux:dropdown position="bottom" align="start" class="w-full">
        <flux:button
            variant="subtle"
            class="w-full justify-between"
            icon-trailing="chevrons-up-down"
            data-test="organization-switcher"
        >
            <span class="truncate text-start">
                {{ $this->current?->name ?? __('Select organization') }}
            </span>
        </flux:button>

        <flux:menu class="min-w-64">
            @if ($this->memberships->isNotEmpty())
                <flux:menu.group :heading="__('Your organizations')">
                    @foreach ($this->memberships as $membership)
                        <flux:menu.item
                            :href="route('organizations.dashboard', $membership->organization)"
                            wire:navigate
                            icon="building-office-2"
                        >
                            <span class="truncate">{{ $membership->organization->name }}</span>
                        </flux:menu.item>
                    @endforeach
                </flux:menu.group>

                <flux:menu.separator />
            @endif

            <flux:menu.item :href="route('organizations.create')" wire:navigate icon="plus">
                {{ __('Create organization') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
