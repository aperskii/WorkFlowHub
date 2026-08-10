<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Verify your email address')"
            :description="__('We sent a verification link to your inbox. Click it to activate your account.')"
        />

        @if (session('status') == 'verification-link-sent')
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.text>
                    {{ __('A new verification link has been sent to the email address you provided during registration.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-col gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" icon="envelope" class="w-full">
                    {{ __('Resend verification email') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button
                    variant="ghost"
                    type="submit"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
