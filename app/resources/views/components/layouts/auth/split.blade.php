<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="bg-muted relative hidden h-full flex-col p-10 text-white lg:flex dark:border-e dark:border-neutral-800 overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-br from-blue-600 to-indigo-900"></div>
                <div class="absolute inset-0 bg-[url('images/splash.jpg')] bg-cover bg-center "></div>
                <div class="relative z-20 flex flex-col h-full">
                    <a href="{{ route('home') }}" class="flex items-center text-lg font-medium" wire:navigate>
                        <span class="flex h-20 w-20 items-center justify-center rounded-md">
                            <x-app-logo-icon class="me-2 h-7 fill-current text-white" />
                        </span>
                        Duplicati Central
                    </a>

                    <!-- Rest of your content -->

                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="flex h-10 w-20 items-center justify-center rounded-md">
                            <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                        </span>
                        <span class="text-center text-3xl">
                            Duplicati Central
                        </span>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
