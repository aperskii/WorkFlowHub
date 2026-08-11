@props([
    'label',
    'value',
    'icon' => null,
    // 'danger' marks a figure that represents a problem. It only takes effect
    // when the figure is non-zero, so a healthy organization stays neutral.
    'tone' => 'neutral',
    // Short factual context, e.g. "of 12 open". Never a trend we cannot compute.
    'context' => null,
    'href' => null,
])

@php
    $isDanger = $tone === 'danger' && (int) $value !== 0;

    $classes = 'wfh-panel flex flex-col gap-2 p-4'
        .($isDanger ? ' border-red-200 dark:border-red-500/30' : '')
        .(filled($href) ? ' transition-colors duration-100 hover:border-zinc-300 dark:hover:border-white/20' : '');

    $labelClasses = 'truncate text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400';
    $iconClasses = 'size-4 shrink-0 '.($isDanger ? 'text-red-500 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-500');
    $valueClasses = 'tabular truncate text-2xl leading-none font-semibold '
        .($isDanger ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white');
@endphp

@if (filled($href))
    <a href="{{ $href }}" wire:navigate {{ $attributes->class($classes) }}>
@else
    <div {{ $attributes->class($classes) }}>
@endif

    <div class="flex items-center justify-between gap-2">
        <span class="{{ $labelClasses }}">{{ $label }}</span>

        @if ($icon)
            <flux:icon :icon="$icon" variant="outline" :class="$iconClasses" />
        @endif
    </div>

    <span class="{{ $valueClasses }}">{{ $value }}</span>

    @if (filled($context))
        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $context }}</p>
    @endif

@if (filled($href))
    </a>
@else
    </div>
@endif
