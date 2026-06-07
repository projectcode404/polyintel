@extends('layouts.app')

@section('title', 'Paper Trades')
@section('page-title', 'Paper Trading')
@section('page-subtitle', 'Simulated portfolio — all times UTC')

@section('page-actions')
<div class="d-flex gap-2 align-items-center flex-wrap">

    {{-- Preset badge --}}
    @php
        $presetColor = match($settings->preset ?? 'balanced') {
            'conservative' => 'bg-teal-lt text-teal',
            'balanced'     => 'bg-blue-lt text-blue',
            'aggressive'   => 'bg-red-lt text-red',
            default        => 'bg-secondary-lt',
        };
    @endphp

    <a href="{{ route('paper-trades.settings') }}"
       class="badge {{ $presetColor }} text-decoration-none py-2 px-3"
       title="Open trading settings">
        {{ ucfirst($settings->preset ?? 'balanced') }} preset
    </a>

    {{-- Settings button --}}
    <a href="{{ route('paper-trades.settings') }}" class="btn btn-sm btn-outline-secondary">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24"
             viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
            <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
            <path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/>
            <circle cx="12" cy="12" r="3"/>
        </svg>
        Settings
    </a>

    {{-- Auto refresh indicator --}}
    <span class="text-muted small d-flex align-items-center gap-1" id="refreshIndicator">
        <span class="badge bg-success-lt" id="refreshDot">●</span>
        <span id="refreshCountdown">60s</span>
    </span>

    {{-- Auto Trade / Auto Close toggles --}}
    <form action="{{ route('paper-trades.settings') }}" method="POST"
          class="d-flex align-items-center gap-3 mb-0" id="paperTradeSettingsForm">
        @csrf
        <input type="hidden" name="is_auto_trade" id="is_auto_trade"
               value="{{ $account->is_auto_trade ? '1' : '0' }}">
        <input type="hidden" name="is_auto_close" id="is_auto_close"
               value="{{ $account->is_auto_close ? '1' : '0' }}">

        <div class="form-check form-switch m-0 d-flex align-items-center gap-1">
            <input class="form-check-input m-0" type="checkbox" id="autoTradeToggle"
                   {{ $account->is_auto_trade ? 'checked' : '' }} data-setting="is_auto_trade">
            <label class="form-check-label text-muted small" for="autoTradeToggle">Auto Trade</label>
        </div>
        <div class="form-check form-switch m-0 d-flex align-items-center gap-1">
            <input class="form-check-input m-0" type="checkbox" id="autoCloseToggle"
                   {{ $account->is_auto_close ? 'checked' : '' }} data-setting="is_auto_close">
            <label class="form-check-label text-muted small" for="autoCloseToggle">Auto Close</label>
        </div>
    </form>

</div>
@endsection

@section('content')

{{-- ================================================================
     ROW 1: Overview Cards
================================================================ --}}
@include('paper-trades.partials.overview-cards')

{{-- ================================================================
     ROW 2: Equity Curve Chart
================================================================ --}}
@include('paper-trades.partials.equity-chart')

{{-- ================================================================
     ROW 3: Active Trades (AG Grid)
================================================================ --}}
@include('paper-trades.partials.active-trades')

{{-- ================================================================
     ROW 4: Closed Trades (AG Grid)
================================================================ --}}
@include('paper-trades.partials.closed-trades')

{{-- ================================================================
     ROW 5: Recent Activity + Exit Breakdown
================================================================ --}}
@include('paper-trades.partials.recent-activity')

{{-- ================================================================
     ROW 6: Performance Analytics + Monthly PnL
================================================================ --}}
@include('paper-trades.partials.performance-metrics')

@endsection

@push('styles')
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-grid.css">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-theme-alpine.css">
@include('partials.ui.grid-styles')
@endpush

@push('scripts')
<script>
// ============================================================
// Auto-refresh: AJAX polling setiap 60 detik
// Update: overview cards, equity chart, timeline
// ============================================================
(function () {
    const INTERVAL = 60; // detik
    let countdown  = INTERVAL;
    let timer      = null;

    const dot       = document.getElementById('refreshDot');
    const countEl   = document.getElementById('refreshCountdown');

    function tick() {
        countdown--;
        if (countEl) countEl.textContent = countdown + 's';

        if (countdown <= 0) {
            countdown = INTERVAL;
            doRefresh();
        }
    }

    function doRefresh() {
        if (dot) dot.textContent = '⟳';

        fetch('/api/paper-trades/refresh', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(payload => {
            updateOverviewCards(payload.overview);

            // Trigger equity chart refresh
            window.dispatchEvent(new Event('paperTradeRefreshed'));

            // Timeline refresh
            if (typeof window.refreshTimeline === 'function') {
                window.refreshTimeline();
            }

            if (dot) dot.textContent = '●';
        })
        .catch(() => {
            if (dot) dot.textContent = '●';
        });
    }

    function updateOverviewCards(data) {
        if (!data) return;

        // Helper: update text dengan animasi flash
        function flash(id, text) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = text;
            el.classList.add('text-primary');
            setTimeout(() => el.classList.remove('text-primary'), 800);
        }

        const fmt = (n) => '$' + parseFloat(n).toLocaleString('en-US', { minimumFractionDigits: 2 });
        const pct  = (n) => parseFloat(n).toFixed(2) + '%';
        const sign = (n) => (parseFloat(n) >= 0 ? '+' : '');

        flash('card-portfolio-value', fmt(data.portfolio_value));
        flash('card-current-equity',  fmt(data.current_equity));
        flash('card-cash',            fmt(data.cash_available));
        flash('card-open-trades',     data.open_trades);
        flash('card-win-rate',        pct(data.win_rate));
        flash('card-total-pnl',       sign(data.total_pnl) + fmt(data.total_pnl));
        flash('card-profit-factor',   data.profit_factor >= 999 ? '∞' : data.profit_factor);
        flash('card-max-drawdown',    '-' + pct(data.max_drawdown));
        flash('card-exposure',        pct(data.exposure_percent));
    }

    // Start countdown
    timer = setInterval(tick, 1000);
})();

// ============================================================
// Auto Trade / Auto Close toggle → submit form
// ============================================================
(function () {
    document.querySelectorAll('[data-setting]').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            const setting = this.getAttribute('data-setting');
            const val     = this.checked ? '1' : '0';
            const hidden  = document.getElementById(setting);
            if (hidden) hidden.value = val;
            document.getElementById('paperTradeSettingsForm')?.submit();
        });
    });
})();
</script>
@endpush
