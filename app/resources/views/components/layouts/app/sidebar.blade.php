<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('home') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                <x-app-logo />
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group :heading="__('Platform')" class="grid">
                    <flux:navlist.item icon="home" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Backups')" class="grid">
                    <flux:navlist.item icon="archive-box" :href="route('backup-results')" :current="request()->routeIs('backup-results')" wire:navigate>{{ __('Backup Results') }}</flux:navlist.item>
                    <flux:navlist.item icon="document-duplicate" :href="route('backups')" :current="request()->routeIs('backups')" wire:navigate>{{ __('Backup Management') }}</flux:navlist.item>
                    <flux:navlist.item icon="server-stack" :href="route('backup-servers.index')" :current="request()->routeIs('backup-servers.index')" wire:navigate>{{ __('Backup Servers') }}</flux:navlist.item>
                    @if (config('remote_control.enabled'))
                        <flux:navlist.item icon="command-line" :href="route('remote-control.index')" :current="request()->routeIs('remote-control.*')" wire:navigate>{{ __('Remote Control') }}</flux:navlist.item>
                    @endif
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Notifications')" class="grid">
                    <flux:navlist.item icon="bell" :href="route('notification-channels.index')" :current="request()->routeIs('notification-channels.*')" wire:navigate>{{ __('Notification Channels') }}</flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <livewire:failing-jobs-badge />

            {{-- Shown only when the browser can install the app (see resources/js/pwa.js). --}}
            <div x-data x-cloak x-show="$store.pwa?.canInstall">
                <flux:navlist variant="outline">
                    <flux:navlist.item icon="arrow-down-tray" href="#" x-on:click.prevent="$store.pwa.install()">{{ __('Install app') }}</flux:navlist.item>
                </flux:navlist>
            </div>

            <a href="https://github.com/burnacid/Duplicati-Cental/releases" target="_blank" rel="noopener"
               class="px-3 text-xs text-zinc-500 hover:underline dark:text-zinc-400" title="Release notes">
                Duplicati Central v{{ config('app.version') }}
            </a>

            <!-- Desktop User Menu -->
            <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <flux:modal name="ios-install" class="max-w-sm">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Install on iPhone or iPad') }}</flux:heading>
                <ol class="list-decimal space-y-2 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>{{ __('Open this page in Safari.') }}</li>
                    <li>{{ __('Tap the Share button') }} <flux:icon.arrow-up-on-square variant="micro" class="inline" />.</li>
                    <li>{{ __('Choose "Add to Home Screen" and tap Add.') }}</li>
                </ol>
            </div>
        </flux:modal>

        @fluxScripts

        @stack('scripts')
    </body>
</html>
