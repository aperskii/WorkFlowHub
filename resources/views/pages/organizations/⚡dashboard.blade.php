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
     * How far ahead work counts as falling due shortly.
     */
    private const DUE_SOON_DAYS = 7;

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

    /**
     * Get a greeting appropriate to the viewer's local part of the day.
     */
    #[Computed]
    public function greeting(): string
    {
        // Drop honorifics such as "Dr." so the greeting uses a given name.
        $name = str(Auth::user()->name)->trim()->explode(' ')
            ->reject(fn (string $part) => $part === '' || str_ends_with($part, '.'))
            ->first() ?? Auth::user()->name;

        return match (true) {
            now()->hour < 12 => __('Good morning, :name', ['name' => $name]),
            now()->hour < 18 => __('Good afternoon, :name', ['name' => $name]),
            default => __('Good evening, :name', ['name' => $name]),
        };
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
     * Get the number of open tasks falling due within the next week.
     */
    #[Computed]
    public function dueSoonTaskCount(): int
    {
        return $this->organizationTasks()->dueSoon(self::DUE_SOON_DAYS)->count();
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
     * Get the open work a manager should look at: late, falling due, or
     * unowned.
     *
     * Ordering by due date puts the most overdue first, then work falling due,
     * then undated unassigned work, which is exactly the order of urgency.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function attentionTasks(): Collection
    {
        return $this->organizationTasks()
            ->needsAttention(self::DUE_SOON_DAYS)
            ->with(['project', 'assignee'])
            ->orderBy('due_date')
            ->take(8)
            ->get();
    }

    /**
     * Get the number of open tasks needing attention.
     */
    #[Computed]
    public function attentionCount(): int
    {
        return $this->organizationTasks()->needsAttention(self::DUE_SOON_DAYS)->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    */

    /**
     * Get this organization's active projects with their real task progress.
     *
     * Counts are aggregated in the same query, so the list costs two queries
     * regardless of how many projects come back.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function activeProjects(): Collection
    {
        return $this->organization->projects()
            ->active()
            ->withCount([
                'tasks',
                'tasks as open_tasks_count' => fn (Builder $query) => $query->open(),
            ])
            ->latest()
            ->take(6)
            ->get();
    }

    /**
     * Get this organization's most recently created projects.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function recentProjects(): Collection
    {
        return $this->organization->projects()
            ->withCount([
                'tasks',
                'tasks as open_tasks_count' => fn (Builder $query) => $query->open(),
            ])
            ->latest()
            ->take(6)
            ->get();
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
    :heading="$this->greeting"
    :breadcrumb-label="__('Dashboard')"
    :subheading="__('Here\'s what\'s happening across :name.', ['name' => $organization->name])"
>
    <x-slot:meta>
        <flux:badge size="sm" inset="top bottom" :color="$this->role->color()" data-test="organization-role">
            {{ $this->role->label() }}
        </flux:badge>
    </x-slot:meta>

    <x-slot:actions>
        @can('create', [App\Models\Project::class, $organization])
            <flux:button
                :href="route('organizations.projects.create', $organization)"
                variant="primary"
                icon="plus"
                size="sm"
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
                size="sm"
                wire:navigate
                data-test="dashboard-invite-member"
            >
                {{ __('Invite member') }}
            </flux:button>
        @endcan
    </x-slot:actions>

    {{-- KPI row. Employees see their own load first; managers see the organization. --}}
    @if ($this->managesWork)
        {{-- Five across only from xl. At 1024px five cards leave roughly 130px
             each, which truncates the labels. --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" data-test="organization-metrics">
            <x-metric-card
                :label="__('Active projects')"
                :value="$this->activeProjectCount"
                icon="bolt"
                :context="trans_choice('{1} :count project total|[0,*] :count projects total', $this->projectCount, ['count' => $this->projectCount])"
                :href="route('organizations.projects.index', $organization)"
                data-test="organization-active-project-count"
            />

            <x-metric-card
                :label="__('Open tasks')"
                :value="$this->openTaskCount"
                icon="clipboard-document-list"
                :context="trans_choice('{0} None falling due|{1} :count due this week|[2,*] :count due this week', $this->dueSoonTaskCount, ['count' => $this->dueSoonTaskCount])"
                data-test="organization-open-task-count"
            />

            <x-metric-card
                :label="__('Overdue')"
                :value="$this->overdueTaskCount"
                icon="exclamation-triangle"
                tone="danger"
                :context="$this->overdueTaskCount === 0 ? __('Nothing is late') : __('Past the due date')"
                data-test="organization-overdue-task-count"
            />

            <x-metric-card
                :label="__('Completed tasks')"
                :value="$this->completedTaskCount"
                icon="check-circle"
                :context="__('Marked done')"
                data-test="organization-completed-task-count"
            />

            <x-metric-card
                :label="__('Members')"
                :value="$this->memberCount"
                icon="users"
                :context="__('In this organization')"
                :href="route('organizations.members', $organization)"
                data-test="organization-member-count"
            />
        </div>
    @else
        {{-- Four cards divide evenly at two and at four, so they never leave a
             ragged row. --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" data-test="personal-metrics">
            <x-metric-card
                :label="__('My open tasks')"
                :value="$this->myOpenTaskCount"
                icon="clipboard-document-list"
                :context="__('Assigned to you')"
                data-test="my-open-task-count"
            />

            <x-metric-card
                :label="__('My overdue tasks')"
                :value="$this->myOverdueTaskCount"
                icon="exclamation-triangle"
                tone="danger"
                :context="$this->myOverdueTaskCount === 0 ? __('You\'re on track') : __('Past the due date')"
                data-test="my-overdue-task-count"
            />

            <x-metric-card
                :label="__('Active projects')"
                :value="$this->activeProjectCount"
                icon="bolt"
                :context="__('Being worked on')"
                :href="route('organizations.projects.index', $organization)"
                data-test="organization-active-project-count"
            />

            <x-metric-card
                :label="__('Members')"
                :value="$this->memberCount"
                icon="users"
                :context="__('In this organization')"
                :href="route('organizations.members', $organization)"
                data-test="organization-member-count"
            />
        </div>
    @endif

    @php
        // A manager's own queue is the only thing that earns the side column.
        // Without it the main panel takes the full width rather than leaving a
        // gap where the old organization summary used to be.
        $hasSideColumn = $this->managesWork && $this->myTasks->isNotEmpty();
    @endphp

    <div @class(['mt-6 grid gap-5', 'xl:grid-cols-3' => $hasSideColumn])>
        {{-- Main column: what is slipping. min-w-0 keeps a long task title from
             widening the column instead of truncating inside it. --}}
        <div @class(['min-w-0', 'xl:col-span-2' => $hasSideColumn])>
            @if ($this->managesWork)
                <x-panel
                    :title="__('Needs attention')"
                    :description="__('Late, falling due this week, or waiting for an owner')"
                    flush
                    data-test="needs-attention"
                >
                    @if ($this->attentionCount > 0)
                        <x-slot:action>
                            <span class="tabular text-xs text-zinc-500 dark:text-zinc-400" data-test="unassigned-task-summary">
                                {{ __(':overdue overdue · :unassigned unassigned', [
                                    'overdue' => $this->overdueTaskCount,
                                    'unassigned' => $this->unassignedTaskCount,
                                ]) }}
                            </span>
                        </x-slot:action>
                    @endif

                    @if ($this->attentionTasks->isEmpty())
                        <x-empty-state
                            icon="check-circle"
                            :heading="__('No tasks need your attention')"
                            :description="__('Nothing is late, falling due this week, or missing an owner. You\'re all caught up.')"
                        />
                    @else
                        <ul class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach ($this->attentionTasks as $task)
                                <x-task-row :task="$task" :organization="$organization" />
                            @endforeach
                        </ul>
                    @endif
                </x-panel>
            @else
                <x-panel
                    :title="__('Your work')"
                    :description="__('Tasks assigned to you, soonest due first')"
                    flush
                    data-test="assigned-to-me"
                >
                    @if ($this->myOverdueTaskCount > 0)
                        <x-slot:action>
                            <flux:badge size="sm" inset="top bottom" color="red">
                                {{ trans_choice('{1} :count overdue|[2,*] :count overdue', $this->myOverdueTaskCount, ['count' => $this->myOverdueTaskCount]) }}
                            </flux:badge>
                        </x-slot:action>
                    @endif

                    @if ($this->myTasks->isEmpty())
                        <x-empty-state
                            icon="check-circle"
                            :heading="__('Nothing is assigned to you right now.')"
                            :description="__('Tasks assigned to you across this organization\'s projects appear here, soonest due first.')"
                            data-test="assigned-to-me-empty"
                        />
                    @else
                        <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-test="my-task-list">
                            @foreach ($this->myTasks as $task)
                                <x-task-row :task="$task" :organization="$organization" :show-assignee="false" />
                            @endforeach
                        </ul>
                    @endif
                </x-panel>
            @endif
        </div>

        {{-- Side column: the manager's own queue. Member and project counts live
             in the metric row above, and members are reachable from both that row
             and the sidebar, so a summary panel here only repeated the page. --}}
        @if ($hasSideColumn)
            <x-panel :title="__('Your work')" class="self-start" flush data-test="assigned-to-me">
                <x-slot:action>
                    <span class="tabular text-xs text-zinc-500 dark:text-zinc-400">{{ $this->myOpenTaskCount }}</span>
                </x-slot:action>

                <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-test="my-task-list">
                    @foreach ($this->myTasks->take(5) as $task)
                        <x-task-row :task="$task" :organization="$organization" :show-assignee="false" />
                    @endforeach
                </ul>
            </x-panel>
        @endif
    </div>

    {{-- Projects. --}}
    @php
        $projects = $this->managesWork ? $this->recentProjects : $this->activeProjects;
    @endphp

    <x-panel
        class="mt-5"
        :title="$this->managesWork ? __('Recent projects') : __('Active projects')"
        :description="__('Jump back into the work')"
        flush
        data-test="recent-projects"
    >
        <x-slot:action>
            <flux:button
                :href="route('organizations.projects.index', $organization)"
                variant="ghost"
                size="sm"
                icon-trailing="arrow-right"
                wire:navigate
            >
                {{ __('View all') }}
            </flux:button>
        </x-slot:action>

        @if ($projects->isEmpty())
            <x-empty-state
                icon="folder"
                :heading="$this->projectCount === 0 ? __('No projects yet.') : __('No active projects.')"
                :description="$this->projectCount === 0
                    ? __('Create your first project to start organizing your team\'s work.')
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
                                {{ __('Create project') }}
                            </flux:button>
                        </x-slot:action>
                    @endif
                @endcan
            </x-empty-state>
        @else
            <ul class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($projects as $project)
                    <li>
                        <a
                            href="{{ route('organizations.projects.show', [$organization, $project]) }}"
                            wire:navigate
                            class="wfh-panel wfh-row flex h-full flex-col gap-3 p-3"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $project->name }}
                                </span>

                                <x-status-badge :status="$project->status" />
                            </div>

                            <x-project-progress :project="$project" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>
</x-pages::organizations.layout>
