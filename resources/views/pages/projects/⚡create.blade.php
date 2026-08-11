<?php

use App\Actions\Projects\CreateProject;
use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New project')] class extends Component {
    /**
     * The route-bound organization. Locked so the browser can never swap the
     * tenant context on a subsequent request.
     */
    #[Locked]
    public Organization $organization;

    public string $name = '';

    public string $description = '';

    public string $status = ProjectStatus::Planning->value;

    /**
     * Mount the component.
     */
    public function mount(Organization $organization): void
    {
        $this->authorize('create', [Project::class, $organization]);

        $this->organization = $organization;
    }

    /**
     * Create the project inside the route-bound organization.
     */
    public function createProject(CreateProject $action): void
    {
        $this->authorize('create', [Project::class, $this->organization]);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
        ]);

        $project = $action->handle(
            $this->organization,
            $validated['name'],
            $validated['description'] === '' ? null : $validated['description'],
            ProjectStatus::from($validated['status']),
        );

        $this->redirect(
            route('organizations.projects.show', [$this->organization, $project]),
            navigate: true,
        );
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('New project')"
    :subheading="__('Projects hold the work your team delivers')"
    :parent-label="__('Projects')"
    :parent-href="route('organizations.projects.index', $organization)"
>
    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-panel :title="__('Details')" :description="__('You can rename the project or change its status later')">
                <form wire:submit="createProject" class="space-y-5">
                    <flux:input
                        wire:model="name"
                        :label="__('Project name')"
                        :description="__('A URL is generated automatically from the name.')"
                        :placeholder="__('Website redesign')"
                        type="text"
                        required
                        autofocus
                    />

                    <flux:textarea
                        wire:model="description"
                        :label="__('Description')"
                        :placeholder="__('What is this project about?')"
                        rows="4"
                    />

                    <flux:select wire:model="status" :label="__('Status')">
                        @foreach (ProjectStatus::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex items-center gap-2">
                        <flux:button
                            variant="primary"
                            size="sm"
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="createProject"
                            data-test="create-project-button"
                        >
                            {{ __('Create project') }}
                        </flux:button>

                        <flux:button
                            :href="route('organizations.projects.index', $organization)"
                            variant="ghost"
                            size="sm"
                            wire:navigate
                        >
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </form>
            </x-panel>
        </div>

        <x-panel :title="__('What happens next')" class="self-start">
            <ul class="space-y-3 text-sm text-zinc-600 dark:text-zinc-300">
                <li class="flex items-start gap-2">
                    <flux:icon icon="folder" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    {{ __('The project belongs to :name.', ['name' => $organization->name]) }}
                </li>

                <li class="flex items-start gap-2">
                    <flux:icon icon="users" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    {{ __('Everyone in this organization can see it.') }}
                </li>

                <li class="flex items-start gap-2">
                    <flux:icon icon="arrow-path" variant="outline" class="mt-0.5 size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    {{ __('You can rename it or change its status at any time.') }}
                </li>
            </ul>
        </x-panel>
    </div>
</x-pages::organizations.layout>
