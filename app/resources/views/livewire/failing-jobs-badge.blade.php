<div wire:poll.120s hidden>
    {{-- The key changes with the count, so the element is replaced and x-init runs again. --}}
    <span wire:key="failing-jobs-{{ $count }}" x-data x-init="window.dcSetFailingJobs?.({{ $count }})"></span>
</div>
