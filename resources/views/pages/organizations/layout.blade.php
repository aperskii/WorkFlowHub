@props([
    'organization',
    'heading' => '',
    'subheading' => '',
])

<div class="w-full">
    <x-page-header :title="$heading" :description="$subheading">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:breadcrumbs.item>

                <flux:breadcrumbs.item
                    :href="route('organizations.dashboard', $organization)"
                    wire:navigate
                >
                    {{ $organization->name }}
                </flux:breadcrumbs.item>

                <flux:breadcrumbs.item>{{ $heading }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>

        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset
    </x-page-header>

    {{ $slot }}
</div>
