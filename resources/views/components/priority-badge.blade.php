@props(['priority'])

{{-- The label carries the meaning; the dot is reinforcement, never the only cue. --}}
<flux:badge size="sm" inset="top bottom" :color="$priority->color()" class="gap-1.5" {{ $attributes }}>
    <span class="size-1.5 shrink-0 rounded-full bg-current opacity-60" aria-hidden="true"></span>
    {{ $priority->label() }}
</flux:badge>
