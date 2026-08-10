@props([
    'title',
    'description' => null,
])

<div class="mb-6 w-full space-y-4">
    @isset($breadcrumbs)
        <div class="min-w-0">{{ $breadcrumbs }}</div>
    @endisset

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 space-y-1">
            <flux:heading size="xl" level="1" class="truncate">{{ $title }}</flux:heading>

            @if (filled($description))
                <flux:subheading>{{ $description }}</flux:subheading>
            @endif
        </div>

        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    <flux:separator variant="subtle" />
</div>
