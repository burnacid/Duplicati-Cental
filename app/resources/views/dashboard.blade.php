<x-layouts.app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <livewire:dashboard.statistics />

        <livewire:dashboard.backup-status />
    </div>
</x-layouts.app>
