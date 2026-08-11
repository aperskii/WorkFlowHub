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

    /**
     * Get the viewer's role in the current organization, if any.
     */
    #[Computed]
    public function currentRole(): ?string
    {
        return $this->current
            ? Auth::user()->membershipFor($this->current)?->role->label()
            : null;
    }
}; ?>

<div class="px-2 py-1">
    <flux:dropdown position="bottom" align="start" class="w-full">
        <button
            type="button"
            class="flex w-full items-center gap-2.5 rounded-lg border border-zinc-200 bg-white px-2 py-2 text-start transition-colors hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
            data-test="organization-switcher"
        >
            @if ($this->current)
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-800 text-xs font-semibold text-white dark:bg-white dark:text-zinc-900"
                    aria-hidden="true"
                >
                    {{ str($this->current->name)->substr(0, 1)->upper() }}
                </span>
            @else
                <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-400 dark:bg-white/10">
                    <flux:icon icon="building-office-2" variant="outline" class="size-4" />
                </span>
            @endif

            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">
                    {{ $this->current?->name ?? __('Select organization') }}
                </span>

                @if ($this->currentRole)
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $this->currentRole }}</span>
                @endif
            </span>

            <flux:icon icon="chevrons-up-down" variant="outline" class="size-4 shrink-0 text-zinc-400" />
        </button>

        <flux:menu class="min-w-72">
            @if ($this->memberships->isNotEmpty())
                <flux:menu.group :heading="__('Your organizations')">
                    @foreach ($this->memberships as $membership)
                        <flux:menu.item
                            :href="route('organizations.dashboard', $membership->organization)"
                            wire:navigate
                            :icon="$this->current?->is($membership->organization) ? 'check' : null"
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
