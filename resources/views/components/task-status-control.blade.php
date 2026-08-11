@props(['task'])

{{--
    Rendered by both the desktop table and the mobile card list, so the status
    control and its test hooks are defined exactly once.

    Hiding the dropdown is presentation only: TaskPolicy::changeStatus is still
    what decides whether the wire:click is allowed to do anything.
--}}
@can('changeStatus', $task)
    <flux:dropdown position="bottom" align="start">
        {{-- Disabled while this row's own status change is in flight, so the menu
             cannot be reopened and fired a second time. --}}
        <flux:button
            size="sm"
            variant="ghost"
            icon-trailing="chevron-down"
            wire:loading.attr="disabled"
            :wire:target="'changeStatus('.$task->id.')'"
            :data-test="'change-task-status-'.$task->id"
        >
            {{ $task->status->label() }}
        </flux:button>

        <flux:menu>
            @foreach (App\Enums\TaskStatus::cases() as $case)
                <flux:menu.item
                    wire:click="changeStatus({{ $task->id }}, '{{ $case->value }}')"
                    :disabled="$case === $task->status"
                    :data-test="'set-task-status-'.$task->id.'-'.$case->value"
                >
                    {{ $case->label() }}
                </flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
@else
    <flux:badge size="sm" inset="top bottom" :color="$task->status->color()">
        {{ $task->status->label() }}
    </flux:badge>
@endcan
