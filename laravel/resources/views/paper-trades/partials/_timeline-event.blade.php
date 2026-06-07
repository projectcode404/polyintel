{{-- Single timeline event row — dipakai di server-side render --}}
@php
$colorMap = [
    'blue' => '#206bc4', 'green' => '#2fb344', 'red' => '#e63946',
    'yellow' => '#f76707', 'purple' => '#ae3ec9', 'cyan' => '#17a2b8',
    'teal' => '#20c997', 'orange' => '#fd7e14', 'secondary' => '#6c757d',
];
$color = $colorMap[$event['color']] ?? $colorMap['secondary'];
@endphp

<div class="d-flex gap-3 px-3 py-2 border-bottom">
    <div class="flex-shrink-0 mt-1">
        <span class="avatar avatar-xs rounded"
              style="background:{{ $color }}20; color:{{ $color }};">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
            </svg>
        </span>
    </div>
    <div class="flex-grow-1 overflow-hidden">
        <div class="d-flex align-items-center gap-2">
            <span class="badge" style="background:{{ $color }}20; color:{{ $color }}; font-size:10px;">
                {{ $event['event_type'] }}
            </span>
            @if($event['pnl'] !== null)
                <span class="small {{ $event['pnl'] >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ $event['pnl'] >= 0 ? '+' : '' }}${{ number_format($event['pnl'], 2) }}
                </span>
            @endif
            <span class="text-muted small ms-auto text-nowrap">{{ $event['created_ago'] }}</span>
        </div>
        <div class="text-truncate small text-muted mt-1">{{ $event['market_short'] }}</div>
    </div>
</div>
