@extends('layouts.app')

@section('content')
<div class="container-xl">
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">
                    Paper Trades
                </h2>
                <div class="text-muted mt-1">Simulated portfolio execution</div>
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

        <div class="row row-cards mb-3">
            <div class="col-md-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-primary text-white avatar">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M16.7 8a3 3 0 0 0 -2.7 -2h-4a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-4a3 3 0 0 1 -2.7 -2" /><path d="M12 3v3m0 12v3" /></svg>
                                </span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">
                                    Portfolio Balance
                                </div>
                                <div class="text-muted">
                                    ${{ number_format($account->balance, 2) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-9">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('paper-trades.settings') }}" method="POST" class="d-flex align-items-center gap-4">
                            @csrf
                            <label class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" name="is_auto_trade" value="1" {{ $account->is_auto_trade ? 'checked' : '' }} onchange="this.form.submit()">
                                <span class="form-check-label">Auto Trade (Open on Signal)</span>
                            </label>
                            
                            <label class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" name="is_auto_close" value="1" {{ $account->is_auto_close ? 'checked' : '' }} onchange="this.form.submit()">
                                <span class="form-check-label">Auto Close (On Market Resolve)</span>
                            </label>
                            
                            <input type="hidden" name="is_auto_trade" value="0" disabled>
                            <input type="hidden" name="is_auto_close" value="0" disabled>
                        </form>
                        <script>
                            // Handle unchecking switch to send 0
                            document.querySelectorAll('input[type=checkbox]').forEach(el => {
                                el.addEventListener('change', function() {
                                    if(!this.checked) {
                                        let hidden = this.parentElement.nextElementSibling;
                                        if(hidden && hidden.type == 'hidden') hidden.disabled = false;
                                    }
                                });
                            });
                        </script>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table card-table table-vcenter text-nowrap datatable">
                    <thead>
                        <tr>
                            <th>Market</th>
                            <th>Signal Source</th>
                            <th>Entry</th>
                            <th>Shares</th>
                            <th>Size (USD)</th>
                            <th>Current/Exit</th>
                            <th>PnL</th>
                            <th>ROI</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($trades as $trade)
                        <tr>
                            <td>
                                <a href="{{ route('markets.show', $trade->market_id) }}" class="text-reset" tabindex="-1">
                                    {{ Str::limit($trade->market->question, 30) }}
                                </a>
                            </td>
                            <td>
                                @if($trade->signal)
                                    <span class="badge bg-purple-lt">{{ $trade->signal->trigger_source }}</span>
                                @else
                                    <span class="badge bg-secondary-lt">Manual</span>
                                @endif
                            </td>
                            <td>
                                {{ strtoupper($trade->direction) }} @ {{ number_format($trade->entry_price * 100, 1) }}%<br>
                                <small class="text-muted">{{ $trade->entered_at->format('M d, H:i') }}</small>
                            </td>
                            <td>{{ number_format($trade->shares, 2) }}</td>
                            <td>${{ number_format($trade->position_size_usd, 2) }}</td>
                            <td>
                                @if($trade->status === 'open')
                                    {{ number_format($trade->current_price * 100, 1) }}% (Current)
                                @else
                                    {{ number_format($trade->exit_price * 100, 1) }}% (Exit)
                                @endif
                            </td>
                            <td>
                                @if($trade->status === 'open')
                                    <span class="{{ $trade->unrealized_pnl_usd >= 0 ? 'text-success' : 'text-danger' }}">
                                        ${{ number_format($trade->unrealized_pnl_usd, 2) }} (U)
                                    </span>
                                @else
                                    <span class="{{ $trade->pnl_usd >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $trade->pnl_formatted }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($trade->status === 'closed')
                                    <span class="{{ $trade->roi >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $trade->roi_percent }}
                                    </span>
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if($trade->status === 'open')
                                    <span class="badge bg-primary">Open</span>
                                @else
                                    <span class="badge bg-secondary">Closed ({{ $trade->outcome }})</span>
                                @endif
                            </td>
                            <td>
                                @if($trade->status === 'open')
                                    <button type="button" class="btn btn-sm btn-ghost-danger" data-bs-toggle="modal" data-bs-target="#closeTradeModal{{ $trade->id }}">
                                        Close Position
                                    </button>

                                    <!-- Close Modal -->
                                    <div class="modal modal-blur fade" id="closeTradeModal{{ $trade->id }}" tabindex="-1" role="dialog" aria-hidden="true">
                                      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
                                        <div class="modal-content">
                                          <form action="{{ route('paper-trades.close', $trade->id) }}" method="POST">
                                              @csrf
                                              <div class="modal-body">
                                                <div class="modal-title">Close Position</div>
                                                <div>Manually close this paper trade. Enter the current market price for your side (0 to 1).</div>
                                                <div class="mt-3">
                                                    <label class="form-label">Exit Price (0 - 1)</label>
                                                    <input type="number" step="0.0001" min="0" max="1" name="exit_price" class="form-control" value="{{ $trade->current_price }}" required>
                                                </div>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Confirm Close</button>
                                              </div>
                                          </form>
                                        </div>
                                      </div>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($trades->hasPages())
            <div class="card-footer d-flex align-items-center">
                {{ $trades->links() }}
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
