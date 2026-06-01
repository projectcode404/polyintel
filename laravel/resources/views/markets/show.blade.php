@extends('layouts.app')

@section('title', Str::limit($market->question, 60))
@section('page-title', 'Market Detail')
@section('page-subtitle')
    <a href="{{ route('markets.index') }}" class="text-muted text-decoration-none">← Back to Markets</a>
@endsection

@section('content')

{{-- ================================================================
     ROW 1: Market Info Cards
================================================================ --}}
<div class="row row-deck row-cards mb-3">

    {{-- Market Question Card --}}
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-blue-lt">{{ $market->sub_category ?? 'crypto' }}</span>
                            <span class="badge {{ $market->status === 'active' ? 'bg-success' : 'bg-secondary' }}-lt">
                                {{ ucfirst($market->status) }}
                            </span>
                            @if($market->is_tracked)
                            <span class="badge bg-green-lt">Tracked</span>
                            @endif
                        </div>
                        <h3 class="mb-2">{{ $market->question }}</h3>
                        @if($market->description)
                        <p class="text-muted small mb-0">{{ Str::limit($market->description, 200) }}</p>
                        @endif
                    </div>
                    {{-- Current Probability Big Display --}}
                    <div class="text-center" style="min-width: 100px;">
                        @if($market->market_probability !== null)
                        @php $prob = round($market->market_probability * 100, 1); @endphp
                        <div class="display-5 fw-bold
                            {{ $prob > 60 ? 'text-success' : ($prob < 40 ? 'text-danger' : 'text-warning') }}">
                            {{ $prob }}%
                        </div>
                        <div class="text-muted small">YES probability</div>
                        @else
                        <div class="display-5 fw-bold text-muted">N/A</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Stats Card --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Market Stats</h3>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="text-muted small">Volume</div>
                        <div class="fw-bold">${{ number_format($market->volume_usd, 0) }}</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Liquidity</div>
                        <div class="fw-bold">${{ number_format($market->liquidity_usd, 0) }}</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Traders</div>
                        <div class="fw-bold">{{ number_format($market->num_traders) }}</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Snapshots</div>
                        <div class="fw-bold">{{ number_format($stats['snapshot_count']) }}</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Min Prob</div>
                        <div class="fw-bold text-danger">
                            {{ $stats['min_probability'] !== null ? $stats['min_probability'] . '%' : 'N/A' }}
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Max Prob</div>
                        <div class="fw-bold text-success">
                            {{ $stats['max_probability'] !== null ? $stats['max_probability'] . '%' : 'N/A' }}
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Expires</div>
                        <div class="fw-bold small">
                            {{ $market->end_date ? $market->end_date->format('Y-m-d H:i') . ' UTC' : 'N/A' }}
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Last Synced</div>
                        <div class="fw-bold small">
                            {{ $market->last_synced_at?->diffForHumans() ?? 'Never' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ================================================================
     ROW 2: Probability Chart
================================================================ --}}
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">Probability History</h3>
        <div class="card-options">
            <div class="btn-group btn-group-sm" id="chartRangeGroup">
                <button class="btn btn-outline-primary active" data-hours="24">24h</button>
                <button class="btn btn-outline-primary" data-hours="48">48h</button>
                <button class="btn btn-outline-primary" data-hours="168">7d</button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <canvas id="probabilityChart" style="height: 280px;"></canvas>
    </div>
</div>

{{-- ================================================================
     ROW 3: Snapshot History (AG Grid)
================================================================ --}}
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Snapshot History</h3>
        <div class="card-options">
            <span class="text-muted small" id="snapshotCount"></span>
        </div>
    </div>
    <div class="card-body p-0" style="height: 400px;">
        <div id="snapshotGrid" class="ag-theme-alpine w-100 h-100"></div>
    </div>
</div>

@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-theme-alpine.css">
@endpush

@push('scripts')
<script>
const MARKET_ID  = {{ $market->id }};
const CHART_URL  = `/api/markets/${MARKET_ID}/chart`;
const GRID_URL   = `/api/markets/${MARKET_ID}/snapshots/grid`;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

