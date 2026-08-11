@props([
    'icon' => null,
    'heading',
    'description' => null,
])

{{--
    The single empty state used across the application. Every area answers the
    same three questions: what is empty, why it matters, and what to do next.
    Built on flux:callout so the visual language matches the rest of the UI.
--}}
<flux:callout :icon="$icon" {{ $attributes }}>
    <flux:callout.heading>{{ $heading }}</flux:callout.heading>

    @if (filled($description))
        <flux:callout.text>{{ $description }}</flux:callout.text>
    @endif

    @isset($action)
        <x-slot:actions>{{ $action }}</x-slot:actions>
    @endisset
</flux:callout>
