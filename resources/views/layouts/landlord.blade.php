<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <title>{{ $title ?? config('app.name') }} - Landlord</title>
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-900 font-sans antialiased">
        <flux:header sticky class="z-10 border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <a href="/" class="flex items-center gap-2 mr-6" wire:navigate>
                <x-app-logo class="size-8" />
                <span class="text-xl font-bold text-zinc-900 dark:text-white">{{ config('app.name') }}</span>
            </a>

            <flux:navbar class="-mb-px hidden lg:flex">
                <flux:navbar.item href="/" :current="request()->is('/')" wire:navigate>{{ __('Home') }}</flux:navbar.item>
                <flux:navbar.item href="/register-agency" :current="request()->is('register-agency')" wire:navigate>{{ __('Register Agency') }}</flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <flux:navbar class="mr-4">
                <flux:navbar.item icon="magnifying-glass" href="#" label="Search" />
                <flux:navbar.item icon="bell" href="#" label="Notifications" />
                <flux:navbar.item icon="question-mark-circle" href="#" label="Help" />
            </flux:navbar>

            @auth
                <x-desktop-user-menu :name="auth()->user()->name" />
            @else
                <flux:button href="{{ route('login') }}" variant="ghost" size="sm" wire:navigate>{{ __('Log in') }}</flux:button>
                <flux:button href="/register-agency" variant="primary" size="sm" class="ml-2" wire:navigate>{{ __('Register') }}</flux:button>
            @endauth
        </flux:header>

        <main class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
