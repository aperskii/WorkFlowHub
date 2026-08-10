<?php

use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Actions\Invitations\SendInvitation;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use Flux\Flux;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
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
     * The invitation queued for revocation. Locked, and always re-resolved
     * through the organization before it is acted upon.
     */
    #[Locked]
    public ?int $revokingInvitationId = null;

    public string $inviteEmail = '';

    public string $inviteRole = '';

    /**
     * Mount the component.
     */
    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);

        $this->organization = $organization;
        $this->inviteRole = OrganizationRole::Employee->value;
    }

    /**
     * Get the organization's outstanding invitations.
     *
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        if (! Gate::allows('viewAny', [Invitation::class, $this->organization])) {
            return new Collection;
        }

        return $this->organization->invitations()
            ->outstanding()
            ->with('invitedBy')
            ->latest()
            ->get();
    }

    /**
     * Get the roles that may be granted through an invitation.
     *
     * @return array<int, OrganizationRole>
     */
    #[Computed]
    public function invitableRoles(): array
    {
        return OrganizationRole::invitable();
    }

    /**
     * Invite an email address into this organization.
     */
    public function sendInvitation(SendInvitation $action): void
    {
        $this->authorize('create', [Invitation::class, $this->organization]);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255'],
            'inviteRole' => ['required', 'string'],
        ]);

        $role = OrganizationRole::tryFrom($validated['inviteRole']);

        if ($role === null || ! $role->isInvitable()) {
            throw ValidationException::withMessages([
                'inviteRole' => __('That role cannot be granted through an invitation.'),
            ]);
        }

        try {
            $action->handle($this->organization, Auth::user(), $validated['inviteEmail'], $role);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'inviteEmail' => $exception->validator->errors()->first(),
            ]);
        }

        $this->reset('inviteEmail');
        $this->inviteRole = OrganizationRole::Employee->value;

        unset($this->invitations);

        Flux::modal('invite-member')->close();

        Flux::toast(variant: 'success', text: __('Invitation sent.'));
    }

    /**
     * Resend an invitation, rotating its token.
     */
    public function resendInvitation(int $invitationId, ResendInvitation $action): void
    {
        $invitation = $this->resolveInvitation($invitationId);

        $this->authorize('resend', $invitation);

        $action->handle($invitation, Auth::user());

        unset($this->invitations);

        Flux::toast(variant: 'success', text: __('Invitation resent with a new link.'));
    }

    /**
     * Queue an invitation for revocation and open the confirmation modal.
     */
    public function confirmRevoke(int $invitationId): void
    {
        $invitation = $this->resolveInvitation($invitationId);

        $this->authorize('revoke', $invitation);

        $this->revokingInvitationId = $invitation->id;

        unset($this->revokingInvitation);

        Flux::modal('confirm-invitation-revoke')->show();
    }

    /**
     * Abandon a queued revocation.
     */
    public function cancelRevoke(): void
    {
        $this->revokingInvitationId = null;

        unset($this->revokingInvitation);

        Flux::modal('confirm-invitation-revoke')->close();
    }

    /**
     * Revoke the queued invitation.
     */
    public function revokeInvitation(RevokeInvitation $action): void
    {
        $invitation = $this->resolveInvitation($this->revokingInvitationId);

        $this->authorize('revoke', $invitation);

        $action->handle($invitation);

        $this->revokingInvitationId = null;

        unset($this->invitations, $this->revokingInvitation);

        Flux::modal('confirm-invitation-revoke')->close();

        Flux::toast(variant: 'success', text: __('Invitation revoked.'));
    }

    /**
     * Get the invitation awaiting revocation confirmation.
     */
    #[Computed]
    public function revokingInvitation(): ?Invitation
    {
        if ($this->revokingInvitationId === null) {
            return null;
        }

        return $this->organization->invitations()->find($this->revokingInvitationId);
    }

    /**
     * Re-resolve an invitation through the route-bound organization, so a
     * tampered identifier can never reach another tenant's invitation.
     */
    private function resolveInvitation(?int $invitationId): Invitation
    {
        return $this->organization->invitations()->findOrFail($invitationId);
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

        @can('create', [App\Models\Invitation::class, $organization])
            <flux:modal.trigger name="invite-member">
                <flux:button variant="primary" size="sm" icon="user-plus" data-test="invite-member-button">
                    {{ __('Invite member') }}
                </flux:button>
            </flux:modal.trigger>
        @endcan
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

    @can('viewAny', [App\Models\Invitation::class, $organization])
        <div class="mt-10" data-test="pending-invitations">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('Pending invitations') }}</flux:heading>
                    <flux:subheading>{{ __('People who have been invited but have not joined yet') }}</flux:subheading>
                </div>
            </div>

            @error('invitations')
                <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" data-test="invitations-error">
                    <flux:callout.text>{{ $message }}</flux:callout.text>
                </flux:callout>
            @enderror

            @if ($this->invitations->isEmpty())
                <flux:callout icon="envelope" data-test="invitations-empty-state">
                    <flux:callout.text>{{ __('No pending invitations.') }}</flux:callout.text>
                </flux:callout>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Email') }}</flux:table.column>
                        <flux:table.column>{{ __('Role') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Invited by') }}</flux:table.column>
                        <flux:table.column>{{ __('Expires') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->invitations as $invitation)
                            <flux:table.row :key="$invitation->id">
                                <flux:table.cell class="font-medium">{{ $invitation->email }}</flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">{{ $invitation->role->label() }}</flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge
                                        size="sm"
                                        inset="top bottom"
                                        :color="$invitation->status()->color()"
                                        :data-test="'invitation-status-'.$invitation->id"
                                    >
                                        {{ $invitation->status()->label() }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell class="text-zinc-500 dark:text-zinc-400">
                                    {{ $invitation->invitedBy?->name ?? __('Unknown') }}
                                </flux:table.cell>

                                <flux:table.cell class="whitespace-nowrap text-zinc-500 dark:text-zinc-400">
                                    {{ $invitation->expires_at->toFormattedDateString() }}
                                </flux:table.cell>

                                <flux:table.cell align="end">
                                    <div class="flex items-center justify-end gap-2">
                                        <flux:button
                                            size="sm"
                                            variant="subtle"
                                            icon="arrow-path"
                                            wire:click="resendInvitation({{ $invitation->id }})"
                                            :data-test="'resend-invitation-'.$invitation->id"
                                        >
                                            {{ __('Resend') }}
                                        </flux:button>

                                        <flux:button
                                            size="sm"
                                            variant="subtle"
                                            icon="x-circle"
                                            wire:click="confirmRevoke({{ $invitation->id }})"
                                            :data-test="'revoke-invitation-'.$invitation->id"
                                        >
                                            {{ __('Revoke') }}
                                        </flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>

        <flux:modal name="invite-member" class="max-w-lg">
            <form wire:submit="sendInvitation" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Invite a member') }}</flux:heading>
                    <flux:subheading>
                        {{ __('They will receive an email with a link to join :organization.', ['organization' => $organization->name]) }}
                    </flux:subheading>
                </div>

                <flux:input
                    wire:model="inviteEmail"
                    :label="__('Email address')"
                    type="email"
                    required
                    autocomplete="off"
                    placeholder="teammate@example.com"
                    data-test="invite-email-field"
                />

                <flux:select wire:model="inviteRole" :label="__('Role')" data-test="invite-role-field">
                    @foreach ($this->invitableRoles as $role)
                        <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:text class="text-xs">
                    {{ __('Owners can only be promoted from the members list, never invited directly.') }}
                </flux:text>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" data-test="send-invitation-button">
                        {{ __('Send invitation') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="confirm-invitation-revoke" class="max-w-lg" wire:close="cancelRevoke">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Revoke this invitation?') }}</flux:heading>

                    <flux:subheading>
                        @if ($this->revokingInvitation)
                            {{ __('The link sent to :email will stop working immediately.', ['email' => $this->revokingInvitation->email]) }}
                        @endif
                    </flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button variant="filled" wire:click="cancelRevoke">{{ __('Cancel') }}</flux:button>

                    <flux:button variant="danger" wire:click="revokeInvitation" data-test="confirm-revoke-invitation-button">
                        {{ __('Revoke invitation') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

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
