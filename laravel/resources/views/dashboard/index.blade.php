@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Polymarket Crypto Intelligence — All times UTC')

@section('content')

{{-- ================================================================
     ROW 1: Stat Cards
================================================================ --}}
<div class="row row-deck row-cards mb-3">

    {{-- Total Active Markets --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader">Active Markets</div>
                </div>
                <div class="h1 mb-3">{{ number_format($stats['active_markets']) }}</div>
                <div class="d-flex mb-2">
                    <div class="text-muted small">
                        <span class="badge bg-blue-lt me-1">₿ {{ number_format($stats['bitcoin_markets']) }}</span>
                        <span class="badge bg-purple-lt">Ξ {{ number_format($stats['ethereum_markets']) }}</span>
                    </div>
                </div>
                <div class="progress progress-sm">
                    <div class="progress-bar bg-primary"
                         style="width: {{ $stats['total_markets'] > 0 ? round($stats['active_markets'] / $stats['total_markets'] * 100) : 0 }}%">
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Total Snapshots --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Total Snapshots</div>
                <div class="h1 mb-3">{{ number_format($stats['total_snapshots']) }}</div>
                <div class="text-muted small mb-2">
                    <span class="text-green fw-bold">+{{ number_format($stats['snapshots_24h']) }}</span>
                    in last 24h
                </div>
                <div class="progress progress-sm">
                    <div class="progress-bar bg-green" style="width: 100%"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- BTC Price --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">BTC Price</div>
                <div class="h1 mb-3">
                    @if($stats['btc_price'])
                        ${{ number_format($stats['btc_price'], 0) }}
                    @else
                        <span class="text-muted">N/A</span>
                    @endif
                </div>
                <div class="text-muted small mb-2">
                    ETH:
                    @if($stats['eth_price'])
                        <span class="fw-bold">${{ number_format($stats['eth_price'], 0) }}</span>
                    @else
                        N/A
                    @endif
                </div>
                <div class="progress progress-sm">
                    <div class="progress-bar bg-orange" style="width: 75%"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Fear & Greed --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Fear & Greed Index</div>
                <div class="h1 mb-3">
                    @if($stats['fear_greed'] !== null)
                        @php
                            $fg = $stats['fear_greed'];
                            $fgLabel = match(true) {
                                $fg <= 25 => ['Extreme Fear', 'danger'],
                                $fg <= 45 => ['Fear', 'warning'],
                                $fg <= 55 => ['Neutral', 'secondary'],
                                $fg <= 75 => ['Greed', 'success'],
                                default   => ['Extreme Greed', 'green'],
                            };
                        @endphp
                        <span class="text-{{ $fgLabel[1] }}">{{ $fg }}</span>
                    @else
                        <span class="text-muted">N/A</span>
                    @endif
                </div>
                @if($stats['fear_greed'] !== null)
                <div class="text-muted small mb-2">
                    <span class="badge bg-{{ $fgLabel[1] }}-lt">{{ $fgLabel[0] }}</span>
                </div>
                <div class="progress progress-sm">
                    <div class="progress-bar bg-{{ $fgLabel[1] }}"
                         style="width: {{ $stats['fear_greed'] }}%">
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

</div>

{{-- ================================================================
     ROW 2: Charts
================================================================ --}}
<div class="row row-deck row-cards mb-3">

    {{-- Snapshot Activity Chart --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Snapshot Collection Activity (24h)</h3>
                <div class="card-options">
                    <span class="text-muted small">Snapshots per hour</span>
                </div>
            </div>
            <div class="card-body">
                <div id="snapshotActivityChart" style="height: 200px;"></div>
            </div>
        </div>
    </div>

    {{-- Sub-category Breakdown --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Markets by Category</h3>
            </div>
            <div class="card-body">
                <div id="subCategoryChart" style="height: 200px;"></div>
            </div>
        </div>
    </div>

</div>

{{-- ================================================================
     ROW 3: Probability Distribution + Expiring Soon
================================================================ --}}
<div class="row row-deck row-cards mb-3">

    {{-- Probability Distribution --}}
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Probability Distribution</h3>
                <div class="card-options">
                    <span class="text-muted small">Active markets</span>
                </div>
            </div>
            <div class="card-body">
                <div id="probabilityDistChart" style="height: 200px;"></div>
            </div>
        </div>
    </div>

    {{-- Expiring Soon --}}
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Expiring Soon</h3>
                <div class="card-options">
                    <span class="badge bg-warning-lt">{{ number_format($stats['expiring_soon']) }} markets in 48h</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-vcenter table-sm card-table">
                        <thead>
                            <tr>
                                <th>Market</th>
                                <th>Prob</th>
                                <th>Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topMarkets->where('end_date', '!=', null)->sortBy('end_date')->take(5) as $market)
                            <tr>
                                <td>
                                    <a href="{{ route('markets.show', $market->id) }}"
                                       class="text-reset text-decoration-none small"
                                       title="{{ $market->question }}">
                                        {{ Str::limit($market->question, 45) }}
                                    </a>
                                </td>
                                <td>
                                    <span class="fw-bold
                                        {{ $market->market_probability > 0.6 ? 'text-green' : ($market->market_probability < 0.4 ? 'text-red' : 'text-muted') }}">
                                        {{ $market->market_probability ? round($market->market_probability * 100, 1) . '%' : 'N/A' }}
                                    </span>
                                </td>
                                <td class="text-muted small text-nowrap">
                                    {{ $market->end_date?->format('M d H:i') }} UTC
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted small py-3">
                                    No markets expiring soon
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ================================================================
     ROW 4: Top Markets Table
================================================================ --}}
<div class="row row-deck row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Top Markets by Volume</h3>
                <div class="card-options">
                    <a href="{{ route('markets.index') }}" class="btn btn-sm btn-outline-primary">
                        View All Markets →
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Market</th>
                                <th>Category</th>
                                <th>Probability</th>
                                <th>Volume</th>
                                <th>Liquidity</th>
                                <th>Expires</th>
                                <th>Last Sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topMarkets as $market)
                            <tr>
                                <td>
                                    <a href="{{ route('markets.show', $market->id) }}"
                                       class="text-reset fw-medium text-decoration-none"
                                       title="{{ $market->question }}">
                                        {{ Str::limit($market->question, 60) }}
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-blue-lt">
                                        {{ $market->sub_category ?? 'crypto' }}
                                    </span>
                                </td>
                                <td>
                                    @if($market->market_probability !== null)
                                    @php $prob = round($market->market_probability * 100, 1); @endphp
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="flex-grow-1 probability-bar" style="width: 80px">
                                            <div class="probability-bar-fill
                                                {{ $prob > 60 ? 'bg-success' : ($prob < 40 ? 'bg-danger' : 'bg-warning') }}"
                                                 style="width: {{ $prob }}%">
                                            </div>
                                        </div>
                                        <span class="fw-bold small">{{ $prob }}%</span>
                                    </div>
                                    @else
                                    <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td class="text-muted">
                                    ${{ number_format($market->volume_usd, 0) }}
                                </td>
                                <td class="text-muted">
                                    ${{ number_format($market->liquidity_usd, 0) }}
                                </td>
                                <td class="text-muted small text-nowrap">
                                    {{ $market->end_date?->format('Y-m-d') ?? 'N/A' }}
                                </td>
                                <td class="text-muted small">
                                    {{ $market->last_synced_at?->diffForHumans() ?? 'Never' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
// ---- Snapshot Activity Chart ----
(function() {
    const data = @json($snapshotActivityChart);
    const ctx  = document.getElementById('snapshotActivityChart');
    if (!ctx || !data.labels.length) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels:   data.labels,
            datasets: [{
                label:           'Snapshots',
                data:            data.data,
                backgroundColor: 'rgba(32, 107, 196, 0.7)',
                borderColor:     'rgba(32, 107, 196, 1)',
                borderWidth:     1,
                borderRadius:    3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }
            }
        }
    });
})();

// ---- Sub-category Pie Chart ----
(function() {
    const data   = @json($subCategoryBreakdown);
    const ctx    = document.getElementById('subCategoryChart');
    if (!ctx || !data.labels.length) return;

    const colors = ['#206bc4','#ae3ec9','#2fb344','#f76707','#e63946','#4dabf7','#748ffc','#a9e34b'];

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels:   data.labels,
            datasets: [{
                data:            data.data,
                backgroundColor: colors.slice(0, data.labels.length),
                borderWidth:     2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
})();

// ---- Probability Distribution Chart ----
(function() {
    const data = @json($probabilityDistribution);
    const ctx  = document.getElementById('probabilityDistChart');
    if (!ctx || !data.labels.length) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels:   data.labels,
            datasets: [{
                label:           'Markets',
                data:            data.data,
                backgroundColor: data.labels.map((_, i) => {
                    if (i <= 2) return 'rgba(230, 57, 70, 0.7)';
                    if (i >= 7) return 'rgba(47, 179, 68, 0.7)';
                    return 'rgba(247, 103, 7, 0.7)';
                }),
                borderRadius: 3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }
            }
        }
    });
})();
</script>
@endpush
