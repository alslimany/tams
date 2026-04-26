<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <title>{{ $title ?? config('app.name') }}</title>
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800 font-sans antialiased">
        <flux:header sticky class="z-10 border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <a href="{{ route('flights.index') }}" class="flex items-center gap-2 mr-6" wire:navigate>
                <x-app-logo class="size-8" />
                <span class="text-xl font-bold text-zinc-900 dark:text-white">{{ config('app.name') }}</span>
            </a>

            <flux:navbar class="-mb-px hidden lg:flex">
                <flux:navbar.item href="{{ route('flights.index') }}" :current="request()->routeIs('flights.index')" wire:navigate>{{ __('Home') }}</flux:navbar.item>
                <flux:navbar.item href="#" :current="false" wire:navigate>{{ __('Products') }}</flux:navbar.item>
                <flux:navbar.item href="#" :current="false" wire:navigate>{{ __('About Us') }}</flux:navbar.item>
                <flux:navbar.item href="#" :current="false" wire:navigate>{{ __('Contacts') }}</flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <flux:navbar class="mr-4">
                <flux:navbar.item icon="magnifying-glass" href="#" label="{{ __('Search') }}" />
                <flux:navbar.item icon="bell" href="#" label="{{ __('Notifications') }}" />
                <flux:navbar.item icon="question-mark-circle" href="#" label="{{ __('Help') }}" />
            </flux:navbar>

            <x-desktop-user-menu :name="auth()->user()->name" />
        </flux:header>

        <main class="flex-1">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        </main>

        @fluxScripts
    </body>
</html>
