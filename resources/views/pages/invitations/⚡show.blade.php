<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Invitation')] class extends Component {
    /**
     * The raw invitation token from the URL. Locked so the browser cannot swap
     * it for another invitation on a subsequent request.
     */
    #[Locked]
    public string $token = '';

    /**
     * Mount the component.
     */
    public function mount(string $token): void
    {
        $this->token = $token;

        // Resolve once so an unknown token is a 404 rather than a rendered page.
        $this->resolveInvitation();
    }

    /**
     * Get the invitation this page is showing.
     */
    #[Computed]
    public function invitation(): Invitation
    {
        return $this->resolveInvitation();
    }

    /**
     * Get the invitation's current state.
     */
    #[Computed]
    public function status(): InvitationStatus
    {
        return $this->invitation->status();
    }

    /**
     * Determine whether the signed-in user owns this invitation.
     */
    #[Computed]
    public function emailMatches(): bool
    {
        return Auth::check() && $this->invitation->isFor(Auth::user()->email);
    }

    /**
     * Determine whether the signed-in user already belongs to the organization.
     */
    #[Computed]
    public function alreadyMember(): bool
    {
        return Auth::check()
            && Auth::user()->membershipFor($this->invitation->organization) !== null;
    }

    /**
     * Remember this invitation so login and registration return here afterwards.
     */
    public function rememberIntendedUrl(): void
    {
        session()->put('url.intended', route('invitations.show', $this->token));
    }

    /**
     * Accept the invitation for the authenticated user.
     */
    public function accept(AcceptInvitation $action): void
    {
        abort_unless(Auth::check(), 403);

        $user = Auth::user();

        abort_unless($user->hasVerifiedEmail(), 403);

        // Re-resolved from the token, never taken from the rendered page.
        $invitation = $this->resolveInvitation();

        $action->handle($invitation, $user);

        unset($this->invitation, $this->status, $this->alreadyMember);

        $this->redirect(
            route('organizations.dashboard', $invitation->organization),
            navigate: true,
        );
    }

    /**
     * Look the invitation up by the hash of the supplied token.
     */
    private function resolveInvitation(): Invitation
    {
        $invitation = Invitation::with(['organization', 'invitedBy'])
            ->where('token_hash', Invitation::hashToken($this->token))
            ->first();

        abort_if($invitation === null, 404);

        return $invitation;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex w-full flex-col gap-1 text-center">
        <flux:heading size="lg" level="1">{{ __('Organization invitation') }}</flux:heading>
        <flux:subheading>
            {{ __('You have been invited to join :organization', ['organization' => $this->invitation->organization->name]) }}
        </flux:subheading>
    </div>

    <flux:card class="space-y-3">
        <dl class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Organization') }}</flux:subheading></dt>
                <dd><flux:text class="truncate font-medium" data-test="invitation-organization">{{ $this->invitation->organization->name }}</flux:text></dd>
            </div>

            <div class="flex items-center justify-between gap-3">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Invited by') }}</flux:subheading></dt>
                <dd>
                    <flux:text class="truncate" data-test="invitation-inviter">
                        {{ $this->invitation->invitedBy?->name ?? __('A member of the organization') }}
                    </flux:text>
                </dd>
            </div>

            <div class="flex items-center justify-between gap-3">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Role') }}</flux:subheading></dt>
                <dd>
                    <flux:badge size="sm" inset="top bottom" data-test="invitation-role">
                        {{ $this->invitation->role->label() }}
                    </flux:badge>
                </dd>
            </div>

            <div class="flex items-center justify-between gap-3">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Invited address') }}</flux:subheading></dt>
                <dd><flux:text class="truncate font-mono text-xs" data-test="invitation-email">{{ $this->invitation->email }}</flux:text></dd>
            </div>

            <div class="flex items-center justify-between gap-3">
                <dt><flux:subheading class="text-xs uppercase tracking-wide">{{ __('Expires') }}</flux:subheading></dt>
                <dd><flux:text data-test="invitation-expires">{{ $this->invitation->expires_at->toFormattedDateString() }}</flux:text></dd>
            </div>
        </dl>
    </flux:card>

    @error('invitation')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="invitation-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($this->status === InvitationStatus::Expired)
        <flux:callout variant="warning" icon="clock" data-test="invitation-expired">
            <flux:callout.heading>{{ __('This invitation has expired.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Ask an organization owner or manager to send you a new one.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($this->status === InvitationStatus::Revoked)
        <flux:callout variant="danger" icon="x-circle" data-test="invitation-revoked">
            <flux:callout.heading>{{ __('This invitation has been revoked.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Ask an organization owner or manager if you think this is a mistake.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($this->status === InvitationStatus::Accepted)
        <flux:callout icon="check-circle" data-test="invitation-accepted">
            <flux:callout.heading>{{ __('This invitation has already been used.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('If that was you, sign in to reach the organization.') }}</flux:callout.text>
        </flux:callout>
    @elseif (! auth()->check())
        <div class="flex flex-col gap-3" data-test="invitation-guest-actions">
            <flux:text class="text-center text-sm">
                {{ __('Sign in or create an account with :email to accept.', ['email' => $this->invitation->email]) }}
            </flux:text>

            <flux:button
                :href="route('login')"
                variant="primary"
                class="w-full"
                wire:click="rememberIntendedUrl"
                data-test="invitation-login-link"
            >
                {{ __('Sign in to accept') }}
            </flux:button>

            <flux:button
                :href="route('register')"
                variant="filled"
                class="w-full"
                wire:click="rememberIntendedUrl"
                data-test="invitation-register-link"
            >
                {{ __('Create an account') }}
            </flux:button>
        </div>
    @elseif ($this->alreadyMember)
        <flux:callout icon="check-circle" data-test="invitation-already-member">
            <flux:callout.heading>{{ __('You are already a member.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Nothing to do here — head to the organization.') }}</flux:callout.text>
        </flux:callout>

        <flux:button
            :href="route('organizations.dashboard', $this->invitation->organization)"
            variant="primary"
            class="w-full"
            wire:navigate
        >
            {{ __('Go to :organization', ['organization' => $this->invitation->organization->name]) }}
        </flux:button>
    @elseif (! $this->emailMatches)
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="invitation-email-mismatch">
            <flux:callout.heading>{{ __('This invitation is for a different account.') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('It was sent to :invited, but you are signed in as :current.', [
                    'invited' => $this->invitation->email,
                    'current' => auth()->user()->email,
                ]) }}
            </flux:callout.text>
        </flux:callout>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button variant="filled" type="submit" class="w-full">
                {{ __('Sign out') }}
            </flux:button>
        </form>
    @elseif (! auth()->user()->hasVerifiedEmail())
        <flux:callout variant="warning" icon="envelope" data-test="invitation-unverified">
            <flux:callout.heading>{{ __('Verify your email first.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Confirm your email address, then come back to this invitation.') }}</flux:callout.text>
        </flux:callout>

        <flux:button :href="route('verification.notice')" variant="primary" class="w-full" wire:navigate>
            {{ __('Verify email address') }}
        </flux:button>
    @else
        <flux:button
            variant="primary"
            class="w-full"
            wire:click="accept"
            data-test="accept-invitation-button"
        >
            {{ __('Accept invitation') }}
        </flux:button>
    @endif
</div>
