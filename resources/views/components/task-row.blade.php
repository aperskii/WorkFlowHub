@props([
    'task',
    // Passed explicitly rather than read from $task->project->organization so a
    // list of tasks never triggers a query per row.
    'organization',
    'showAssignee' => true,
])

{{--
    The scannable task line shared by every dashboard list. Title leads, the
    project is the secondary anchor, and everything else is metadata pinned to
    the trailing edge so a column of rows reads cleanly.
--}}
<li {{ $attributes->class('wfh-row px-4 py-3') }}>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $task->title }}</p>

            <a
                href="{{ route('organizations.projects.show', [$organization, $task->project]) }}"
                wire:navigate
                class="mt-0.5 inline-flex max-w-full items-center gap-1 text-xs text-zinc-500 hover:text-zinc-800 hover:underline dark:text-zinc-400 dark:hover:text-zinc-200"
            >
                <flux:icon icon="folder" variant="outline" class="size-3 shrink-0" />
                <span class="truncate">{{ $task->project->name }}</span>
            </a>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <x-status-badge :status="$task->status" />

            <x-priority-badge :priority="$task->priority" />

            @if ($showAssignee)
                @if ($task->assignee)
                    <span class="flex items-center gap-1.5">
                        <flux:avatar
                            size="xs"
                            :name="$task->assignee->name"
                            :initials="$task->assignee->initials()"
                        />
                        <span class="sr-only">{{ __('Assigned to :name', ['name' => $task->assignee->name]) }}</span>
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-md border border-dashed border-zinc-300 px-1.5 py-0.5 text-xs text-zinc-500 dark:border-white/15 dark:text-zinc-400">
                        <flux:icon icon="user-minus" variant="outline" class="size-3" />
                        {{ __('Unassigned') }}
                    </span>
                @endif
            @endif

            @if ($task->isOverdue())
                <flux:badge size="sm" inset="top bottom" color="red">
                    {{ trans_choice('{1} :count day late|[2,*] :count days late', $task->daysOverdue(), ['count' => $task->daysOverdue()]) }}
                </flux:badge>
            @elseif ($task->due_date)
                <span class="tabular whitespace-nowrap text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Due :date', ['date' => $task->due_date->format('M j')]) }}
                </span>
            @endif
        </div>
    </div>
</li>
