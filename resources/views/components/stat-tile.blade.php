@props([
    'label',
    'value',
    'icon' => null,
    // 'danger' draws attention to a figure that represents a problem, such as
    // overdue work. Any other value renders the neutral tile.
    'tone' => 'neutral',
])

@php
    $isDanger = $tone === 'danger' && $value !== 0;
@endphp

<flux:card
    class="flex items-start justify-between gap-3 {{ $isDanger ? 'border-red-200 dark:border-red-400/30' : '' }}"
>
    <div class="min-w-0 space-y-1">
        <flux:subheading class="text-xs uppercase tracking-wide">{{ $label }}</flux:subheading>

        <flux:heading
            size="lg"
            class="truncate {{ $isDanger ? 'text-red-600 dark:text-red-400' : '' }}"
            {{ $attributes }}
        >
            {{ $value }}
        </flux:heading>
    </div>

    @if ($icon)
        <flux:icon
            :icon="$icon"
            variant="outline"
            class="size-5 shrink-0 {{ $isDanger ? 'text-red-500 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-500' }}"
        />
    @endif
</flux:card>
