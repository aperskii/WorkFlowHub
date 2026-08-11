@props([
    'title',
    'description' => null,
])

<div class="mb-6 w-full space-y-3">
    @isset($breadcrumbs)
        <div class="min-w-0">{{ $breadcrumbs }}</div>
    @endisset

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
        <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="truncate text-xl font-semibold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">
                    {{ $title }}
                </h1>

                @isset($meta)
                    {{ $meta }}
                @endisset
            </div>

            @if (filled($description))
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
