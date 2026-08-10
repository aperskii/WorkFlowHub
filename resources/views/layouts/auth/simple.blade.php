<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">
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
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </div>

            <flux:text class="text-center text-xs">
                {{ __('Multi-tenant project, team, and time management.') }}
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
