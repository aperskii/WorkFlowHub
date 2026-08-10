@props([
    'status',
])

@if ($status)
    <flux:callout variant="success" icon="check-circle" {{ $attributes }}>
        <flux:callout.text>{{ $status }}</flux:callout.text>
    </flux:callout>
@endif
