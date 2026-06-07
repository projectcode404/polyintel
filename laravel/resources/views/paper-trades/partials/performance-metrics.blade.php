{{-- ============================================================
     Performance Metrics
     Variables: $performance (array dari PerformanceAnalyticsService)
                $monthlyPnl  (array chart data)
============================================================ --}}
<div class="row row-deck row-cards mb-3">

    {{-- Performance Stat Cards --}}
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Performance Analytics</h3>
            </div>
            <div class="card-body">

                @if($performance['total_trades'] === 0)
                <div class="text-center text-muted py-4">
                    No closed trades yet. Performance metrics will appear here.
                </div>
                @else

                <div class="row g-3">
                    {{-- Col 1 --}}
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Avg Win</div>
                            <div class="fw-bold text-success">+${{ number_format($performance['avg_win'], 2) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Avg Loss</div>
                            <div class="fw-bold text-danger">-${{ number_format($performance['avg_loss'], 2) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Largest Win</div>
                            <div class="fw-bold text-success">+${{ number_format($performance['largest_win'], 2) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Largest Loss</div>
                            <div class="fw-bold text-danger">-${{ number_format($performance['largest_loss'], 2) }}</div>
                        </div>
                    </div>

                    {{-- Col 2 --}}
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Profit Factor</div>
                            <div class="fw-bold {{ $performance['profit_factor'] >= 1.5 ? 'text-success' : ($performance['profit_factor'] >= 1 ? 'text-warning' : 'text-danger') }}">
                                {{ $performance['profit_factor'] >= 999 ? '∞' : $performance['profit_factor'] }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Expectancy</div>
                            <div class="fw-bold {{ $performance['expectancy'] >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $performance['expectancy'] >= 0 ? '+' : '' }}${{ number_format($performance['expectancy'], 2) }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Sharpe Ratio</div>
                            <div class="fw-bold {{ $performance['sharpe_ratio'] >= 1 ? 'text-success' : ($performance['sharpe_ratio'] >= 0 ? 'text-warning' : 'text-danger') }}">
                                {{ $performance['sharpe_ratio'] }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Avg Holding</div>
                            <div class="fw-bold">{{ $performance['avg_holding_hours'] }}h</div>
                        </div>
                    </div>

                    {{-- Streaks --}}
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Best Streak</div>
                            <div class="fw-bold text-success">{{ $performance['max_consecutive_wins'] }}W</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Worst Streak</div>
                            <div class="fw-bold text-danger">{{ $performance['max_consecutive_losses'] }}L</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Best ROI</div>
                            <div class="fw-bold text-success">+{{ $performance['best_roi_percent'] }}%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">Worst ROI</div>
                            <div class="fw-bold text-danger">{{ $performance['worst_roi_percent'] }}%</div>
                        </div>
                    </div>
                </div>

                @endif
            </div>
        </div>
    </div>

    {{-- Monthly PnL Chart --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Monthly PnL</h3>
            </div>
            <div class="card-body">
                @if(empty($monthlyPnl['labels']))
                <div class="text-center text-muted py-4 small">No monthly data yet</div>
                @else
                <div style="height: 220px;">
                    <canvas id="monthlyPnlChart"></canvas>
                </div>
                @endif
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
(function () {
    const ctx  = document.getElementById('monthlyPnlChart');
    const data = @json($monthlyPnl);
    if (!ctx || !data.labels?.length) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'PnL',
                data: data.pnlData,
                backgroundColor: data.colorData,
                borderRadius: 4,
                borderWidth: 0,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => (ctx.raw >= 0 ? '+' : '') + '$' + ctx.raw.toFixed(2)
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: {
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: {
                        callback: v => '$' + v,
                        font: { size: 10 },
                    }
                }
            }
        }
    });
})();
</script>
@endpush
