<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas antialiased">
        <div class="flex min-h-svh flex-col">
            <header class="border-b border-hairline">
                <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-4">
                    <div class="flex items-center gap-2.5">
                        <span class="flex aspect-square size-8 items-center justify-center rounded-lg bg-accent-content text-accent-foreground">
                            <x-app-logo-icon class="size-4 fill-current text-white dark:text-black" />
                        </span>

                        <span class="font-semibold text-zinc-900 dark:text-white">
                            {{ config('app.name') }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        @auth
                            <flux:button :href="route('dashboard')" variant="primary" size="sm" wire:navigate>
                                {{ __('Dashboard') }}
                            </flux:button>
                        @else
                            <flux:button :href="route('login')" variant="ghost" size="sm" wire:navigate>
                                {{ __('Log in') }}
                            </flux:button>

                            <flux:button :href="route('register')" variant="primary" size="sm" wire:navigate>
                                {{ __('Get started') }}
                            </flux:button>
                        @endauth
                    </div>
                </div>
            </header>

            <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-16">
                <div class="max-w-2xl space-y-6">
                    <flux:heading size="xl" level="1" class="text-4xl! leading-tight! sm:text-5xl!">
                        {{ __('Run your projects and your team in one place.') }}
                    </flux:heading>

                    <flux:subheading class="text-lg!">
                        {{ __('WorkFlowHub is a multi-tenant platform for small companies and agencies to manage organizations, projects, tasks, and the people who deliver them.') }}
                    </flux:subheading>

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        @auth
                            <flux:button :href="route('dashboard')" variant="primary" icon-trailing="arrow-right" wire:navigate>
                                {{ __('Go to dashboard') }}
                            </flux:button>
                        @else
                            <flux:button :href="route('register')" variant="primary" icon-trailing="arrow-right" wire:navigate>
                                {{ __('Create an account') }}
                            </flux:button>

                            <flux:button :href="route('login')" variant="filled" wire:navigate>
                                {{ __('Log in') }}
                            </flux:button>
                        @endauth
                    </div>
                </div>

                {{-- Same panel chrome as the signed-in product, so the landing
                     page and the application read as one thing. --}}
                <div class="mt-16 grid gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['building-office-2', __('Organizations'), __('Belong to multiple organizations, each with its own members and roles.')],
                        ['users', __('Roles'), __('Invite teammates by email as an owner, manager, or employee. Roles are scoped per organization.')],
                        ['shield-check', __('Isolation'), __('Every organization\'s data stays scoped to its own members.')],
                    ] as [$icon, $title, $body])
                        <div class="wfh-panel space-y-2 p-4">
                            <flux:icon :icon="$icon" variant="outline" class="size-5 text-zinc-400 dark:text-zinc-500" />

                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>

                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </main>

            <footer class="border-t border-hairline">
                <div class="mx-auto w-full max-w-5xl px-6 py-6">
                    <flux:text class="text-xs">
                        &copy; {{ now()->year }} {{ config('app.name') }}
                    </flux:text>
                </div>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
