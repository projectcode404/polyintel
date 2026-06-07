{{-- ============================================================
     Overview Cards
     Variables: $overview (array dari PortfolioDashboardService)
============================================================ --}}
<div class="row row-deck row-cards mb-3" id="overviewCards">

    {{-- Portfolio Value --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="subheader flex-grow-1">Portfolio Value</div>
                    <span class="badge bg-blue-lt">Total</span>
                </div>
                <div class="h2 mb-0" id="card-portfolio-value">
                    ${{ number_format($overview['portfolio_value'], 2) }}
                </div>
                <div class="text-muted small mt-1">
                    Initial: ${{ number_format($overview['initial_capital'], 2) }}
                </div>
            </div>
        </div>
    </div>

    {{-- Current Equity --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="subheader flex-grow-1">Current Equity</div>
                    @php $roiClass = $overview['roi_percent'] >= 0 ? 'bg-success-lt text-success' : 'bg-danger-lt text-danger'; @endphp
                    <span class="badge {{ $roiClass }}">
                        {{ $overview['roi_percent'] >= 0 ? '+' : '' }}{{ $overview['roi_percent'] }}%
                    </span>
                </div>
                <div class="h2 mb-0" id="card-current-equity">
                    ${{ number_format($overview['current_equity'], 2) }}
                </div>
                <div class="text-muted small mt-1">
                    Unrealized: <span class="{{ $overview['unrealized_pnl'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $overview['unrealized_pnl'] >= 0 ? '+' : '' }}${{ number_format($overview['unrealized_pnl'], 2) }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Cash Available --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="subheader flex-grow-1">Cash Available</div>
                </div>
                <div class="h2 mb-0" id="card-cash">
                    ${{ number_format($overview['cash_available'], 2) }}
                </div>
                <div class="text-muted small mt-1">
                    Allocated: ${{ number_format($overview['allocated_capital'], 2) }}
                </div>
                <div class="progress progress-sm mt-2">
                    <div class="progress-bar bg-blue"
                         style="width: {{ min($overview['exposure_percent'], 100) }}%"
                         title="Exposure {{ $overview['exposure_percent'] }}%">
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Exposure --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="subheader flex-grow-1">Exposure</div>
                    @php
                        $expClass = $overview['exposure_percent'] > 70
                            ? 'bg-danger-lt text-danger'
                            : ($overview['exposure_percent'] > 40 ? 'bg-warning-lt text-warning' : 'bg-success-lt text-success');
                    @endphp
                    <span class="badge {{ $expClass }}" id="card-exposure">
                        {{ $overview['exposure_percent'] }}%
                    </span>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-6 text-center">
                        <div class="fw-bold" id="card-open-trades">{{ $overview['open_trades'] }}</div>
                        <div class="text-muted small">Open</div>
                    </div>
                    <div class="col-6 text-center">
                        <div class="fw-bold">{{ $overview['closed_trades'] }}</div>
                        <div class="text-muted small">Closed</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Win Rate --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader mb-2">Win Rate</div>
                <div class="d-flex align-items-end gap-2">
                    <div class="h2 mb-0 {{ $overview['win_rate'] >= 50 ? 'text-success' : 'text-danger' }}" id="card-win-rate">
                        {{ $overview['win_rate'] }}%
                    </div>
                </div>
                <div class="progress progress-sm mt-2">
                    <div class="progress-bar {{ $overview['win_rate'] >= 50 ? 'bg-success' : 'bg-danger' }}"
                         style="width: {{ $overview['win_rate'] }}%">
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Total PnL --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader mb-2">Total PnL</div>
                @php $pnlClass = $overview['total_pnl'] >= 0 ? 'text-success' : 'text-danger'; @endphp
                <div class="h2 mb-0 {{ $pnlClass }}" id="card-total-pnl">
                    {{ $overview['total_pnl'] >= 0 ? '+' : '' }}${{ number_format($overview['total_pnl'], 2) }}
                </div>
                <div class="text-muted small mt-1">
                    Realized: <span class="{{ $overview['realized_pnl'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $overview['realized_pnl'] >= 0 ? '+' : '' }}${{ number_format($overview['realized_pnl'], 2) }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Profit Factor --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader mb-2">Profit Factor</div>
                @php
                    $pfClass = $overview['profit_factor'] >= 1.5
                        ? 'text-success'
                        : ($overview['profit_factor'] >= 1.0 ? 'text-warning' : 'text-danger');
                @endphp
                <div class="h2 mb-0 {{ $pfClass }}" id="card-profit-factor">
                    {{ $overview['profit_factor'] === 999.0 ? '∞' : $overview['profit_factor'] }}
                </div>
                <div class="text-muted small mt-1">
                    &gt; 1.5 = good
                </div>
            </div>
        </div>
    </div>

    {{-- Max Drawdown --}}
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader mb-2">Max Drawdown</div>
                @php
                    $ddClass = $overview['max_drawdown'] > 20
                        ? 'text-danger'
                        : ($overview['max_drawdown'] > 10 ? 'text-warning' : 'text-success');
                @endphp
                <div class="h2 mb-0 {{ $ddClass }}" id="card-max-drawdown">
                    -{{ $overview['max_drawdown'] }}%
                </div>
                <div class="text-muted small mt-1">
                    &lt; 10% = healthy
                </div>
            </div>
        </div>
    </div>

</div>
