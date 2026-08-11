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
    <x-slot:meta>
        <flux:badge size="sm" inset="top bottom" icon="users">
            {{ trans_choice('{1} :count member|[2,*] :count members', $this->memberships->count(), ['count' => $this->memberships->count()]) }}
        </flux:badge>
    </x-slot:meta>

    <x-slot:actions>
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

    {{-- One list at every width: the email wraps under the name instead of
         forcing a fifth column that no phone can show. --}}
    <x-panel flush :title="__('Team')" :description="__('Everyone with access to this organization')" data-test="member-card-list">
        <ul class="divide-y divide-zinc-200 dark:divide-white/10">
            @foreach ($this->memberships as $membership)
                <li class="wfh-row flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4" wire:key="member-{{ $membership->id }}">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <flux:avatar
                            size="sm"
                            :name="$membership->user->name"
                            :initials="$membership->user->initials()"
                        />

                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $membership->user->name }}
                            </p>

                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $membership->user->email }}
                            </p>
                        </div>
                    </div>

                    <span class="tabular hidden shrink-0 text-xs whitespace-nowrap text-zinc-500 lg:block dark:text-zinc-400">
                        {{ __('Joined :date', ['date' => $membership->created_at->format('M j, Y')]) }}
                    </span>

                    <div class="flex shrink-0 items-center justify-between gap-2 sm:justify-end">
                        <x-member-role-control
                            :membership="$membership"
                            :assignable-roles="$this->assignableRoles"
                        />

                        @can('delete', $membership)
                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="trash"
                                wire:click="confirmRemoval({{ $membership->id }})"
                                :aria-label="__('Remove :name', ['name' => $membership->user->name])"
                                :data-test="'remove-member-'.$membership->id"
                            >
                                {{ __('Remove') }}
                            </flux:button>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    </x-panel>

    @can('viewAny', [App\Models\Invitation::class, $organization])
        {{-- Invitations are deliberately quieter than members: dashed edges and a
             muted ground mark them as not-yet-real people. --}}
        <x-panel
            flush
            class="mt-5 border-dashed bg-zinc-50/60 dark:bg-white/[0.02]"
            :title="__('Pending invitations')"
            :description="__('People who have been invited but have not joined yet')"
            data-test="pending-invitations"
        >
            @error('invitations')
                <flux:callout variant="danger" icon="exclamation-triangle" class="m-4" data-test="invitations-error">
                    <flux:callout.text>{{ $message }}</flux:callout.text>
                </flux:callout>
            @enderror

            @if ($this->invitations->isEmpty())
                <x-empty-state
                    icon="envelope"
                    :heading="__('No pending invitations.')"
                    :description="__('Invite a colleague by email and they will appear here until they accept. You choose the role they join with.')"
                    data-test="invitations-empty-state"
                >
                    @can('create', [App\Models\Invitation::class, $organization])
                        <x-slot:action>
                            <flux:modal.trigger name="invite-member">
                                <flux:button variant="primary" size="sm" icon="user-plus" data-test="empty-invite-member">
                                    {{ __('Invite a member') }}
                                </flux:button>
                            </flux:modal.trigger>
                        </x-slot:action>
                    @endcan
                </x-empty-state>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-white/10">
                    @foreach ($this->invitations as $invitation)
                        <li class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4" wire:key="invitation-{{ $invitation->id }}">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full border border-dashed border-zinc-300 text-zinc-400 dark:border-white/20"
                                    aria-hidden="true"
                                >
                                    <flux:icon icon="envelope" variant="outline" class="size-4" />
                                </span>

                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ $invitation->email }}
                                    </p>

                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ __('Invited by :name · expires :date', [
                                            'name' => $invitation->invitedBy?->name ?? __('Unknown'),
                                            'date' => $invitation->expires_at->format('M j, Y'),
                                        ]) }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                                <flux:badge size="sm" inset="top bottom" :color="$invitation->role->color()">
                                    {{ $invitation->role->label() }}
                                </flux:badge>

                                <flux:badge
                                    size="sm"
                                    inset="top bottom"
                                    :color="$invitation->status()->color()"
                                    :data-test="'invitation-status-'.$invitation->id"
                                >
                                    {{ $invitation->status()->label() }}
                                </flux:badge>

                                <flux:button
                                    size="sm"
                                    variant="subtle"
                                    icon="arrow-path"
                                    wire:click="resendInvitation({{ $invitation->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="resendInvitation({{ $invitation->id }})"
                                    :aria-label="__('Resend invitation to :email', ['email' => $invitation->email])"
                                    :data-test="'resend-invitation-'.$invitation->id"
                                >
                                    {{ __('Resend') }}
                                </flux:button>

                                <flux:button
                                    size="sm"
                                    variant="subtle"
                                    icon="x-circle"
                                    wire:click="confirmRevoke({{ $invitation->id }})"
                                    :aria-label="__('Revoke invitation to :email', ['email' => $invitation->email])"
                                    :data-test="'revoke-invitation-'.$invitation->id"
                                >
                                    {{ __('Revoke') }}
                                </flux:button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-panel>

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

                    <flux:button
                        variant="primary"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="sendInvitation"
                        data-test="send-invitation-button"
                    >
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

                    <flux:button
                        variant="danger"
                        wire:click="revokeInvitation"
                        wire:loading.attr="disabled"
                        wire:target="revokeInvitation"
                        data-test="confirm-revoke-invitation-button"
                    >
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

                <flux:button
                    variant="danger"
                    wire:click="removeMember"
                    wire:loading.attr="disabled"
                    wire:target="removeMember"
                    data-test="confirm-remove-member-button"
                >
                    {{ __('Remove member') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</x-pages::organizations.layout>
