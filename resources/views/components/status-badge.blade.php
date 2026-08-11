@props(['status'])

<flux:badge size="sm" inset="top bottom" :color="$status->color()" {{ $attributes }}>
    {{ $status->label() }}
</flux:badge>
