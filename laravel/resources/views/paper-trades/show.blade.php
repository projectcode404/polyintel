@extends('layouts.app')

@section('title', 'Trade #' . $paperTrade->id)
@section('page-title', 'Trade Detail')
@section('page-subtitle', \Illuminate\Support\Str::limit($paperTrade->market?->question ?? 'Unknown Market', 80))

@section('page-actions')
<div class="d-flex gap-2">
    <a href="{{ route('paper-trades.index') }}" class="btn btn-sm btn-outline-secondary">
        ← Back
    </a>

    @if($paperTrade->isOpen())
    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#closeTradeModal">
        Close Position
    </button>
    @endif
</div>
@endsection

@section('content')

@php
    $statusMap = [
        'OPEN'        => 'bg-blue-lt text-blue',
        'PARTIAL'     => 'bg-yellow-lt text-yellow',
        'TAKE_PROFIT' => 'bg-success-lt text-success',
        'STOPPED'     => 'bg-danger-lt text-danger',
        'SMART_EXIT'  => 'bg-purple-lt text-purple',
        'EXPIRED'     => 'bg-orange-lt text-orange',
        'CLOSED'      => 'bg-secondary-lt',
    ];
    $statusClass = $statusMap[strtoupper($paperTrade->status)] ?? 'bg-secondary-lt';
@endphp

