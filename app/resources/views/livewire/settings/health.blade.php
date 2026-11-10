@php
    $colors = ['ok' => 'green', 'warning' => 'amber', 'error' => 'red', 'off' => 'zinc'];
    $labels = ['ok' => __('OK'), 'warning' => __('Warning'), 'error' => __('Problem'), 'off' => __('Off')];
@endphp

<section class="w-full" wire:poll.30s>
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Health')" :subheading="__('Status of this installation and its background processes')">
        @if ($overall === 'error')
            <flux:callout icon="exclamation-triangle" variant="danger" class="mb-6">
                <flux:callout.text>{{ __('Something needs attention: alerts or remote control may not work.') }}</flux:callout.text>
            </flux:callout>
        @elseif ($overall === 'warning')
            <flux:callout icon="exclamation-circle" variant="warning" class="mb-6">
                <flux:callout.text>{{ __('Mostly fine, but check the items marked Warning.') }}</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout icon="check-circle" variant="success" class="mb-6">
                <flux:callout.text>{{ __('Everything is running.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($checks as $check)
                <div class="flex items-start justify-between gap-4 p-4" wire:key="health-{{ $check['key'] }}" data-check="{{ $check['key'] }}" data-status="{{ $check['status'] }}">
                    <div class="min-w-0">
                        <flux:heading size="sm">{{ $check['label'] }}</flux:heading>
                        <flux:text class="mt-1">{{ $check['summary'] }}</flux:text>
                        @if ($check['detail'])
                            <flux:text size="sm" class="mt-1 break-words text-zinc-500 dark:text-zinc-400">{{ $check['detail'] }}</flux:text>
                        @endif
                    </div>
                    <flux:badge size="sm" :color="$colors[$check['status']]" class="shrink-0">{{ $labels[$check['status']] }}</flux:badge>
                </div>
            @endforeach
        </div>

        <flux:text size="sm" class="mt-4 text-zinc-500 dark:text-zinc-400">{{ __('Refreshes every 30 seconds.') }}</flux:text>
    </x-settings.layout>
</section>
