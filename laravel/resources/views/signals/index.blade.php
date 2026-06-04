@extends('layouts.app')

@section('title', 'Signals')
@section('page-title', 'Signals')
@section('page-subtitle', 'Pending and active signals from the Python rules engine')

@section('page-actions')
<div class="d-flex gap-2 align-items-center flex-wrap">
    <select id="filterStatus" class="form-select form-select-sm" style="width: auto">
        <option value="">All Status</option>
        <option value="pending" selected>Pending</option>
        <option value="active">Active</option>
        <option value="cancelled">Cancelled</option>
        <option value="closed">Closed</option>
    </select>
    <select id="filterDirection" class="form-select form-select-sm" style="width: auto">
        <option value="">All Directions</option>
        <option value="yes">BUY YES</option>
        <option value="no">BUY NO</option>
    </select>
    <span class="text-muted small d-flex align-items-center" id="tableRowCount"></span>
</div>
@endsection

@section('content')
@component('partials.ui.data-table-card', ['tableId' => 'signalsTable'])
    <thead>
        <tr>
            <th>Market Question</th>
            <th>Rule Trigger</th>
            <th>Direction</th>
            <th>Prob Entry</th>
            <th>Edge</th>
            <th>Status</th>
            <th>Fired At (UTC)</th>
            <th>Context</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        @forelse($signals as $signal)
        <tr data-status="{{ $signal->status }}" data-direction="{{ $signal->direction }}">
            <td>
                <a href="{{ route('markets.show', $signal->market_id) }}" class="market-link" title="{{ $signal->market->question }}">
                    {{ Str::limit($signal->market->question, 48) }}
                </a>
            </td>
            <td>
                <span class="badge-rule">{{ $signal->trigger_source }}</span>
            </td>
            <td>
                @if($signal->direction === 'yes')
                    <span class="prob-high">BUY YES</span>
                @else
                    <span class="prob-low">BUY NO</span>
                @endif
            </td>
            <td>
                @include('partials.ui.probability-bar', ['value' => $signal->market_probability_at_signal])
            </td>
            <td>
                @php $edgePct = round($signal->edge_at_signal * 100, 1); @endphp
                <span class="{{ $edgePct >= 0 ? 'edge-positive' : 'edge-negative' }}">
                    {{ $edgePct >= 0 ? '+' : '' }}{{ $edgePct }}%
                </span>
            </td>
            <td>
                @if($signal->status === 'pending')
                    <span class="badge bg-warning-lt">Pending</span>
                @elseif($signal->status === 'active')
                    <span class="badge bg-success-lt">Active</span>
                @elseif($signal->status === 'cancelled')
                    <span class="badge bg-secondary-lt">Cancelled</span>
                @else
                    <span class="badge bg-blue-lt">{{ ucfirst($signal->status) }}</span>
                @endif
            </td>
            <td class="text-meta">{{ $signal->fired_at->utc()->format('M d, H:i') }}</td>
            <td>
                @if($signal->snapshot_data)
                <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="popover"
                        data-bs-trigger="focus"
                        title="Snapshot Context"
                        data-bs-content="{{ e(json_encode($signal->snapshot_data)) }}">
                    View
                </button>
                @else
                <span class="text-meta">—</span>
                @endif
            </td>
            <td>
                @if($signal->status === 'pending')
                <div class="d-flex gap-1">
                    <form action="{{ route('signals.execute', $signal->id) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Execute</button>
                    </form>
                    <form action="{{ route('signals.ignore', $signal->id) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-danger">Ignore</button>
                    </form>
                </div>
                @else
                <span class="text-meta">—</span>
                @endif
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="9" class="text-center text-meta py-5">No signals found.</td>
        </tr>
        @endforelse
    </tbody>
    @if($signals->hasPages())
    @slot('footer')
        {{ $signals->links() }}
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
    const table = document.getElementById('signalsTable');
    const rows = table ? Array.from(table.querySelectorAll('tbody tr[data-status]')) : [];
    const countEl = document.getElementById('tableRowCount');
    const filterStatus = document.getElementById('filterStatus');
    const filterDirection = document.getElementById('filterDirection');

    function applyFilters() {
        const status = filterStatus.value;
        const direction = filterDirection.value;
        let visible = 0;

        rows.forEach(row => {
            const matchStatus = !status || row.dataset.status === status;
            const matchDir = !direction || row.dataset.direction === direction;
            const show = matchStatus && matchDir;
            row.dataset.hidden = show ? 'false' : 'true';
            if (show) visible++;
        });

        countEl.textContent = visible.toLocaleString() + ' signals';
    }

    [filterStatus, filterDirection].forEach(el => {
        el.addEventListener('change', applyFilters);
    });

    applyFilters();

    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
        new bootstrap.Popover(el);
    });
});
</script>
@endpush
