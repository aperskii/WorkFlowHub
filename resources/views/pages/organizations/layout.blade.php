@props([
    'organization',
    'heading' => '',
    'subheading' => '',
    // Optional intermediate breadcrumb, e.g. Projects when viewing one project.
    'parentLabel' => null,
    'parentHref' => null,
])

<div class="w-full">
    <x-page-header :title="$heading" :description="$subheading">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>
                    {{ __('Organizations') }}
                </flux:breadcrumbs.item>

                <flux:breadcrumbs.item
                    :href="route('organizations.dashboard', $organization)"
                    wire:navigate
                >
                    {{ $organization->name }}
                </flux:breadcrumbs.item>

                @if (filled($parentLabel))
                    <flux:breadcrumbs.item :href="$parentHref" wire:navigate>
                        {{ $parentLabel }}
                    </flux:breadcrumbs.item>
                @endif

                <flux:breadcrumbs.item>{{ $heading }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>

        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset
    </x-page-header>

    {{ $slot }}
</div>
