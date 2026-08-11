<div class="flex items-start gap-10 max-md:flex-col max-md:gap-0">
    <div class="w-full pb-4 md:w-[220px] md:shrink-0">
        <flux:navlist aria-label="{{ __('Account settings') }}">
            <flux:navlist.item
                icon="user-circle"
                :href="route('profile.edit')"
                :current="request()->routeIs('profile.edit')"
                wire:navigate
            >
                {{ __('Profile') }}
            </flux:navlist.item>

            <flux:navlist.item
                icon="shield-check"
                :href="route('security.edit')"
                :current="request()->routeIs('security.edit')"
                wire:navigate
            >
                {{ __('Security') }}
            </flux:navlist.item>

            <flux:navlist.item
                icon="sun"
                :href="route('appearance.edit')"
                :current="request()->routeIs('appearance.edit')"
                wire:navigate
            >
                {{ __('Appearance') }}
            </flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="min-w-0 flex-1 self-stretch max-md:pt-6">
        <x-panel :title="$heading ?? ''" :description="$subheading ?? ''">
            <div class="w-full max-w-lg">
                {{ $slot }}
            </div>
        </x-panel>
    </div>
</div>
