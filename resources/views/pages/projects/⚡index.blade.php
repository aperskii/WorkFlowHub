<?php

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
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
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->organization
            ->projects()
            ->when($this->selectedStatus(), fn ($query, ProjectStatus $status) => $query->where('status', $status))
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
    @can('create', [App\Models\Project::class, $organization])
        <x-slot:actions>
            <flux:button
                :href="route('organizations.projects.create', $organization)"
                variant="primary"
                icon="plus"
                wire:navigate
                data-test="new-project-link"
            >
                {{ __('New project') }}
            </flux:button>
        </x-slot:actions>
    @endcan

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-tile
            :label="__('Total projects')"
            :value="$this->totalCount"
            icon="folder"
            data-test="total-projects-count"
        />

        <x-stat-tile
            :label="__('Active projects')"
            :value="$this->activeCount"
            icon="bolt"
            data-test="active-projects-count"
        />
    </div>

    <div class="mb-4 flex items-end justify-between gap-4">
        <flux:select
            wire:model.live="status"
            :label="__('Status')"
            class="max-w-56"
            data-test="project-status-filter"
        >
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>

            @foreach (ProjectStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->projects->isEmpty())
        <flux:callout icon="folder" data-test="projects-empty-state">
            <flux:callout.heading>
                {{ $this->totalCount === 0 ? __('No projects yet.') : __('No projects match this filter.') }}
            </flux:callout.heading>

            <flux:callout.text>
                @if ($this->totalCount === 0)
                    {{ __('Projects hold the work your team delivers for this organization.') }}
                @else
                    {{ __('Try a different status, or clear the filter to see everything.') }}
                @endif
            </flux:callout.text>

            @can('create', [App\Models\Project::class, $organization])
                @if ($this->totalCount === 0)
                    <flux:callout.link
                        :href="route('organizations.projects.create', $organization)"
                        wire:navigate
                    >
                        {{ __('Create your first project') }}
                    </flux:callout.link>
                @endif
            @endcan
        </flux:callout>
    @else
        <flux:table data-test="project-list">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Created') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->projects as $project)
                    <flux:table.row :key="$project->id">
                        <flux:table.cell>
                            <flux:link
                                :href="route('organizations.projects.show', [$organization, $project])"
                                wire:navigate
                                class="font-medium"
                            >
                                {{ $project->name }}
                            </flux:link>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" inset="top bottom" :color="$project->status->color()">
                                {{ $project->status->label() }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell class="whitespace-nowrap text-zinc-500 dark:text-zinc-400">
                            {{ $project->created_at->toFormattedDateString() }}
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <flux:button
                                :href="route('organizations.projects.show', [$organization, $project])"
                                variant="subtle"
                                size="sm"
                                icon-trailing="arrow-right"
                                wire:navigate
                            >
                                {{ __('Open') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</x-pages::organizations.layout>
