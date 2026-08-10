<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use Flux\Flux;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organization members')] class extends Component {
    /**
     * The route-bound organization. Locked so the browser can never swap the
     * tenant context on a subsequent request.
     */
    #[Locked]
    public Organization $organization;

    /**
     * The membership queued for removal. Locked, and always re-resolved through
     * the organization before it is acted upon.
     */
    #[Locked]
    public ?int $removingMembershipId = null;

    /**
     * Mount the component.
     */
    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);

        $this->organization = $organization;
    }

    /**
     * Get this organization's memberships, scoped through the relationship so no
     * other tenant's users can ever be reached.
     *
     * @return Collection<int, Membership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return $this->organization->memberships()->with('user')->oldest()->get();
    }

    /**
     * Get the roles that may be assigned to a membership.
     *
     * @return array<int, OrganizationRole>
     */
    #[Computed]
    public function assignableRoles(): array
    {
        return OrganizationRole::cases();
    }

    /**
     * Get the membership currently awaiting removal confirmation.
     */
    #[Computed]
    public function removingMembership(): ?Membership
    {
        if ($this->removingMembershipId === null) {
            return null;
        }

        return $this->organization->memberships()->with('user')->find($this->removingMembershipId);
    }

    /**
     * Change a member's role within this organization.
     */
    public function updateRole(int $membershipId, string $role): void
    {
        $membership = $this->resolveMembership($membershipId);

        $targetRole = OrganizationRole::tryFrom($role);

        if ($targetRole === null) {
            throw ValidationException::withMessages([
                'members' => __('That role does not exist.'),
            ]);
        }

        $this->ensureAllowed(Gate::inspect('updateRole', [$membership, $targetRole]));

        $membership->update(['role' => $targetRole]);

        unset($this->memberships);

        Flux::toast(variant: 'success', text: __('Member role updated.'));
    }

    /**
     * Queue a member for removal and open the confirmation modal.
     */
    public function confirmRemoval(int $membershipId): void
    {
        $membership = $this->resolveMembership($membershipId);

        $this->ensureAllowed(Gate::inspect('delete', $membership));

        $this->removingMembershipId = $membership->id;

        unset($this->removingMembership);

        Flux::modal('confirm-member-removal')->show();
    }

    /**
     * Abandon a queued removal.
     */
    public function cancelRemoval(): void
    {
        $this->removingMembershipId = null;

        unset($this->removingMembership);

        Flux::modal('confirm-member-removal')->close();
    }

    /**
     * Remove the queued member from this organization.
     */
    public function removeMember(): void
    {
        $membership = $this->resolveMembership($this->removingMembershipId);

        $this->ensureAllowed(Gate::inspect('delete', $membership));

        $membership->delete();

        $this->removingMembershipId = null;

        unset($this->memberships, $this->removingMembership);

        Flux::modal('confirm-member-removal')->close();

        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    /**
     * Re-resolve a membership through the route-bound organization, so a tampered
     * identifier can never reach a membership belonging to another tenant.
     */
    private function resolveMembership(?int $membershipId): Membership
    {
        return $this->organization->memberships()->findOrFail($membershipId);
    }

    /**
     * Turn a policy denial into a page-level error instead of a raw exception.
     */
    private function ensureAllowed(Response $response): void
    {
        if ($response->denied()) {
            throw ValidationException::withMessages([
                'members' => $response->message() ?: __('You are not allowed to manage this member.'),
            ]);
        }
    }
}; ?>

<x-pages::organizations.layout
    :organization="$organization"
    :heading="__('Members')"
    :subheading="__('People who belong to this organization')"
>
    <x-slot:actions>
        <flux:badge size="sm" inset="top bottom" icon="users">
            {{ trans_choice('{1} :count member|[2,*] :count members', $this->memberships->count(), ['count' => $this->memberships->count()]) }}
        </flux:badge>
    </x-slot:actions>

    @error('members')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" data-test="members-error">
            <flux:callout.heading>{{ __('That change was not applied') }}</flux:callout.heading>
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Joined') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->memberships as $membership)
                <flux:table.row :key="$membership->id">
                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            <flux:avatar
                                size="xs"
                                :name="$membership->user->name"
                                :initials="$membership->user->initials()"
                            />

                            <span class="truncate font-medium">{{ $membership->user->name }}</span>
                        </div>
                    </flux:table.cell>

                    <flux:table.cell class="text-zinc-500 dark:text-zinc-400">
                        {{ $membership->user->email }}
                    </flux:table.cell>

                    <flux:table.cell>
                        @php
                            $assignableForMember = collect($this->assignableRoles)
                                ->filter(fn (OrganizationRole $role) => Gate::allows('updateRole', [$membership, $role]));
                        @endphp

                        @if ($assignableForMember->isNotEmpty())
                            <flux:dropdown position="bottom" align="start">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon-trailing="chevron-down"
                                    :data-test="'change-role-'.$membership->id"
                                >
                                    {{ $membership->role->label() }}
                                </flux:button>

                                <flux:menu>
                                    @foreach ($assignableForMember as $role)
                                        <flux:menu.item
                                            wire:click="updateRole({{ $membership->id }}, '{{ $role->value }}')"
                                            :disabled="$role === $membership->role"
                                            :data-test="'assign-role-'.$membership->id.'-'.$role->value"
                                        >
                                            {{ $role->label() }}
                                        </flux:menu.item>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>
                        @else
                            <flux:badge
                                size="sm"
                                inset="top bottom"
                                :color="$membership->role === OrganizationRole::Owner ? 'lime' : 'zinc'"
                            >
                                {{ $membership->role->label() }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="whitespace-nowrap text-zinc-500 dark:text-zinc-400">
                        {{ $membership->created_at->toFormattedDateString() }}
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        @can('delete', $membership)
                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="trash"
                                wire:click="confirmRemoval({{ $membership->id }})"
                                :data-test="'remove-member-'.$membership->id"
                            >
                                {{ __('Remove') }}
                            </flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="confirm-member-removal" class="max-w-lg" wire:close="cancelRemoval">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Remove this member?') }}</flux:heading>

                <flux:subheading>
                    @if ($this->removingMembership)
                        {{ __(':name will immediately lose access to this organization. Their WorkFlowHub account is not deleted.', ['name' => $this->removingMembership->user->name]) }}
                    @endif
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="cancelRemoval">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="danger" wire:click="removeMember" data-test="confirm-remove-member-button">
                    {{ __('Remove member') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</x-pages::organizations.layout>
