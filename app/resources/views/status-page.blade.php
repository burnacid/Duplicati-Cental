@php
    $states = [
        'operational' => ['label' => __('Operational'), 'color' => 'green', 'dot' => 'bg-green-500'],
        'degraded' => ['label' => __('Warnings'), 'color' => 'amber', 'dot' => 'bg-amber-500'],
        'down' => ['label' => __('Problem'), 'color' => 'red', 'dot' => 'bg-red-500'],
    ];
    $banner = [
        'operational' => __('All backups are running normally.'),
        'degraded' => __('Some backups finished with warnings.'),
        'down' => __('Some backups failed or are overdue.'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <meta http-equiv="refresh" content="120" />
    <title>{{ $title }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-800 antialiased dark:bg-zinc-900 dark:text-zinc-100">
    <main class="mx-auto max-w-3xl px-4 py-10 sm:py-16">
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>

        @if($jobs->isEmpty())
            <flux:callout icon="information-circle" color="zinc" class="mt-6">
                <flux:callout.text>{{ __('No backups are shown on this page yet.') }}</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout :icon="$overall === 'operational' ? 'check-circle' : 'exclamation-triangle'" :color="$states[$overall]['color']" class="mt-6" data-overall="{{ $overall }}">
                <flux:callout.text>{{ $banner[$overall] }}</flux:callout.text>
            </flux:callout>

            <div class="mt-6 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                @foreach($jobs as $job)
                    <div class="p-4" data-state="{{ $job['state'] }}">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="size-2.5 shrink-0 rounded-full {{ $states[$job['state']]['dot'] }}" aria-hidden="true"></span>
                                <span class="truncate font-medium">{{ $job['name'] }}</span>
                            </div>
                            <flux:badge size="sm" :color="$states[$job['state']]['color']">{{ $states[$job['state']]['label'] }}</flux:badge>
                        </div>
                        <div class="mt-3 flex h-6 gap-0.5" aria-hidden="true">
                            @foreach($job['recent'] as $color)
                                <span class="flex-1 rounded-sm" style="background-color: {{ $color }}"></span>
                            @endforeach
                        </div>
                        <flux:text size="sm" class="mt-2">
                            {{ $job['last_backup_at'] ? __('Last backup :time', ['time' => $job['last_backup_at']->diffForHumans()]) : __('No backup yet') }}
                        </flux:text>
                    </div>
                @endforeach
            </div>
        @endif

        <flux:text size="sm" class="mt-8 text-center">
            {{ __('Updated :time', ['time' => now()->format('Y-m-d H:i T')]) }} · Duplicati Central
        </flux:text>
    </main>
</body>
</html>
