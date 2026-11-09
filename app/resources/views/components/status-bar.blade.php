{{-- A row of coloured status blocks. Each item: ['status' => BackupStatus, 'title' => string, 'href' => ?string]. Kept minimal: it renders thousands of times. --}}
@props(['items'])
<div class="flex gap-0.5">@foreach ($items as $item)@if(!empty($item['href']))<a href="{{ $item['href'] }}" class="status-block" style="background:{{ $item['status']->color() }}" title="{{ $item['title'] }}"></a>@else<div class="status-block" style="background:{{ $item['status']->color() }}" title="{{ $item['title'] }}"></div>@endif @endforeach</div>
