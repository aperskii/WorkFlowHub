@php
    $organization = request()->route('organization');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-900">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:pages::organizations.switcher />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Workspace')" class="grid">
                    <flux:sidebar.item
                        icon="building-office-2"
                        :href="route('dashboard')"
                        :current="request()->routeIs('dashboard')"
                        wire:navigate
                    >
                        {{ __('Organizations') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if ($organization)
                    <flux:sidebar.group :heading="$organization->name" class="grid">
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

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

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