<div class="row">

    {{-- ============================================================
         COL LEFT: Trade Info + Exit Strategy + Metrics
    ============================================================ --}}
    <div class="col-12 col-lg-4">

        {{-- Trade Information --}}
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Trade Information</h3>
                <div class="card-options">
                    <span class="badge {{ $statusClass }}">{{ strtoupper($paperTrade->status) }}</span>
                </div>
            </div>
            <div class="card-body">
                {{-- Market question full-width on mobile --}}
                <div class="mb-3">
                    <div class="text-muted small mb-1">Market</div>
                    @if($paperTrade->market)
                        <a href="{{ route('markets.show', $paperTrade->market_id) }}"
                           class="text-reset text-decoration-none fw-medium small">
                            {{ \Illuminate\Support\Str::limit($paperTrade->market->question, 80) }}
                        </a>
                    @else
                        <span class="small">N/A</span>
                    @endif
                </div>

                {{-- Key stats in 2-col grid --}}
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <div class="text-muted small">Direction</div>
                        @if(strtoupper($paperTrade->direction) === 'YES')
                            <span class="badge bg-success-lt text-success">YES</span>
                        @else
                            <span class="badge bg-danger-lt text-danger">NO</span>
                        @endif
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Signal Score</div>
                        <div class="fw-medium small">
                            {{ $paperTrade->signal_score !== null ? round((float)$paperTrade->signal_score * 100, 1) . '%' : '—' }}
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Entry Price</div>
                        <div class="fw-bold">{{ round((float)$paperTrade->entry_price * 100, 2) }}%</div>
                    </div>
                    <div class="col-6">
                        @if($paperTrade->exit_price)
                            <div class="text-muted small">Exit Price</div>
                            <div class="fw-bold">{{ round((float)$paperTrade->exit_price * 100, 2) }}%</div>
                        @else
                            <div class="text-muted small">Current Price</div>
                            <div class="fw-bold">{{ round((float)($paperTrade->current_price ?? $paperTrade->entry_price) * 100, 2) }}%</div>
                        @endif
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Position Size</div>
                        <div class="small">${{ number_format($paperTrade->position_size_usd, 2) }}</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Shares</div>
                        <div class="small">{{ number_format($paperTrade->shares, 4) }}</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Opened</div>
                        <div class="small text-muted">{{ $paperTrade->entered_at?->format('m-d H:i') }} UTC</div>
                    </div>
                    @if($paperTrade->exited_at)
                    <div class="col-6">
                        <div class="text-muted small">Closed</div>
                        <div class="small text-muted">{{ $paperTrade->exited_at->format('m-d H:i') }} UTC</div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Exit Strategy --}}
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Exit Strategy</h3>
            </div>
            <div class="card-body">
                <div class="row g-2 text-center">
                    <div class="col-4">
                        <div class="text-muted small mb-1">Take Profit</div>
                        <div class="fw-bold text-success small">
                            {{ $paperTrade->take_profit_price ? round((float)$paperTrade->take_profit_price * 100, 2) . '%' : '—' }}
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small mb-1">Breakeven</div>
                        <div class="fw-bold text-cyan small">
                            {{ $paperTrade->breakeven_price ? round((float)$paperTrade->breakeven_price * 100, 2) . '%' : '—' }}
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small mb-1">Stop Loss</div>
                        <div class="fw-bold text-danger small">
                            {{ $paperTrade->stop_loss_price ? round((float)$paperTrade->stop_loss_price * 100, 2) . '%' : '—' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- PnL Metrics --}}
        <div class="card mb-3 mb-lg-0">
            <div class="card-header">
                <h3 class="card-title">Metrics</h3>
            </div>
            <div class="card-body">
                @if($paperTrade->isOpen())
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-muted small mb-1">Unrealized PnL</div>
                        <div class="h4 mb-0 {{ $unrealizedPnl >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $unrealizedPnl >= 0 ? '+' : '' }}${{ number_format($unrealizedPnl, 2) }}
                        </div>
                        <div class="text-muted small">
                            <span class="{{ $unrealizedRoi >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $unrealizedRoi >= 0 ? '+' : '' }}{{ number_format($unrealizedRoi, 2) }}%
                            </span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small mb-1">Holding</div>
                        <div class="fw-medium small">{{ $holdingDisplay }}</div>
                    </div>
                </div>
                @else
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-muted small mb-1">Realized PnL</div>
                        <div class="h4 mb-0 {{ (float)$paperTrade->pnl_usd >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ (float)$paperTrade->pnl_usd >= 0 ? '+' : '' }}${{ number_format($paperTrade->pnl_usd, 2) }}
                        </div>
                        <div class="text-muted small">
                            ROI: <span class="{{ (float)$paperTrade->roi >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ (float)$paperTrade->roi >= 0 ? '+' : '' }}{{ number_format((float)$paperTrade->roi * 100, 2) }}%
                            </span>
                        </div>
                    </div>
                    @if($paperTrade->holding_period_hours)
                    <div class="col-6">
                        <div class="text-muted small mb-1">Duration</div>
                        <div class="fw-medium small">{{ round($paperTrade->holding_period_hours, 1) }}h</div>
                    </div>
                    @endif
                </div>
                @endif

                @if($paperTrade->max_favorable_excursion || $paperTrade->max_adverse_excursion)
                <hr class="my-2">
                <div class="row g-2 text-center">
                    <div class="col-6">
                        <div class="text-muted small">MFE</div>
                        <div class="small text-success fw-bold">
                            +{{ round((float)($paperTrade->max_favorable_excursion ?? 0) * 100, 2) }}%
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">MAE</div>
                        <div class="small text-danger fw-bold">
                            {{ round((float)($paperTrade->max_adverse_excursion ?? 0) * 100, 2) }}%
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

    </div>{{-- /col-left --}}

    {{-- ============================================================
         COL RIGHT: History Timeline
    ============================================================ --}}
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Trade History</h3>
                <div class="card-options">
                    <span class="badge bg-secondary-lt">{{ $paperTrade->history->count() }} events</span>
                </div>
            </div>
            <div class="card-body p-0">
                @if($paperTrade->history->isEmpty())
                <div class="text-center text-muted py-5">No history events recorded.</div>
                @else

                {{-- Desktop table (hidden on mobile) --}}
                <div class="d-none d-md-block table-responsive">
                    <table class="table table-vcenter table-sm card-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Price</th>
                                <th>Shares</th>
                                <th>Realized PnL</th>
                                <th>Reason</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($paperTrade->history->sortBy('created_at') as $event)
                            @php
                                $evColorMap = [
                                    'OPENED'          => 'bg-blue-lt text-blue',
                                    'PARTIAL_CLOSE'   => 'bg-yellow-lt text-yellow',
                                    'TP1'             => 'bg-success-lt text-success',
                                    'TP2'             => 'bg-success-lt text-success',
                                    'TP3'             => 'bg-success-lt text-success',
                                    'STOP_LOSS'       => 'bg-danger-lt text-danger',
                                    'BREAKEVEN_MOVED' => 'bg-cyan-lt text-cyan',
                                    'SMART_EXIT'      => 'bg-purple-lt text-purple',
                                    'CLOSED'          => 'bg-secondary-lt',
                                    'EXPIRED'         => 'bg-orange-lt text-orange',
                                ];
                                $evClass = $evColorMap[$event->event_type] ?? 'bg-secondary-lt';
                            @endphp
                            <tr>
                                <td><span class="badge {{ $evClass }}">{{ $event->event_type }}</span></td>
                                <td class="fw-bold">
                                    {{ $event->price_at_event ? round((float)$event->price_at_event * 100, 2) . '%' : '—' }}
                                </td>
                                <td class="text-muted small">
                                    {{ $event->shares_affected ? number_format($event->shares_affected, 4) : '—' }}
                                </td>
                                <td>
                                    @if($event->pnl_realized !== null)
                                        <span class="{{ (float)$event->pnl_realized >= 0 ? 'text-success' : 'text-danger' }} fw-bold small">
                                            {{ (float)$event->pnl_realized >= 0 ? '+' : '' }}${{ number_format($event->pnl_realized, 2) }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-muted small">
                                    {{ $event->reason ? \Illuminate\Support\Str::limit($event->reason, 50) : '—' }}
                                </td>
                                <td class="text-muted small text-nowrap">
                                    {{ $event->created_at?->format('Y-m-d H:i') }} UTC
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile cards (hidden on desktop) --}}
                <div class="d-md-none">
                    @foreach($paperTrade->history->sortBy('created_at') as $event)
                    @php
                        $evColorMap = [
                            'OPENED'          => 'bg-blue-lt text-blue',
                            'PARTIAL_CLOSE'   => 'bg-yellow-lt text-yellow',
                            'TP1'             => 'bg-success-lt text-success',
                            'TP2'             => 'bg-success-lt text-success',
                            'TP3'             => 'bg-success-lt text-success',
                            'STOP_LOSS'       => 'bg-danger-lt text-danger',
                            'BREAKEVEN_MOVED' => 'bg-cyan-lt text-cyan',
                            'SMART_EXIT'      => 'bg-purple-lt text-purple',
                            'CLOSED'          => 'bg-secondary-lt',
                            'EXPIRED'         => 'bg-orange-lt text-orange',
                        ];
                        $evClass = $evColorMap[$event->event_type] ?? 'bg-secondary-lt';
                    @endphp
                    <div class="px-3 py-2 border-bottom">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="badge {{ $evClass }}">{{ $event->event_type }}</span>
                            <span class="text-muted" style="font-size:11px;">
                                {{ $event->created_at?->format('m-d H:i') }} UTC
                            </span>
                        </div>
                        <div class="d-flex gap-3 small">
                            <div>
                                <span class="text-muted">Price </span>
                                <span class="fw-bold">{{ $event->price_at_event ? round((float)$event->price_at_event * 100, 2) . '%' : '—' }}</span>
                            </div>
                            <div>
                                <span class="text-muted">PnL </span>
                                @if($event->pnl_realized !== null)
                                    <span class="{{ (float)$event->pnl_realized >= 0 ? 'text-success' : 'text-danger' }} fw-bold">
                                        {{ (float)$event->pnl_realized >= 0 ? '+' : '' }}${{ number_format($event->pnl_realized, 2) }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                        @if($event->reason)
                        <div class="text-muted mt-1" style="font-size:11px;">
                            {{ \Illuminate\Support\Str::limit($event->reason, 80) }}
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>

                @endif
            </div>
        </div>
    </div>

</div>{{-- /row --}}

{{-- ============================================================
     Modal: Close Trade
============================================================ --}}
@if($paperTrade->isOpen())
<div class="modal fade" id="closeTradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('paper-trades.close', $paperTrade->id) }}">
                @csrf
                <div class="modal-body pt-4 pb-3 px-4">
                    <h5 class="fw-bold mb-3">Close Position</h5>

                    <div class="bg-light rounded p-2 mb-3 small">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Direction</span>
                            <strong>{{ strtoupper($paperTrade->direction) }}</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Entry Price</span>
                            <strong>{{ round((float)$paperTrade->entry_price * 100, 2) }}%</strong>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small fw-medium mb-1">
                            Exit Price <span class="text-muted">(0 – 1)</span>
                        </label>
                        <input type="number" step="0.0001" min="0" max="1"
                               name="exit_price"
                               class="form-control form-control-sm"
                               value="{{ round((float)($paperTrade->current_price ?? $paperTrade->entry_price), 4) }}"
                               required>
                        <div class="text-muted mt-1" style="font-size:11px;">
                            Current market price for your side.
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-link link-secondary me-auto"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger px-3">
                        Confirm Close
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection
