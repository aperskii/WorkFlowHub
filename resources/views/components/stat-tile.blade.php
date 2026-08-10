@props([
    'label',
    'value',
    'icon' => null,
])

<flux:card class="flex items-start justify-between gap-3">
    <div class="min-w-0 space-y-1">
        <flux:subheading class="text-xs uppercase tracking-wide">{{ $label }}</flux:subheading>
        <flux:heading size="lg" class="truncate" {{ $attributes }}>{{ $value }}</flux:heading>
    </div>

    @if ($icon)
        <flux:icon :icon="$icon" variant="outline" class="size-5 shrink-0 text-zinc-400 dark:text-zinc-500" />
    @endif
</flux:card>
