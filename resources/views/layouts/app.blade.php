<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>
        {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
    </title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800 antialiased">
<flux:header container class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700">
    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

    <flux:brand :href="route('home')" name="CodexPicker">
        <x-slot name="logo" class="size-6 rounded-full bg-cyan-500 text-white text-xs font-bold">
            <flux:icon name="rocket-launch" variant="micro" />
        </x-slot>
    </flux:brand>

    <flux:navbar class="-mb-px max-lg:hidden">
        <flux:navbar.item :href="route('providers')" :current="request()->routeIs('providers')" wire:navigate>
            {{ __('Providers') }}
        </flux:navbar.item>
    </flux:navbar>

    <flux:spacer />

    <flux:navbar class="me-4">
        <flux:navbar.item icon="magnifying-glass" href="#" :label="__('Search')" />
    </flux:navbar>

    <flux:dropdown position="top" align="start">
        <flux:profile />

        <flux:menu>
            <flux:menu.separator />

            <flux:menu.item icon="arrow-right-start-on-rectangle">
                {{ __('Logout') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</flux:header>

<flux:sidebar sticky collapsible="mobile" class="lg:hidden bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
    <flux:sidebar.header>
        <flux:brand href="#" name="CodexPicker">
            <x-slot name="logo" class="size-6 rounded-full bg-cyan-500 text-white text-xs font-bold">
                <flux:icon name="rocket-launch" variant="micro" />
            </x-slot>
        </flux:brand>

        <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
    </flux:sidebar.header>

    <flux:sidebar.nav>
        <flux:sidebar.item icon="home" :current="request()->routeIs('dashboard')" wire:navigate>
            {{ __('Dashboard') }}
        </flux:sidebar.item>
    </flux:sidebar.nav>

    <flux:sidebar.spacer />
</flux:sidebar>

<flux:main container>
    {{ $slot }}
</flux:main>

<flux:footer class="border-t border-zinc-200 dark:border-zinc-700">
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 py-6">
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ __(':year :app. All rights reserved.', ['year' => now()->year, 'app' => config('app.name', 'Laravel')]) }}
        </p>

        <flux:navbar>
            <flux:navbar.item href="#">{{ __('Terms') }}</flux:navbar.item>
            <flux:navbar.item href="#">{{ __('Privacy') }}</flux:navbar.item>
        </flux:navbar>
    </div>
</flux:footer>

@persist('toast')
<flux:toast.group>
    <flux:toast />
</flux:toast.group>
@endpersist

@fluxScripts
</body>
</html>
