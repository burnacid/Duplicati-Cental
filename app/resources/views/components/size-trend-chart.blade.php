@props(['points'])
@php($chart = new \App\Support\SizeTrendChart($points))
{{-- Source size and backup (destination) size per backup run. --}}
@if($chart->isEmpty())
    <p class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No backup runs with size information in this period.</p>
@else
    <svg viewBox="0 0 {{ \App\Support\SizeTrendChart::WIDTH }} {{ \App\Support\SizeTrendChart::HEIGHT }}" class="h-auto w-full" role="img" aria-label="Source and backup size over time">
        @foreach($chart->yTicks() as $tick)
            <line x1="{{ $chart->plotLeft() }}" x2="{{ $chart->plotRight() }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="1" />
            <text x="{{ $chart->plotLeft() - 8 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end" class="fill-zinc-500 dark:fill-zinc-400" font-size="11">{{ $tick['label'] }}</text>
        @endforeach
        @foreach($chart->xTicks() as $tick)
            <text x="{{ $tick['x'] }}" y="{{ $chart->plotBottom() + 18 }}" text-anchor="{{ $tick['anchor'] }}" class="fill-zinc-500 dark:fill-zinc-400" font-size="11">{{ $tick['label'] }}</text>
        @endforeach

        <path d="{{ $chart->path('source') }}" fill="none" stroke="#5CB4E8" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <path d="{{ $chart->path('backup') }}" fill="none" class="stroke-[#1E4E79] dark:stroke-[#E6F1FA]" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

        @php($markers = $chart->markers())
        @foreach($markers as $m)
            <g class="{{ count($markers) > 60 ? 'opacity-0 hover:opacity-100' : '' }}">
                <title>{{ $m['title'] }}</title>
                <circle cx="{{ $m['x'] }}" cy="{{ $m['ys'] }}" r="3" fill="#5CB4E8" />
                <circle cx="{{ $m['x'] }}" cy="{{ $m['yb'] }}" r="3" class="fill-[#1E4E79] dark:fill-[#E6F1FA]" />
            </g>
        @endforeach
    </svg>
    <div class="mt-2 flex flex-wrap gap-4 text-xs text-zinc-600 dark:text-zinc-300">
        <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-[#5CB4E8]"></span> Source size</span>
        <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-[#1E4E79] dark:bg-[#E6F1FA]"></span> Backup size (destination)</span>
    </div>
@endif
