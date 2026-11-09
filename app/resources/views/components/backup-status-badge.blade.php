@props(['status'])

@php
    $status = $status instanceof \App\Enums\BackupStatus ? $status : \App\Enums\BackupStatus::fromResult($status);
@endphp

<flux:badge size="sm" :color="$status->fluxColor()" {{ $attributes }}>{{ $status->label() }}</flux:badge>
