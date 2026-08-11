<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * The route-bound organization. Locked so the browser can never swap the
     * tenant context on a subsequent request.
     */
    #[Locked]
    public Organization $organization;

    /**
     * Mount the component.
     */
    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);

        $this->organization = $organization;
    }

    /**
     * Get the authenticated user's role within this organization.
     */
    #[Computed]
    public function role(): OrganizationRole
    {
        return Auth::user()->membershipFor($this->organization)->role;
    }

    /**
     * Determine whether the viewer manages work, and therefore sees the
     * organization-wide view rather than the personal one.
     *
     * Reuses the existing task capability rather than introducing a new one.
     */
    #[Computed]
    public function managesWork(): bool
    {
        return $this->role->canManageTasks();
    }

    /*
    |--------------------------------------------------------------------------
    | Organization figures
    |--------------------------------------------------------------------------
    */

    /**
     * Get the number of members belonging to this organization.
     */
    #[Computed]
    public function memberCount(): int
    {
        return $this->organization->memberships()->count();
    }

    /**
     * Get the number of projects owned by this organization.
     */
    #[Computed]
    public function projectCount(): int
    {
        return $this->organization->projects()->count();
    }

    /**
     * Get the number of active projects owned by this organization.
     */
    #[Computed]
    public function activeProjectCount(): int
    {
        return $this->organization->projects()->active()->count();
    }

    /**
     * Get the number of open tasks across this organization's projects.
     */
    #[Computed]
    public function openTaskCount(): int
    {
        return $this->organizationTasks()->open()->count();
    }

    /**
     * Get the number of completed tasks across this organization's projects.
     */
    #[Computed]
    public function completedTaskCount(): int
    {
        return $this->organizationTasks()->completed()->count();
    }

    /**
     * Get the number of open tasks whose due date has already passed.
     */
    #[Computed]
    public function overdueTaskCount(): int
    {
        return $this->organizationTasks()->overdue()->count();
    }

    /**
     * Get the number of open tasks nobody is responsible for.
     */
    #[Computed]
    public function unassignedTaskCount(): int
    {
        return $this->organizationTasks()->open()->unassigned()->count();
    }

    /*
    |--------------------------------------------------------------------------
    | The viewer's own work
    |--------------------------------------------------------------------------
    */

    /**
     * Get the number of open tasks assigned to the viewer here.
     */
    #[Computed]
    public function myOpenTaskCount(): int
    {
        return $this->organizationTasks()->open()->assignedTo(Auth::user())->count();
    }

    /**
     * Get the number of the viewer's own tasks that are past their due date.
     */
    #[Computed]
    public function myOverdueTaskCount(): int
    {
        return $this->organizationTasks()->overdue()->assignedTo(Auth::user())->count();
    }

    /**
     * Get the viewer's open tasks, soonest due first.
     *
     * PostgreSQL sorts nulls last on an ascending order, so dated work leads and
     * undated work follows without a raw expression.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function myTasks(): Collection
    {
        return $this->organizationTasks()
            ->open()
            ->assignedTo(Auth::user())
            ->with('project')
            ->orderBy('due_date')
            ->take(8)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Work needing attention
    |--------------------------------------------------------------------------
    */

    /**
     * Get the organization's overdue tasks, most overdue first.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function overdueTasks(): Collection
    {
        return $this->organizationTasks()
            ->overdue()
            ->with(['project', 'assignee'])
            ->orderBy('due_date')
            ->take(5)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    */

    /**
     * Get this organization's active projects.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function activeProjects(): Collection
    {
        return $this->organization->projects()->active()->latest()->take(5)->get();
    }

    /**
     * Get this organization's most recently created projects.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function recentProjects(): Collection
    {
        return $this->organization->projects()->latest()->take(5)->get();
    }

    /**
     * Build a task query scoped to this organization through its projects.
     *
     * @return Builder<Task>
     */
    private function organizationTasks(): Builder
    {
        return Task::query()->whereHas(
            'project',
            fn (Builder $query) => $query->where('organization_id', $this->organization->id)
        );
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('Dashboard')"
    :subheading="__('What needs your attention in :name', ['name' => $organization->name])"
>
    <x-slot:actions>
        <flux:badge size="sm" inset="top bottom" :color="$this->role->color()" data-test="organization-role">
            {{ $this->role->label() }}
        </flux:badge>

        @can('create', [App\Models\Project::class, $organization])
            <flux:button
                :href="route('organizations.projects.create', $organization)"
                variant="primary"
                icon="plus"
                wire:navigate
                data-test="dashboard-new-project"
            >
                {{ __('New project') }}
            </flux:button>
        @endcan

        @can('create', [App\Models\Invitation::class, $organization])
            <flux:button
                :href="route('organizations.members', $organization)"
                variant="filled"
                icon="user-plus"
                wire:navigate
                data-test="dashboard-invite-member"
            >
                {{ __('Invite member') }}
            </flux:button>
        @endcan
    </x-slot:actions>

    @if ($this->managesWork)
        {{-- Owners and managers lead with the health of the whole organization. --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-test="organization-metrics">
            <x-stat-tile
                :label="__('Active projects')"
                :value="$this->activeProjectCount"
                icon="bolt"
                data-test="organization-active-project-count"
            />

            <x-stat-tile
                :label="__('Open tasks')"
                :value="$this->openTaskCount"
                icon="clipboard-document-list"
                data-test="organization-open-task-count"
            />

            <x-stat-tile
                :label="__('Overdue')"
                :value="$this->overdueTaskCount"
                icon="exclamation-triangle"
                tone="danger"
                data-test="organization-overdue-task-count"
            />

            <x-stat-tile
                :label="__('Completed tasks')"
                :value="$this->completedTaskCount"
                icon="check-circle"
                data-test="organization-completed-task-count"
            />
        </div>
    @else
        {{-- Employees lead with their own workload. --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="personal-metrics">
            <x-stat-tile
                :label="__('My open tasks')"
                :value="$this->myOpenTaskCount"
                icon="clipboard-document-list"
                data-test="my-open-task-count"
            />

            <x-stat-tile
                :label="__('My overdue tasks')"
                :value="$this->myOverdueTaskCount"
                icon="exclamation-triangle"
                tone="danger"
                data-test="my-overdue-task-count"
            />

            <x-stat-tile
                :label="__('Active projects')"
                :value="$this->activeProjectCount"
                icon="bolt"
                data-test="organization-active-project-count"
            />
        </div>
    @endif

    @if ($this->managesWork && ($this->overdueTaskCount > 0 || $this->unassignedTaskCount > 0))
        <flux:card class="mt-6 space-y-3" data-test="needs-attention">
            <flux:heading size="lg">{{ __('Needs attention') }}</flux:heading>

            <flux:separator variant="subtle" />

            @if ($this->unassignedTaskCount > 0)
                <flux:text class="flex items-start gap-2 text-sm" data-test="unassigned-task-summary">
                    <flux:icon icon="user-minus" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400" />

                    {{ trans_choice(
                        '{1} :count open task has nobody assigned to it.|[2,*] :count open tasks have nobody assigned to them.',
                        $this->unassignedTaskCount,
                        ['count' => $this->unassignedTaskCount]
                    ) }}
                </flux:text>
            @endif

            @if ($this->overdueTasks->isNotEmpty())
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->overdueTasks as $task)
                        <li class="flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                            <div class="min-w-0">
                                <flux:text class="truncate font-medium">{{ $task->title }}</flux:text>

                                <flux:link
                                    :href="route('organizations.projects.show', [$organization, $task->project])"
                                    wire:navigate
                                    class="text-xs"
                                >
                                    {{ $task->project->name }}
                                </flux:link>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <flux:text class="text-xs">
                                    {{ $task->assignee?->name ?? __('Unassigned') }}
                                </flux:text>

                                <flux:badge size="sm" inset="top bottom" color="red">
                                    {{ trans_choice('{1} :count day late|[2,*] :count days late', $task->daysOverdue(), ['count' => $task->daysOverdue()]) }}
                                </flux:badge>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>
    @endif

    @if (! $this->managesWork || $this->myTasks->isNotEmpty())
        <flux:card class="mt-6 space-y-3" data-test="assigned-to-me">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Assigned to me') }}</flux:heading>

                @if ($this->myOverdueTaskCount > 0)
                    <flux:badge size="sm" inset="top bottom" color="red">
                        {{ trans_choice('{1} :count overdue|[2,*] :count overdue', $this->myOverdueTaskCount, ['count' => $this->myOverdueTaskCount]) }}
                    </flux:badge>
                @endif
            </div>

            <flux:separator variant="subtle" />

            @if ($this->myTasks->isEmpty())
                <x-empty-state
                    icon="check-circle"
                    :heading="__('Nothing is assigned to you right now.')"
                    :description="__('Tasks assigned to you across this organization\'s projects appear here, soonest due first.')"
                    data-test="assigned-to-me-empty"
                />
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700" data-test="my-task-list">
                    @foreach ($this->myTasks as $task)
                        <li class="flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                            <div class="min-w-0">
                                <flux:text class="truncate font-medium">{{ $task->title }}</flux:text>

                                <flux:link
                                    :href="route('organizations.projects.show', [$organization, $task->project])"
                                    wire:navigate
                                    class="text-xs"
                                >
                                    {{ $task->project->name }}
                                </flux:link>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                <flux:badge size="sm" inset="top bottom" :color="$task->status->color()">
                                    {{ $task->status->label() }}
                                </flux:badge>

                                @if ($task->isOverdue())
                                    <flux:badge size="sm" inset="top bottom" color="red">
                                        {{ trans_choice('{1} :count day late|[2,*] :count days late', $task->daysOverdue(), ['count' => $task->daysOverdue()]) }}
                                    </flux:badge>
                                @elseif ($task->due_date)
                                    <flux:text class="text-xs whitespace-nowrap">
                                        {{ __('Due :date', ['date' => $task->due_date->toFormattedDateString()]) }}
                                    </flux:text>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <flux:card class="space-y-3 lg:col-span-2" data-test="recent-projects">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">
                    {{ $this->managesWork ? __('Recent projects') : __('Active projects') }}
                </flux:heading>

                <flux:button
                    :href="route('organizations.projects.index', $organization)"
                    variant="ghost"
                    size="sm"
                    icon-trailing="arrow-right"
                    wire:navigate
                >
                    {{ __('View all') }}
                </flux:button>
            </div>

            <flux:separator variant="subtle" />

            @php
                $projects = $this->managesWork ? $this->recentProjects : $this->activeProjects;
            @endphp

            @if ($projects->isEmpty())
                <x-empty-state
                    icon="folder"
                    :heading="$this->projectCount === 0 ? __('No projects yet.') : __('No active projects.')"
                    :description="$this->projectCount === 0
                        ? __('Projects hold the work your team delivers. Create one to start adding tasks.')
                        : __('Nothing is currently being worked on. Move a project to Active to see it here.')"
                    data-test="recent-projects-empty"
                >
                    @can('create', [App\Models\Project::class, $organization])
                        @if ($this->projectCount === 0)
                            <x-slot:action>
                                <flux:button
                                    :href="route('organizations.projects.create', $organization)"
                                    variant="primary"
                                    size="sm"
                                    icon="plus"
                                    wire:navigate
                                    data-test="empty-create-project"
                                >
                                    {{ __('Create your first project') }}
                                </flux:button>
                            </x-slot:action>
                        @endif
                    @endcan
                </x-empty-state>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($projects as $project)
                        <li class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                            <flux:link
                                :href="route('organizations.projects.show', [$organization, $project])"
                                wire:navigate
                                class="min-w-0 truncate font-medium"
                            >
                                {{ $project->name }}
                            </flux:link>

                            <flux:badge size="sm" inset="top bottom" :color="$project->status->color()">
                                {{ $project->status->label() }}
                            </flux:badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>

        <flux:card class="space-y-3 self-start" data-test="organization-summary">
            <flux:heading size="lg">{{ __('Organization') }}</flux:heading>

            <flux:separator variant="subtle" />

            <dl class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Members') }}</flux:subheading></dt>
                    <dd><flux:text data-test="organization-member-count">{{ $this->memberCount }}</flux:text></dd>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Projects') }}</flux:subheading></dt>
                    <dd><flux:text data-test="organization-project-count">{{ $this->projectCount }}</flux:text></dd>
                </div>

                <div class="min-w-0 space-y-1">
                    <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('URL') }}</flux:subheading></dt>
                    <dd>
                        <flux:text class="truncate font-mono text-sm" data-test="organization-slug">
                            /o/{{ $organization->slug }}
                        </flux:text>
                    </dd>
                </div>
            </dl>

            <flux:separator variant="subtle" />

            <flux:button
                :href="route('organizations.members', $organization)"
                variant="filled"
                size="sm"
                icon-trailing="arrow-right"
                wire:navigate
                class="w-full"
            >
                {{ __('View members') }}
            </flux:button>
        </flux:card>
    </div>
</x-pages::organizations.layout>
