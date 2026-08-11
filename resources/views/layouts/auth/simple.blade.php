<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <a
                href="{{ route('home') }}"
                class="flex items-center gap-2.5 font-medium"
                wire:navigate
            >
                <span class="flex aspect-square size-9 items-center justify-center rounded-lg bg-accent-content text-accent-foreground">
                    <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
                </span>

                <span class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ config('app.name') }}
                </span>
            </a>

            <div class="w-full max-w-md">
                <div class="wfh-panel p-6 sm:p-8">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </div>

            <flux:text class="text-center text-xs">
                {{ __('Multi-tenant project and team management.') }}
            </flux:text>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
