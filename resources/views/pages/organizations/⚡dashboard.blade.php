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

new #[Title('Organization')] class extends Component {
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
     * Get the number of members belonging to this organization.
     */
    #[Computed]
    public function memberCount(): int
    {
        return $this->organization->memberships()->count();
    }

    /**
     * Get the number of owners belonging to this organization.
     */
    #[Computed]
    public function ownerCount(): int
    {
        return $this->organization->owners()->count();
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
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('Overview')"
    :subheading="__('A summary of :name', ['name' => $organization->name])"
>
    <x-slot:actions>
        <flux:button
            :href="route('organizations.projects.index', $organization)"
            variant="filled"
            icon="folder"
            wire:navigate
        >
            {{ __('Projects') }}
        </flux:button>

        <flux:button
            :href="route('organizations.members', $organization)"
            variant="filled"
            icon="users"
            wire:navigate
        >
            {{ __('Members') }}
        </flux:button>

        @can('update', $organization)
            <flux:button
                :href="route('organizations.settings', $organization)"
                variant="primary"
                icon="cog-6-tooth"
                wire:navigate
            >
                {{ __('Settings') }}
            </flux:button>
        @endcan
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat-tile
            :label="__('Active projects')"
            :value="$this->activeProjectCount"
            icon="bolt"
            data-test="organization-active-project-count"
        />

        <x-stat-tile
            :label="__('Total projects')"
            :value="$this->projectCount"
            icon="folder"
            data-test="organization-project-count"
        />

        <x-stat-tile
            :label="__('Open tasks')"
            :value="$this->openTaskCount"
            icon="clipboard-document-list"
            data-test="organization-open-task-count"
        />

        <x-stat-tile
            :label="__('Completed tasks')"
            :value="$this->completedTaskCount"
            icon="check-circle"
            data-test="organization-completed-task-count"
        />

        <x-stat-tile
            :label="__('Members')"
            :value="$this->memberCount"
            icon="users"
            data-test="organization-member-count"
        />

        <x-stat-tile
            :label="__('Owners')"
            :value="$this->ownerCount"
            icon="shield-check"
            data-test="organization-owner-count"
        />

        <x-stat-tile
            :label="__('Your role')"
            :value="$this->role->label()"
            icon="user-circle"
            data-test="organization-role"
        />

        <x-stat-tile
            :label="__('Created')"
            :value="$organization->created_at->toFormattedDateString()"
            icon="calendar-days"
        />
    </div>

    <flux:card class="mt-6 space-y-3" data-test="recent-projects">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Recent projects') }}</flux:heading>

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

        @if ($this->recentProjects->isEmpty())
            <flux:text class="text-sm" data-test="recent-projects-empty">
                {{ __('No projects yet.') }}
            </flux:text>
        @else
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->recentProjects as $project)
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

    <flux:card class="mt-6 space-y-3">
        <flux:heading size="lg">{{ __('Organization details') }}</flux:heading>

        <flux:separator variant="subtle" />

        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="min-w-0 space-y-1">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Name') }}</flux:subheading></dt>
                <dd><flux:text class="truncate" data-test="organization-name">{{ $organization->name }}</flux:text></dd>
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
    </flux:card>
</x-pages::organizations.layout>
