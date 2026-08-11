@props(['project'])

{{--
    Real progress only. Requires the caller to have loaded tasks_count and
    open_tasks_count via withCount, so this never triggers a query per project.
--}}
@php
    $total = (int) ($project->tasks_count ?? 0);
    $open = (int) ($project->open_tasks_count ?? 0);
    $percent = $total > 0 ? (int) round(($total - $open) / $total * 100) : 0;
@endphp

<div {{ $attributes->class('mt-auto') }}>
    @if ($total === 0)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('No tasks yet') }}</p>
    @else
        <div class="space-y-1.5">
            <div class="flex items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="truncate">
                    {{ trans_choice('{0} All done|{1} :count open|[2,*] :count open', $open, ['count' => $open]) }}
                </span>

                <span class="tabular shrink-0">{{ $percent }}%</span>
            </div>

            <div
                class="h-1 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10"
                role="progressbar"
                aria-valuenow="{{ $percent }}"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="{{ __(':project is :percent% complete', ['project' => $project->name, 'percent' => $percent]) }}"
            >
                <div
                    class="h-full rounded-full {{ $percent === 100 ? 'bg-lime-500' : 'bg-zinc-800 dark:bg-white/70' }}"
                    style="width: {{ $percent }}%"
                ></div>
            </div>
        </div>
    @endif
</div>
