@extends('layouts.app')

@section('title', 'Paper Trades')
@section('page-title', 'Paper Trades')
@section('page-subtitle', 'Simulated portfolio execution — all times UTC')

@section('page-actions')
<div class="d-flex gap-2 align-items-center flex-wrap">
    <span class="text-muted small d-flex align-items-center">
        Balance: <strong class="text-dark ms-1">${{ number_format($account->balance, 2) }}</strong>
    </span>
    <select id="filterStatus" class="form-select form-select-sm" style="width: auto">
        <option value="">All Status</option>
        <option value="open" selected>Open</option>
        <option value="closed">Closed</option>
    </select>
    <form action="{{ route('paper-trades.settings') }}" method="POST" class="d-flex align-items-center gap-3 mb-0" id="paperTradeSettingsForm">
        @csrf
        <input type="hidden" name="is_auto_trade" id="is_auto_trade" value="{{ $account->is_auto_trade ? '1' : '0' }}">
        <input type="hidden" name="is_auto_close" id="is_auto_close" value="{{ $account->is_auto_close ? '1' : '0' }}">
        <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" value="1"
                   {{ $account->is_auto_trade ? 'checked' : '' }}
                   data-setting="is_auto_trade" aria-controls="is_auto_trade">
            <span class="form-check-label text-muted small">Auto Trade</span>
        </label>
        <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" value="1"
                   {{ $account->is_auto_close ? 'checked' : '' }}
                   data-setting="is_auto_close" aria-controls="is_auto_close">
            <span class="form-check-label text-muted small">Auto Close</span>
        </label>
    </form>
    <span class="text-muted small d-flex align-items-center" id="tableRowCount"></span>
</div>
@endsection

@section('content')
@component('partials.ui.data-table-card', ['tableId' => 'paperTradesTable'])
    <thead>
        <tr>
            <th>Market Question</th>
            <th>Signal Source</th>
            <th>Entry</th>
            <th>Shares</th>
            <th>Size (USD)</th>
            <th>Current / Exit</th>
            <th>PnL</th>
            <th>ROI</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        @forelse($trades as $trade)
        <tr data-status="{{ $trade->status }}">
            <td>
                <a href="{{ route('markets.show', $trade->market_id) }}" class="market-link" title="{{ $trade->market->question }}">
                    {{ Str::limit($trade->market->question, 48) }}
                </a>
            </td>
            <td>
                @if($trade->signal)
                    <span class="badge-rule">{{ $trade->signal->trigger_source }}</span>
                @else
                    <span class="badge-other">Manual</span>
                @endif
            </td>
            <td>
                <div class="fw-medium {{ $trade->direction === 'yes' ? 'prob-high' : 'prob-low' }}">
                    {{ strtoupper($trade->direction) }}
                </div>
                @include('partials.ui.probability-bar', ['value' => $trade->entry_price])
                <div class="text-meta">{{ $trade->entered_at->utc()->format('M d, H:i') }}</div>
            </td>
            <td>{{ number_format($trade->shares, 2) }}</td>
            <td>${{ number_format($trade->position_size_usd, 2) }}</td>
            <td>
                @if($trade->status === 'open')
                    @include('partials.ui.probability-bar', ['value' => $trade->current_price])
                    <div class="text-meta">Current</div>
                @else
                    @include('partials.ui.probability-bar', ['value' => $trade->exit_price])
                    <div class="text-meta">Exit</div>
                @endif
            </td>
            <td>
                @if($trade->status === 'open')
                    <span class="{{ $trade->unrealized_pnl_usd >= 0 ? 'edge-positive' : 'edge-negative' }}">
                        ${{ number_format($trade->unrealized_pnl_usd, 2) }}
                        <span class="text-meta fw-normal">(U)</span>
                    </span>
                @else
                    <span class="{{ $trade->pnl_usd >= 0 ? 'edge-positive' : 'edge-negative' }}">
                        {{ $trade->pnl_formatted }}
                    </span>
                @endif
            </td>
            <td>
                @if($trade->status === 'closed')
                    <span class="{{ $trade->roi >= 0 ? 'edge-positive' : 'edge-negative' }}">
                        {{ $trade->roi_percent }}
                    </span>
                @else
                    <span class="text-meta">—</span>
                @endif
            </td>
            <td>
                @if($trade->status === 'open')
                    <span class="badge bg-primary-lt">Open</span>
                @else
                    <span class="badge bg-secondary-lt">Closed ({{ $trade->outcome }})</span>
                @endif
            </td>
            <td>
                @if($trade->status === 'open')
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#closeTradeModal{{ $trade->id }}">
                    Close
                </button>

                <div class="modal modal-blur fade" id="closeTradeModal{{ $trade->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
                        <div class="modal-content">
                            <form action="{{ route('paper-trades.close', $trade->id) }}" method="POST">
                                @csrf
                                <div class="modal-body">
                                    <div class="modal-title">Close Position</div>
                                    <div class="text-muted">Enter the current market price for your side (0 to 1).</div>
                                    <div class="mt-3">
                                        <label class="form-label">Exit Price (0 – 1)</label>
                                        <input type="number" step="0.0001" min="0" max="1" name="exit_price"
                                               class="form-control form-control-sm" value="{{ $trade->current_price }}" required>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-danger btn-sm">Confirm Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                @else
                <span class="text-meta">—</span>
                @endif
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="10" class="text-center text-meta py-5">No paper trades yet.</td>
        </tr>
        @endforelse
    </tbody>
    @if($trades->hasPages())
    @slot('footer')
        {{ $trades->links() }}
    @endslot
    @endif
@endcomponent
@endsection

@push('styles')
@include('partials.ui.grid-styles')
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('paperTradesTable');
    const rows = table ? Array.from(table.querySelectorAll('tbody tr[data-status]')) : [];
    const countEl = document.getElementById('tableRowCount');
    const filterStatus = document.getElementById('filterStatus');
    const form = document.getElementById('paperTradeSettingsForm');

    function applyFilters() {
        const status = filterStatus.value;
        let visible = 0;

        rows.forEach(row => {
            const show = !status || row.dataset.status === status;
            row.dataset.hidden = show ? 'false' : 'true';
            if (show) visible++;
        });

        countEl.textContent = visible.toLocaleString() + ' trades';
    }

    filterStatus.addEventListener('change', applyFilters);
    applyFilters();

    if (form) {
        const syncSettingsToHidden = () => {
            form.querySelectorAll('input[type=checkbox][data-setting]').forEach(checkbox => {
                const hidden = document.getElementById(checkbox.dataset.setting);
                if (hidden) {
                    hidden.value = checkbox.checked ? '1' : '0';
                }
            });
        };

        form.querySelectorAll('input[type=checkbox][data-setting]').forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                syncSettingsToHidden();
                form.submit();
            });
        });
    }
});
</script>
@endpush
