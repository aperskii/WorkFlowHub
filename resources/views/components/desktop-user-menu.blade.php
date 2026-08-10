@props([
    'sidebar' => false,
])

<flux:dropdown position="{{ $sidebar ? 'top' : 'bottom' }}" align="{{ $sidebar ? 'start' : 'end' }}" {{ $attributes }}>
    @if ($sidebar)
        <flux:sidebar.profile
            :name="auth()->user()->name"
            :initials="auth()->user()->initials()"
            icon:trailing="chevrons-up-down"
            data-test="sidebar-menu-button"
        />
    @else
        <flux:profile
            :initials="auth()->user()->initials()"
            icon-trailing="chevron-down"
            data-test="sidebar-menu-button"
        />
    @endif

    <flux:menu class="min-w-60">
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />

            <div class="grid min-w-0 flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>

        <flux:menu.separator />

        <flux:menu.group>
            <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>
                {{ __('Profile') }}
            </flux:menu.item>

            <flux:menu.item :href="route('security.edit')" icon="shield-check" wire:navigate>
                {{ __('Security') }}
            </flux:menu.item>

            <flux:menu.item :href="route('appearance.edit')" icon="sun" wire:navigate>
                {{ __('Appearance') }}
            </flux:menu.item>
        </flux:menu.group>

        <flux:menu.separator />

        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('Log out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
