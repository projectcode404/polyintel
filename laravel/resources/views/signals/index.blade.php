@extends('layouts.app')

@section('content')
<div class="container-xl">
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">
                    Trading Signals
                </h2>
                <div class="text-muted mt-1">Pending and active signals from Python engine</div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="card">
            <div class="table-responsive">
                <table class="table card-table table-vcenter text-nowrap datatable">
                    <thead>
                        <tr>
                            <th>Market</th>
                            <th>Rule Trigger</th>
                            <th>Direction</th>
                            <th>Prob Entry</th>
                            <th>Edge</th>
                            <th>Status</th>
                            <th>Fired At</th>
                            <th>Context</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($signals as $signal)
                        <tr>
                            <td>
                                <a href="{{ route('markets.show', $signal->market_id) }}" class="text-reset" tabindex="-1">
                                    {{ Str::limit($signal->market->question, 40) }}
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-purple-lt">{{ $signal->trigger_source }}</span>
                            </td>
                            <td>
                                @if($signal->direction === 'yes')
                                    <span class="badge bg-success">BUY YES</span>
                                @else
                                    <span class="badge bg-danger">BUY NO</span>
                                @endif
                            </td>
                            <td>{{ number_format($signal->market_probability_at_signal * 100, 1) }}%</td>
                            <td>{{ number_format($signal->edge_at_signal * 100, 1) }}%</td>
                            <td>
                                @if($signal->status === 'pending')
                                    <span class="badge bg-warning">Pending</span>
                                @elseif($signal->status === 'active')
                                    <span class="badge bg-info">Active</span>
                                @else
                                    <span class="badge bg-secondary">{{ ucfirst($signal->status) }}</span>
                                @endif
                            </td>
                            <td>{{ $signal->fired_at->diffForHumans() }}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-toggle="popover" title="Snapshot Context" data-bs-content="{{ json_encode($signal->snapshot_data) }}">
                                    View Data
                                </button>
                            </td>
                            <td>
                                @if($signal->status === 'pending')
                                    <form action="{{ route('signals.execute', $signal->id) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-primary">Execute</button>
                                    </form>
                                    <form action="{{ route('signals.ignore', $signal->id) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-ghost-danger">Ignore</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($signals->hasPages())
            <div class="card-footer d-flex align-items-center">
                {{ $signals->links() }}
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl)
        })
    });
</script>
@endpush
