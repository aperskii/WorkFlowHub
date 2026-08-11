@props([
    'title' => null,
    'description' => null,
    // Flush panels own their padding, so lists and tables can sit edge to edge.
    'flush' => false,
])

{{--
    The single section container used across the application. Everything that
    used to be a bare flux:card plus a heading plus a separator is this instead,
    so section chrome is defined once.
--}}
<section {{ $attributes->class('wfh-panel flex flex-col overflow-hidden') }}>
    @if (filled($title) || isset($action))
        {{--
            The header stacks below the sm breakpoint. A panel action can be a
            fixed-width filter plus a button, which would otherwise crush the
            title to an ellipsis on a phone.
        --}}
        <header class="flex flex-col gap-3 border-b border-zinc-200 px-4 py-3 sm:flex-row sm:items-start sm:justify-between dark:border-white/10">
            <div class="min-w-0">
                @if (filled($title))
                    <h2 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                @endif

                @if (filled($description))
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
                @endif
            </div>

            @isset($action)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $action }}</div>
            @endisset
        </header>
    @endif

    <div class="{{ $flush ? '' : 'p-4' }} flex-1">
        {{ $slot }}
    </div>
</section>
