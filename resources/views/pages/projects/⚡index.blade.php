<?php

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
    /**
     * The route-bound organization. Locked so the browser can never swap the
     * tenant context on a subsequent request.
     */
    #[Locked]
    public Organization $organization;

    /**
     * The selected status filter. Client supplied, so it is always validated
     * against the enum before it reaches a query.
     */
    public string $status = '';

    /**
     * Mount the component.
     */
    public function mount(Organization $organization): void
    {
        $this->authorize('viewAny', [Project::class, $organization]);

        $this->organization = $organization;
    }

    /**
     * Get this organization's projects, scoped through the relationship so no
     * other tenant's projects can ever be reached.
     *
     * Task counts are aggregated in the same query, so the list costs a fixed
     * number of queries however many projects come back.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->organization
            ->projects()
            ->when($this->selectedStatus(), fn ($query, ProjectStatus $status) => $query->where('status', $status))
            ->withCount([
                'tasks',
                'tasks as open_tasks_count' => fn (Builder $query) => $query->open(),
            ])
            ->latest()
            ->get();
    }

    /**
     * Get the total number of projects in this organization.
     */
    #[Computed]
    public function totalCount(): int
    {
        return $this->organization->projects()->count();
    }

    /**
     * Get the number of active projects in this organization.
     */
    #[Computed]
    public function activeCount(): int
    {
        return $this->organization->projects()->active()->count();
    }

    /**
     * Resolve the filter into a known status, ignoring anything unrecognised.
     */
    private function selectedStatus(): ?ProjectStatus
    {
        return ProjectStatus::tryFrom($this->status);
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('Projects')"
    :subheading="__('Work owned by this organization')"
>
    <x-slot:meta>
        <flux:badge size="sm" inset="top bottom" class="tabular">
            {{ trans_choice('{0} No projects|{1} :count project|[2,*] :count projects', $this->totalCount, ['count' => $this->totalCount]) }}
        </flux:badge>
    </x-slot:meta>

    @can('create', [App\Models\Project::class, $organization])
        <x-slot:actions>
            <flux:button
                :href="route('organizations.projects.create', $organization)"
                variant="primary"
                icon="plus"
                size="sm"
                wire:navigate
                data-test="new-project-link"
            >
                {{ __('New project') }}
            </flux:button>
        </x-slot:actions>
    @endcan

    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:max-w-md">
        <x-metric-card
            :label="__('Total projects')"
            :value="$this->totalCount"
            icon="folder"
            data-test="total-projects-count"
        />

        <x-metric-card
            :label="__('Active projects')"
            :value="$this->activeCount"
            icon="bolt"
            :context="__('Being worked on')"
            data-test="active-projects-count"
        />
    </div>

    <x-panel flush :title="__('All projects')">
        <x-slot:action>
            <div class="flex w-full items-center gap-2 sm:w-auto">
                <span wire:loading wire:target="status" class="flex items-center gap-2" role="status">
                    <flux:icon.loading class="size-4 text-zinc-400" />
                    <span class="sr-only" data-test="projects-loading">{{ __('Loading projects') }}</span>
                </span>

                <flux:select
                    wire:model.live="status"
                    size="sm"
                    class="w-full sm:w-40"
                    :aria-label="__('Filter by status')"
                    data-test="project-status-filter"
                >
                    <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>

                    @foreach (ProjectStatus::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </x-slot:action>

        @if ($this->projects->isEmpty())
            <x-empty-state
                icon="folder"
                :heading="$this->totalCount === 0 ? __('No projects yet.') : __('No projects match this filter.')"
                :description="$this->totalCount === 0
                    ? __('Create your first project to start organizing your team\'s work.')
                    : __('Try a different status, or clear the filter to see everything.')"
                data-test="projects-empty-state"
            >
                @can('create', [App\Models\Project::class, $organization])
                    @if ($this->totalCount === 0)
                        <x-slot:action>
                            <flux:button
                                :href="route('organizations.projects.create', $organization)"
                                variant="primary"
                                size="sm"
                                icon="plus"
                                wire:navigate
                                data-test="empty-create-project"
                            >
                                {{ __('Create project') }}
                            </flux:button>
                        </x-slot:action>
                    @endif
                @endcan
            </x-empty-state>
        @else
            <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-test="project-list">
                @foreach ($this->projects as $project)
                    <li>
                        <a
                            href="{{ route('organizations.projects.show', [$organization, $project]) }}"
                            wire:navigate
                            class="wfh-row flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:gap-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $project->name }}
                                </p>

                                @if (filled($project->description))
                                    <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $project->description }}
                                    </p>
                                @endif
                            </div>

                            <div class="w-full shrink-0 sm:w-40">
                                <x-project-progress :project="$project" />
                            </div>

                            <div class="flex shrink-0 items-center justify-between gap-3 sm:justify-end">
                                <x-status-badge :status="$project->status" />

                                <span class="tabular hidden text-xs whitespace-nowrap text-zinc-500 lg:inline dark:text-zinc-400">
                                    {{ $project->created_at->format('M j, Y') }}
                                </span>

                                <flux:icon
                                    icon="chevron-right"
                                    variant="outline"
                                    class="size-4 shrink-0 text-zinc-400"
                                />
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>
</x-pages::organizations.layout>
