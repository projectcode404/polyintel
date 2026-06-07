{{-- ============================================================
     Recent Activity Timeline
     Variables: $recentActivity (Collection dari PortfolioDashboardService)
============================================================ --}}
<div class="row row-deck row-cards mb-3">

    {{-- Timeline --}}
    <div class="col-lg-7">
        <div class="card" style="max-height: 420px; overflow-y: auto;">
            <div class="card-header">
                <h3 class="card-title">Recent Activity</h3>
                <div class="card-options">
                    <span class="text-muted small" id="timelineLastUpdated"></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="activityTimeline">
                    @forelse($recentActivity as $event)
                    @include('paper-trades.partials._timeline-event', ['event' => $event])
                    @empty
                    <div class="text-center text-muted py-5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg mb-2" width="40" height="40" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9"/>
                            <path d="M12 8v4l3 3"/>
                        </svg>
                        <div>No activity yet</div>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Smart Exit Stats --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Exit Breakdown</h3>
            </div>
            <div class="card-body">
                {{-- Stat rows --}}
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span class="d-flex align-items-center gap-2">
                        <span class="badge bg-success-lt">TP</span>
                        Take Profit
                    </span>
                    <strong class="text-success">{{ $smartExitStats['take_profit'] }}</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span class="d-flex align-items-center gap-2">
                        <span class="badge bg-danger-lt">SL</span>
                        Stop Loss
                    </span>
                    <strong class="text-danger">{{ $smartExitStats['stop_loss'] }}</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span class="d-flex align-items-center gap-2">
                        <span class="badge bg-purple-lt">SE</span>
                        Smart Exit
                    </span>
                    <strong class="text-purple">{{ $smartExitStats['smart_exit'] }}</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span class="d-flex align-items-center gap-2">
                        <span class="badge bg-orange-lt">EX</span>
                        Expired
                    </span>
                    <strong class="text-orange">{{ $smartExitStats['expired'] }}</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2">
                    <span class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary-lt">MC</span>
                        Manual Close
                    </span>
                    <strong>{{ $smartExitStats['manual_close'] }}</strong>
                </div>

                {{-- Pie chart --}}
                @if(array_sum($smartExitStats['chart']['data']) > 0)
                <div class="mt-3" style="height: 140px;">
                    <canvas id="exitPieChart"></canvas>
                </div>
                @endif
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
// Exit Pie Chart
(function () {
    const ctx  = document.getElementById('exitPieChart');
    const data = @json($smartExitStats['chart']);
    if (!ctx || !data.data.some(v => v > 0)) return;

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels:   data.labels,
            datasets: [{ data: data.data, backgroundColor: data.colors, borderWidth: 2 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } }
            }
        }
    });
})();

// AJAX Timeline Refresh
window.refreshTimeline = function () {
    fetch('/api/paper-trades/timeline?limit=20', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('activityTimeline');
        if (!container || !data.events?.length) return;

        container.innerHTML = data.events.map(ev => buildEventHtml(ev)).join('');
        document.getElementById('timelineLastUpdated').textContent =
            'Updated ' + new Date().toLocaleTimeString();
    })
    .catch(console.error);
};

function buildEventHtml(ev) {
    const colorMap = {
        blue: '#206bc4', green: '#2fb344', red: '#e63946',
        yellow: '#f76707', purple: '#ae3ec9', cyan: '#17a2b8',
        teal: '#20c997', orange: '#fd7e14', secondary: '#6c757d',
    };
    const color = colorMap[ev.color] || colorMap.secondary;
    const pnl   = ev.pnl != null
        ? `<span class="${ev.pnl >= 0 ? 'text-success' : 'text-danger'} ms-2 small">
               ${ev.pnl >= 0 ? '+' : ''}$${parseFloat(ev.pnl).toFixed(2)}
           </span>`
        : '';

    return `
        <div class="d-flex gap-3 px-3 py-2 border-bottom">
            <div class="flex-shrink-0 mt-1">
                <span class="avatar avatar-xs rounded" style="background:${color}20; color:${color};">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                    </svg>
                </span>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge" style="background:${color}20;color:${color};font-size:10px;">${ev.event_type}</span>
                    ${pnl}
                    <span class="text-muted small ms-auto text-nowrap">${ev.created_ago}</span>
                </div>
                <div class="text-truncate small text-muted mt-1">${ev.market_short}</div>
            </div>
        </div>
    `;
}
</script>
@endpush
