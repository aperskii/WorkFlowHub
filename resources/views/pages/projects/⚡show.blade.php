<?php

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Project')] class extends Component {
    /**
     * The route-bound organization and project. Both locked so the browser can
     * never swap the tenant or the record on a subsequent request.
     */
    #[Locked]
    public Organization $organization;

    #[Locked]
    public Project $project;

    public bool $editing = false;

    public string $name = '';

    public string $description = '';

    /**
     * Mount the component.
     */
    public function mount(Organization $organization, Project $project): void
    {
        $this->authorize('view', $project);

        $this->organization = $organization;
        $this->project = $project;

        $this->resetForm();
    }

    /**
     * Open the inline edit form.
     */
    public function edit(): void
    {
        $this->authorize('update', $this->resolveProject());

        $this->resetForm();

        $this->editing = true;
    }

    /**
     * Abandon the inline edit form.
     */
    public function cancelEdit(): void
    {
        $this->resetForm();

        $this->editing = false;
    }

    /**
     * Update the project's name and description.
     */
    public function updateProject(): void
    {
        $project = $this->resolveProject();

        $this->authorize('update', $project);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project->update([
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
        ]);

        $this->project = $project->refresh();
        $this->editing = false;

        Flux::toast(variant: 'success', text: __('Project updated.'));
    }

    /**
     * Move the project to another status.
     */
    public function changeStatus(string $status): void
    {
        $project = $this->resolveProject();

        $this->authorize('changeStatus', $project);

        $targetStatus = ProjectStatus::tryFrom($status);

        if ($targetStatus === null) {
            throw ValidationException::withMessages([
                'status' => __('That status does not exist.'),
            ]);
        }

        $project->update(['status' => $targetStatus]);

        $this->project = $project->refresh();

        Flux::toast(variant: 'success', text: __('Project status updated.'));
    }

    /**
     * Archive the project.
     */
    public function archiveProject(): void
    {
        $project = $this->resolveProject();

        $this->authorize('archive', $project);

        $project->update(['status' => ProjectStatus::Archived]);

        $this->project = $project->refresh();

        Flux::modal('confirm-project-archive')->close();

        Flux::toast(variant: 'success', text: __('Project archived.'));
    }

    /**
     * Re-resolve the project through the route-bound organization, so a tampered
     * identifier can never reach a project belonging to another tenant.
     */
    private function resolveProject(): Project
    {
        return $this->organization->projects()->findOrFail($this->project->id);
    }

    /**
     * Reset the edit form to the persisted values.
     */
    private function resetForm(): void
    {
        $this->name = $this->project->name;
        $this->description = $this->project->description ?? '';
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="$project->name"
    :subheading="__('Project overview')"
    :parent-label="__('Projects')"
    :parent-href="route('organizations.projects.index', $organization)"
>
    <x-slot:meta>
        <flux:badge size="sm" inset="top bottom" :color="$project->status->color()" data-test="project-status-badge">
            {{ $project->status->label() }}
        </flux:badge>
    </x-slot:meta>

    <x-slot:actions>
        @can('changeStatus', $project)
            <flux:dropdown position="bottom" align="end">
                <flux:button
                    size="sm"
                    variant="filled"
                    icon-trailing="chevron-down"
                    wire:loading.attr="disabled"
                    wire:target="changeStatus"
                    data-test="change-status-button"
                >
                    {{ __('Status') }}
                </flux:button>

                <flux:menu>
                    @foreach (ProjectStatus::cases() as $case)
                        <flux:menu.item
                            wire:click="changeStatus('{{ $case->value }}')"
                            :disabled="$case === $project->status"
                            :data-test="'set-status-'.$case->value"
                        >
                            {{ $case->label() }}
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        @endcan

        @can('update', $project)
            <flux:button
                size="sm"
                variant="primary"
                icon="pencil-square"
                wire:click="edit"
                data-test="edit-project-button"
            >
                {{ __('Edit') }}
            </flux:button>
        @endcan

        @can('archive', $project)
            @unless ($project->status->isArchived())
                <flux:modal.trigger name="confirm-project-archive">
                    <flux:button size="sm" variant="subtle" icon="archive-box" data-test="archive-project-button">
                        {{ __('Archive') }}
                    </flux:button>
                </flux:modal.trigger>
            @endunless
        @endcan
    </x-slot:actions>

    @error('status')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" data-test="project-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($project->status->isArchived())
        <flux:callout icon="archive-box" class="mb-6" data-test="project-archived-notice">
            <flux:callout.heading>{{ __('This project is archived.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Archived projects stay readable. Change the status to pick the work back up.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($editing)
        <x-panel
            class="mb-5"
            :title="__('Edit project')"
            :description="__('The project URL does not change when you rename it.')"
            data-test="project-edit-form"
        >
            <form wire:submit="updateProject" class="space-y-5">
                <flux:input wire:model="name" :label="__('Name')" type="text" required />

                <flux:textarea wire:model="description" :label="__('Description')" rows="4" />

                <div class="flex items-center gap-2">
                    <flux:button
                        variant="primary"
                        size="sm"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="updateProject"
                        data-test="save-project-button"
                    >
                        {{ __('Save changes') }}
                    </flux:button>

                    <flux:button variant="ghost" size="sm" wire:click="cancelEdit" type="button">
                        {{ __('Cancel') }}
                    </flux:button>
                </div>
            </form>
        </x-panel>
    @else
        {{-- Description sits in the header band as context, not as a section
             competing with the work itself. --}}
        <div class="mb-5 flex flex-col gap-3 border-y border-zinc-200 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
            <div class="min-w-0 flex-1">
                @if (filled($project->description))
                    <p class="text-sm whitespace-pre-line text-zinc-600 dark:text-zinc-300" data-test="project-description">
                        {{ $project->description }}
                    </p>
                @else
                    <p class="text-sm text-zinc-500 dark:text-zinc-400" data-test="project-description-empty">
                        {{ __('No description yet.') }}
                    </p>
                @endif
            </div>

            <dl class="flex shrink-0 flex-wrap items-center gap-x-5 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                <div class="flex items-center gap-1.5">
                    <dt class="sr-only">{{ __('Organization') }}</dt>
                    <flux:icon icon="building-office-2" variant="outline" class="size-3.5" />
                    <dd>
                        <a
                            href="{{ route('organizations.dashboard', $organization) }}"
                            wire:navigate
                            class="hover:text-zinc-800 hover:underline dark:hover:text-zinc-200"
                        >{{ $organization->name }}</a>
                    </dd>
                </div>

                <div class="flex items-center gap-1.5">
                    <dt class="sr-only">{{ __('Created') }}</dt>
                    <flux:icon icon="calendar-days" variant="outline" class="size-3.5" />
                    <dd class="tabular">{{ $project->created_at->format('M j, Y') }}</dd>
                </div>

                <div class="hidden items-center gap-1.5 xl:flex">
                    <dt class="sr-only">{{ __('URL') }}</dt>
                    <flux:icon icon="link" variant="outline" class="size-3.5" />
                    <dd class="truncate font-mono" data-test="project-slug">
                        /o/{{ $organization->slug }}/projects/{{ $project->slug }}
                    </dd>
                </div>
            </dl>
        </div>
    @endif

    {{-- The work is the point of this page, so it leads. --}}
    <livewire:pages::projects.tasks :project="$project" />

    @can('archive', $project)
        <flux:modal name="confirm-project-archive" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Archive this project?') }}</flux:heading>

                    <flux:subheading>
                        {{ __(':name will be marked as archived. Nothing is deleted, and you can reactivate it later.', ['name' => $project->name]) }}
                    </flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button
                        variant="danger"
                        wire:click="archiveProject"
                        wire:loading.attr="disabled"
                        wire:target="archiveProject"
                        data-test="confirm-archive-project-button"
                    >
                        {{ __('Archive project') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</x-pages::organizations.layout>
