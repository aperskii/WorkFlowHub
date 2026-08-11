@props([
    'icon' => null,
    'heading',
    'description' => null,
    // 'inline' is the compact variant used inside a panel that already has a
    // header; the default is the roomier standalone treatment.
    'inline' => false,
])

{{--
    Every empty area answers the same three questions: what is empty, why it
    matters, and what to do next. The action slot carries the next step.
--}}
<div {{ $attributes->class(['flex flex-col items-center text-center', $inline ? 'gap-2 px-4 py-8' : 'gap-3 px-6 py-12']) }}>
    @if ($icon)
        <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
            <flux:icon :icon="$icon" variant="outline" class="size-5" />
        </div>
    @endif

    <div class="space-y-1">
        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $heading }}</p>

        @if (filled($description))
            <p class="mx-auto max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>

    @isset($action)
        <div class="mt-1 flex flex-wrap items-center justify-center gap-2">{{ $action }}</div>
    @endisset
</div>
