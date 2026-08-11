<?php

use App\Actions\Tasks\CreateTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /**
     * The project these tasks belong to. Locked so the browser can never swap
     * the project on a subsequent request.
     */
    #[Locked]
    public Project $project;

    /**
     * The task open in the edit form. Locked, and always re-resolved through the
     * project before it is acted upon.
     */
    #[Locked]
    public ?int $editingTaskId = null;

    /**
     * The selected status filter. Client supplied, so it is validated against
     * the enum before it reaches a query.
     */
    public string $statusFilter = '';

    public string $title = '';

    public string $description = '';

    public string $status = '';

    public string $priority = '';

    public string $dueDate = '';

    public string $assigneeId = '';

    /**
     * Mount the component.
     */
    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Task::class, $project]);

        $this->project = $project;

        $this->resetForm();
    }

    /**
     * Get this project's tasks, scoped through the relationship so no other
     * project's work can ever be reached.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->project->tasks()
            ->when(
                TaskStatus::tryFrom($this->statusFilter),
                fn ($query, TaskStatus $status) => $query->where('status', $status)
            )
            ->with('assignee')
            ->latest()
            ->get();
    }

    /**
     * Get the total number of tasks in this project.
     */
    #[Computed]
    public function taskCount(): int
    {
        return $this->project->tasks()->count();
    }

    /**
     * Get the organization members a task may be assigned to.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignableMembers(): Collection
    {
        return $this->project->organization->users()->orderBy('name')->get();
    }

    /**
     * Get the task currently being edited.
     */
    #[Computed]
    public function editingTask(): ?Task
    {
        return $this->editingTaskId === null
            ? null
            : $this->project->tasks()->find($this->editingTaskId);
    }

    /**
     * Open the create task form.
     */
    public function createTask(): void
    {
        $this->authorize('create', [Task::class, $this->project]);

        $this->resetForm();

        Flux::modal('task-form')->show();
    }

    /**
     * Open the edit form for an existing task.
     */
    public function editTask(int $taskId): void
    {
        $task = $this->resolveTask($taskId);

        $this->authorize('update', $task);

        $this->editingTaskId = $task->id;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->status = $task->status->value;
        $this->priority = $task->priority->value;
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->assigneeId = (string) ($task->assigned_to_user_id ?? '');

        unset($this->editingTask);

        Flux::modal('task-form')->show();
    }

    /**
     * Persist the create or edit form.
     */
    public function saveTask(CreateTask $action): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string'],
            'priority' => ['required', 'string'],
            'dueDate' => ['nullable', 'date'],
            'assigneeId' => ['nullable'],
        ]);

        $status = TaskStatus::tryFrom($validated['status']);
        $priority = TaskPriority::tryFrom($validated['priority']);

        if ($status === null || $priority === null) {
            throw ValidationException::withMessages([
                'status' => __('That status or priority does not exist.'),
            ]);
        }

        $assignee = $this->resolveAssignee();
        $dueDate = $validated['dueDate'] === '' ? null : $validated['dueDate'];
        $description = $validated['description'] === '' ? null : $validated['description'];

        if ($this->editingTaskId === null) {
            $this->authorize('create', [Task::class, $this->project]);

            $action->handle(
                $this->project,
                $validated['title'],
                $description,
                $status,
                $priority,
                $dueDate,
                $assignee,
            );
        } else {
            $task = $this->resolveTask($this->editingTaskId);

            $this->authorize('update', $task);

            if ($assignee?->id !== $task->assigned_to_user_id) {
                $this->authorize('assign', $task);
            }

            $task->update([
                'title' => $validated['title'],
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'due_date' => $dueDate,
                'assigned_to_user_id' => $assignee?->id,
            ]);
        }

        $this->resetForm();

        unset($this->tasks, $this->taskCount, $this->editingTask);

        Flux::modal('task-form')->close();

        Flux::toast(variant: 'success', text: __('Task saved.'));
    }

    /**
     * Move a task to another status.
     */
    public function changeStatus(int $taskId, string $status): void
    {
        $task = $this->resolveTask($taskId);

        $this->authorize('changeStatus', $task);

        $targetStatus = TaskStatus::tryFrom($status);

        if ($targetStatus === null) {
            throw ValidationException::withMessages([
                'tasks' => __('That status does not exist.'),
            ]);
        }

        $task->update(['status' => $targetStatus]);

        unset($this->tasks);

        Flux::toast(variant: 'success', text: __('Task status updated.'));
    }

    /**
     * Abandon the task form.
     */
    public function cancelTaskForm(): void
    {
        $this->resetForm();

        unset($this->editingTask);

        Flux::modal('task-form')->close();
    }

    /**
     * Re-resolve a task through the project, so a tampered identifier can never
     * reach a task belonging to another project or tenant.
     */
    private function resolveTask(?int $taskId): Task
    {
        return $this->project->tasks()->findOrFail($taskId);
    }

    /**
     * Resolve the submitted assignee, rejecting anyone outside the organization.
     */
    private function resolveAssignee(): ?User
    {
        if ($this->assigneeId === '') {
            return null;
        }

        $assignee = $this->project->organization->users()
            ->where('users.id', $this->assigneeId)
            ->first();

        if ($assignee === null) {
            throw ValidationException::withMessages([
                'assigneeId' => __('That person is not a member of this organization.'),
            ]);
        }

        return $assignee;
    }

    /**
     * Reset the form back to its defaults.
     */
    private function resetForm(): void
    {
        $this->editingTaskId = null;
        $this->title = '';
        $this->description = '';
        $this->status = TaskStatus::Todo->value;
        $this->priority = TaskPriority::Medium->value;
        $this->dueDate = '';
        $this->assigneeId = '';
    }
}; ?>