// ====================================================================
// Probability Chart
// ====================================================================
let probabilityChart = null;

function buildChart(data) {
    const ctx = document.getElementById('probabilityChart');
    if (probabilityChart) probabilityChart.destroy();

    probabilityChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label:           'YES Probability (%)',
                    data:            data.probabilityYes,
                    borderColor:     '#2fb344',
                    backgroundColor: 'rgba(47, 179, 68, 0.08)',
                    fill:            true,
                    tension:         0.3,
                    pointRadius:     2,
                    borderWidth:     2,
                },
                {
                    label:           'NO Probability (%)',
                    data:            data.probabilityNo,
                    borderColor:     '#e63946',
                    backgroundColor: 'rgba(230, 57, 70, 0.04)',
                    fill:            false,
                    tension:         0.3,
                    pointRadius:     2,
                    borderWidth:     1.5,
                    borderDash:      [5, 5],
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend:  { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y}%`
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxTicksLimit: 12, font: { size: 11 } }
                },
                y: {
                    min: 0, max: 100,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: { callback: v => v + '%' }
                }
            }
        }
    });
}

function loadChart(hours) {
    fetch(`${CHART_URL}?hours=${hours}`, {
        headers: { 'X-CSRF-TOKEN': CSRF_TOKEN }
    })
    .then(r => r.json())
    .then(data => {
        if (data.labels.length === 0) {
            document.getElementById('probabilityChart')
                .parentElement.innerHTML = '<div class="text-center text-muted py-5">No snapshot data yet</div>';
            return;
        }
        buildChart(data);
    });
}

// Chart range buttons
document.querySelectorAll('#chartRangeGroup button').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#chartRangeGroup button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        loadChart(this.dataset.hours);
    });
});

// Load initial chart
loadChart(24);

// ====================================================================
// Snapshot AG Grid
// ====================================================================
const snapshotColumnDefs = [
    { headerName: 'Time (UTC)',       field: 'snapshotted_at',  width: 175, sort: 'desc' },
    { headerName: 'YES Prob',         field: 'probability_yes', width: 100, cellStyle: { color: '#2fb344', fontWeight: 600 } },
    { headerName: 'NO Prob',          field: 'probability_no',  width: 100, cellStyle: { color: '#e63946', fontWeight: 600 } },
    { headerName: 'Best Bid',         field: 'best_bid',        width: 100 },
    { headerName: 'Best Ask',         field: 'best_ask',        width: 100 },
    { headerName: 'Spread',           field: 'spread',          width: 100 },
    { headerName: 'Volume 24h',       field: 'volume_24h_usd',  width: 120 },
    { headerName: 'Liquidity',        field: 'liquidity_usd',   width: 120 },
    { headerName: 'BTC Price',        field: 'btc_price_usd',   width: 110 },
    { headerName: 'ETH Price',        field: 'eth_price_usd',   width: 110 },
    { headerName: 'Fear & Greed',     field: 'fear_greed',      width: 110 },
];

const snapshotGridOptions = {
    columnDefs:              snapshotColumnDefs,
    rowModelType:            'infinite',
    cacheBlockSize:          50,
    infiniteInitialRowCount: 50,
    defaultColDef: { resizable: true, sortable: false, filter: false },
    onGridReady: params => {
        params.api.setGridOption('datasource', {
            getRows(p) {
                fetch(`${GRID_URL}?startRow=${p.startRow}&endRow=${p.endRow}`, {
                    headers: { 'X-CSRF-TOKEN': CSRF_TOKEN }
                })
                .then(r => r.json())
                .then(data => {
                    p.successCallback(data.rows, data.totalRows);
                    document.getElementById('snapshotCount').textContent =
                        Number(data.totalRows).toLocaleString() + ' snapshots';
                })
                .catch(() => p.failCallback());
            }
        });
    }
};

agGrid.createGrid(document.getElementById('snapshotGrid'), snapshotGridOptions);
</script>
@endpush
