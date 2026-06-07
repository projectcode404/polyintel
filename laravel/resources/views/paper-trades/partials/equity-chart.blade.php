{{-- ============================================================
     Equity Curve Chart
     Variables: $equityCurve, $equitySummary
============================================================ --}}
<div class="row row-deck row-cards mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Equity Curve</h3>
                <div class="card-options d-flex align-items-center gap-3">
                    {{-- Summary stats --}}
                    <span class="text-muted small">
                        Peak: <strong class="text-body">${{ number_format($equitySummary['peak_equity'], 2) }}</strong>
                    </span>
                    <span class="text-muted small">
                        Return:
                        <strong class="{{ $equitySummary['total_return_pct'] >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $equitySummary['total_return_pct'] >= 0 ? '+' : '' }}{{ $equitySummary['total_return_pct'] }}%
                        </strong>
                    </span>
                    <span class="text-muted small">
                        Max DD: <strong class="text-danger">{{ $equitySummary['max_drawdown'] }}%</strong>
                    </span>
                    {{-- Mode toggle --}}
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary active" id="btnEquityDaily">Daily</button>
                        <button type="button" class="btn btn-outline-secondary" id="btnEquityPerTrade">Per Trade</button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div style="position: relative; height: 220px;">
                    <canvas id="equityCurveChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const initialData = @json($equityCurve);
    let equityChart   = null;

    function buildChart(data) {
        const ctx = document.getElementById('equityCurveChart');
        if (!ctx) return;

        if (equityChart) equityChart.destroy();

        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';

        equityChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Equity',
                        data: data.equity,
                        borderColor: '#206bc4',
                        backgroundColor: 'rgba(32, 107, 196, 0.08)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: data.equity.length > 60 ? 0 : 3,
                        yAxisID: 'yEquity',
                    },
                    {
                        label: 'Drawdown %',
                        data: data.drawdown,
                        borderColor: 'rgba(230, 57, 70, 0.7)',
                        backgroundColor: 'rgba(230, 57, 70, 0.08)',
                        borderWidth: 1.5,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                        borderDash: [4, 3],
                        yAxisID: 'yDrawdown',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.datasetIndex === 0) return ' Equity: $' + ctx.raw.toFixed(2);
                                return ' Drawdown: ' + ctx.raw.toFixed(2) + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 10, font: { size: 10 } },
                    },
                    yEquity: {
                        position: 'left',
                        grid: { color: gridColor },
                        ticks: {
                            callback: v => '$' + v.toLocaleString(),
                            font: { size: 10 },
                        },
                    },
                    yDrawdown: {
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            callback: v => v + '%',
                            font: { size: 10 },
                        },
                        max: 0,
                    },
                },
            },
        });
    }

    // Build initial chart
    buildChart(initialData);

    // Mode toggle: per trade vs daily
    document.getElementById('btnEquityDaily')?.addEventListener('click', function () {
        this.classList.add('active');
        document.getElementById('btnEquityPerTrade')?.classList.remove('active');
        fetchEquity('daily');
    });
    document.getElementById('btnEquityPerTrade')?.addEventListener('click', function () {
        this.classList.add('active');
        document.getElementById('btnEquityDaily')?.classList.remove('active');
        fetchEquity('per_trade');
    });

    function fetchEquity(mode) {
        fetch('/api/paper-trades/equity-curve?mode=' + mode, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => buildChart(data))
        .catch(console.error);
    }

    // Update chart saat auto-refresh (event custom dari index.blade.php)
    window.addEventListener('paperTradeRefreshed', function () {
        fetchEquity(
            document.getElementById('btnEquityPerTrade')?.classList.contains('active')
                ? 'per_trade' : 'daily'
        );
    });
})();
</script>
@endpush