<div data-test="project-tasks">
    <x-panel flush>
        <x-slot:title>
            <span class="flex items-center gap-2">
                {{ __('Tasks') }}

                <span class="tabular rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-white/10 dark:text-zinc-300" data-test="task-count">
                    {{ $this->taskCount }}
                </span>

                <span wire:loading wire:target="statusFilter" role="status">
                    <flux:icon.loading class="size-3.5 text-zinc-400" />
                    <span class="sr-only" data-test="tasks-loading">{{ __('Loading tasks') }}</span>
                </span>
            </span>
        </x-slot:title>

        <x-slot:action>
            {{-- The filter flexes and the action stays whole, so both fit on one
                 row once the panel header stacks on a phone. --}}
            <div class="flex w-full items-center gap-2 sm:w-auto">
                <flux:select
                    wire:model.live="statusFilter"
                    size="sm"
                    class="w-full sm:w-40"
                    :aria-label="__('Filter tasks by status')"
                    data-test="task-status-filter"
                >
                    <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>

                    @foreach (TaskStatus::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @can('create', [App\Models\Task::class, $project])
                    <flux:button
                        variant="primary"
                        size="sm"
                        icon="plus"
                        class="shrink-0"
                        wire:click="createTask"
                        data-test="new-task-button"
                    >
                        {{ __('New task') }}
                    </flux:button>
                @endcan
            </div>
        </x-slot:action>

        @error('tasks')
            <flux:callout variant="danger" icon="exclamation-triangle" class="m-4" data-test="tasks-error">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        @if ($this->tasks->isEmpty())
            <x-empty-state
                icon="clipboard-document-list"
                :heading="$this->taskCount === 0 ? __('No tasks yet.') : __('No tasks match this filter.')"
                :description="$this->taskCount === 0
                    ? __('Tasks break this project down into the work your team delivers, each with an owner, a priority, and a due date.')
                    : __('Try a different status, or clear the filter to see everything.')"
                data-test="tasks-empty-state"
            >
                @can('create', [App\Models\Task::class, $project])
                    @if ($this->taskCount === 0)
                        <x-slot:action>
                            <flux:button
                                variant="primary"
                                size="sm"
                                icon="plus"
                                wire:click="createTask"
                                data-test="empty-create-task"
                            >
                                {{ __('Create your first task') }}
                            </flux:button>
                        </x-slot:action>
                    @endif
                @endcan
            </x-empty-state>
        @else
            {{-- One list at every width. A six-column table is unreadable on a phone,
                 and duplicating rows for two breakpoints duplicates the DOM. --}}
            <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-test="task-list">
                @foreach ($this->tasks as $task)
                    <li class="wfh-row px-4 py-3" wire:key="task-{{ $task->id }}">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $task->title }}</p>

                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($task->assignee)
                                        <span class="flex items-center gap-1.5">
                                            <flux:avatar
                                                size="xs"
                                                :name="$task->assignee->name"
                                                :initials="$task->assignee->initials()"
                                            />
                                            <span class="truncate">{{ $task->assignee->name }}</span>
                                        </span>
                                    @else
                                        <span class="flex items-center gap-1">
                                            <flux:icon icon="user-minus" variant="outline" class="size-3.5" />
                                            {{ __('Unassigned') }}
                                        </span>
                                    @endif

                                    @if ($task->isOverdue())
                                        <span class="flex items-center gap-1 font-medium text-red-600 dark:text-red-400">
                                            <flux:icon icon="exclamation-triangle" variant="outline" class="size-3.5" />
                                            {{ trans_choice('{1} :count day late|[2,*] :count days late', $task->daysOverdue(), ['count' => $task->daysOverdue()]) }}
                                        </span>
                                    @elseif ($task->due_date)
                                        <span class="tabular flex items-center gap-1">
                                            <flux:icon icon="calendar-days" variant="outline" class="size-3.5" />
                                            {{ __('Due :date', ['date' => $task->due_date->format('M j, Y')]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <x-priority-badge :priority="$task->priority" />

                                <x-task-status-control :task="$task" />

                                @can('update', $task)
                                    <flux:button
                                        size="sm"
                                        variant="subtle"
                                        icon="pencil-square"
                                        wire:click="editTask({{ $task->id }})"
                                        :aria-label="__('Edit :task', ['task' => $task->title])"
                                        :data-test="'edit-task-'.$task->id"
                                    >
                                        {{ __('Edit') }}
                                    </flux:button>
                                @endcan
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>

    @can('create', [App\Models\Task::class, $project])
        <flux:modal name="task-form" class="max-w-lg" wire:close="cancelTaskForm">
            <form wire:submit="saveTask" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $editingTaskId === null ? __('New task') : __('Edit task') }}
                    </flux:heading>

                    <flux:subheading>
                        {{ __('Tasks belong to :project.', ['project' => $project->name]) }}
                    </flux:subheading>
                </div>

                <flux:input
                    wire:model="title"
                    :label="__('Title')"
                    type="text"
                    required
                    data-test="task-title-field"
                />

                <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="status" :label="__('Status')" data-test="task-status-field">
                        @foreach (TaskStatus::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="priority" :label="__('Priority')" data-test="task-priority-field">
                        @foreach (TaskPriority::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="dueDate" :label="__('Due date')" type="date" data-test="task-due-date-field" />

                    <flux:select wire:model="assigneeId" :label="__('Assignee')" data-test="task-assignee-field">
                        <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>

                        @foreach ($this->assignableMembers as $member)
                            <flux:select.option :value="(string) $member->id">{{ $member->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button variant="filled" type="button" wire:click="cancelTaskForm">
                        {{ __('Cancel') }}
                    </flux:button>

                    <flux:button
                        variant="primary"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="saveTask"
                        data-test="save-task-button"
                    >
                        {{ __('Save task') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
