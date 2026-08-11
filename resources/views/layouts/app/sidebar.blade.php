@php
    $organization = request()->route('organization');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas">
        <flux:sidebar
            sticky
            collapsible="mobile"
            class="border-e border-hairline bg-surface"
        >
            <flux:sidebar.header class="px-2">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:pages::organizations.switcher />

            <flux:sidebar.nav>
                @if ($organization)
                    <flux:sidebar.group :heading="__('Organization')" class="grid">
                        <flux:sidebar.item
                            icon="squares-2x2"
                            :href="route('organizations.dashboard', $organization)"
                            :current="request()->routeIs('organizations.dashboard')"
                            wire:navigate
                        >
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item
                            icon="folder"
                            :href="route('organizations.projects.index', $organization)"
                            :current="request()->routeIs('organizations.projects.*')"
                            wire:navigate
                        >
                            {{ __('Projects') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item
                            icon="users"
                            :href="route('organizations.members', $organization)"
                            :current="request()->routeIs('organizations.members')"
                            wire:navigate
                        >
                            {{ __('Members') }}
                        </flux:sidebar.item>

                        @can('update', $organization)
                            <flux:sidebar.item
                                icon="cog-6-tooth"
                                :href="route('organizations.settings', $organization)"
                                :current="request()->routeIs('organizations.settings')"
                                wire:navigate
                            >
                                {{ __('Settings') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif

                <flux:sidebar.group :heading="__('Workspace')" class="grid">
                    <flux:sidebar.item
                        icon="building-office-2"
                        :href="route('dashboard')"
                        :current="request()->routeIs('dashboard')"
                        wire:navigate
                    >
                        {{ __('All organizations') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item
                    icon="cog"
                    :href="route('profile.edit')"
                    :current="request()->routeIs('profile.edit', 'security.edit', 'appearance.edit')"
                    wire:navigate
                >
                    {{ __('Account settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu :sidebar="true" class="hidden lg:block" />
        </flux:sidebar>

        <flux:header class="border-b border-hairline bg-surface lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            @if ($organization)
                <span class="min-w-0 truncate text-sm font-medium text-zinc-900 dark:text-white">
                    {{ $organization->name }}
                </span>
            @endif

            <flux:spacer />

            <x-desktop-user-menu />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
