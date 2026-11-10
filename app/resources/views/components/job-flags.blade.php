@props(['job'])
{{-- Overdue / snoozed / not-monitored badges for a job from BackupHealthService::jobs(). --}}
@if($job['is_overdue'])
    <flux:badge size="sm" color="zinc">Overdue</flux:badge>
@endif
@if($job['is_muted'])
    <flux:badge size="sm" color="zinc" icon="bell-slash" title="Alerts snoozed {{ \App\Support\Snooze::label($job['muted_until']) }}">Snoozed</flux:badge>
@endif
@if(! $job['monitored'])
    <flux:badge size="sm" color="zinc" icon="eye-slash" title="Missed backups are not checked for this job">Not monitored</flux:badge>
@endif
