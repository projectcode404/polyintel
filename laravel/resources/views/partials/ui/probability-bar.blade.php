{{-- @param float|null $value Probability 0–1 --}}
@php
    $pct = $value !== null ? round($value * 1000) / 10 : null;
@endphp
@if($pct === null)
    <span class="text-meta">N/A</span>
@else
    @php
        $cls = $pct > 60 ? 'prob-high' : ($pct < 40 ? 'prob-low' : 'prob-mid');
        $barColor = $pct > 60 ? '#2fb344' : ($pct < 40 ? '#e63946' : '#f76707');
        $width = min(100, max(0, (int) round($value * 100)));
    @endphp
    <div class="d-flex align-items-center gap-2">
        <div style="width:60px;height:6px;background:#e9ecef;border-radius:3px;overflow:hidden;">
            <div style="width:{{ $width }}%;height:100%;border-radius:3px;background:{{ $barColor }};"></div>
        </div>
        <span class="{{ $cls }}">{{ $pct }}%</span>
    </div>
@endif
