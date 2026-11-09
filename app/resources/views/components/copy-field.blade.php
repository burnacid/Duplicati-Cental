{{-- Read-only input with a copy button. A light alternative to <flux:input copyable> for pages that render many of them. --}}
@props(['label', 'value'])
@php($id = 'copy-' . substr(md5($label . $value), 0, 10))
<div x-data="{ copied: false }" {{ $attributes->class('space-y-1') }}>
    <label for="{{ $id }}" class="block text-sm font-medium text-zinc-800 dark:text-white">{{ $label }}</label>
    <div class="flex">
        <input id="{{ $id }}" type="text" value="{{ $value }}" readonly x-ref="field"
               class="w-full min-w-0 rounded-l-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 dark:border-white/10 dark:bg-white/10 dark:text-zinc-300"
               x-on:focus="$el.select()">
        <button type="button"
                class="rounded-r-lg border border-l-0 border-zinc-200 px-3 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-white/10 dark:text-zinc-300 dark:hover:bg-white/10"
                x-on:click="navigator.clipboard.writeText($refs.field.value).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                x-text="copied ? 'Copied' : 'Copy'" aria-label="Copy {{ $label }}">Copy</button>
    </div>
</div>
