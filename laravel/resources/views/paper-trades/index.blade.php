@extends('layouts.app')

@section('title', 'Paper Trades')
@section('page-title', 'Paper Trades')
@section('page-subtitle', 'Simulated portfolio execution — all times UTC')

@section('page-actions')
<div class="d-flex gap-2 align-items-center flex-wrap">
    <span class="badge bg-light text-dark border d-flex align-items-center py-2 px-3">
        Balance: <strong class="ms-1 fs-6">${{{ number_format($account->balance, 2) }}}</strong>
    </span>

    <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" id="refreshGridBtn" title="Refresh Data">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21v-5h5"/></svg>
    </button>

    <select id="filterStatus" class="form-select form-select-sm" style="width: auto">
        <option value="">All Status</option>
        <option value="open" selected>Open</option>
        <option value="closed">Closed</option>
    </select>
    
    <form action="{{ route('paper-trades.settings') }}" method="POST" class="d-flex align-items-center gap-3 mb-0" id="paperTradeSettingsForm">
        @csrf
        <input type="hidden" name="is_auto_trade" id="is_auto_trade" value="{{ $account->is_auto_trade ? '1' : '0' }}">
        <input type="hidden" name="is_auto_close" id="is_auto_close" value="{{ $account->is_auto_close ? '1' : '0' }}">
        
        <div class="form-check form-switch m-0 d-flex align-items-center gap-1">
            <input class="form-check-input m-0" type="checkbox" value="1" id="autoTradeToggle"
                   {{ $account->is_auto_trade ? 'checked' : '' }}
                   data-setting="is_auto_trade">
            <label class="form-check-label text-muted small" for="autoTradeToggle">Auto Trade</label>
        </div>
        <div class="form-check form-switch m-0 d-flex align-items-center gap-1">
            <input class="form-check-input m-0" type="checkbox" value="1" id="autoCloseToggle"
                   {{ $account->is_auto_close ? 'checked' : '' }}
                   data-setting="is_auto_close">
            <label class="form-check-label text-muted small" for="autoCloseToggle">Auto Close</label>
        </div>
    </form>
    <span class="text-muted small d-flex align-items-center" id="gridRowCount"></span>
</div>
@endsection

@section('content')
<div class="card p-0" style="height: calc(100vh - 200px); min-height: 500px;">
    <div id="paperTradesGrid" class="ag-theme-alpine w-100 h-100"></div>
</div>

<div class="modal fade" id="closeTradeGridModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <form id="closeTradeActionForm" method="POST" action="">
                @csrf
                <div class="modal-body">
                    <h5 class="modal-title mb-2 fw-bold">Close Position</h5>
                    
                    <div class="alert alert-info py-2 mb-3 d-flex justify-content-between align-items-center" style="font-size: 13px;">
                        <span>Entry Price:</span> 
                        <strong id="modalEntryPriceDisplay" class="fs-6 text-dark"></strong>
                    </div>
                    
                    <div class="text-muted small mb-3">Enter the current market price for your side (0 to 1).</div>
                    <div>
                        <label class="form-label small fw-medium">Exit Price (0 – 1)</label>
                        <input type="number" step="0.0001" min="0" max="1" name="exit_price" id="modalExitPriceInput"
                               class="form-control form-control-sm" value="" required>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger" onclick="this.innerHTML='<span class=\'spinner-border spinner-border-sm\'></span> Closing...'; this.form.submit(); this.disabled=true;">Confirm Close</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-theme-alpine.css">
@include('partials.ui.grid-styles')
<style>
    .badge-rule { background: #eef2f7; color: #495057; padding: 2px 6px; border-radius: 4px; font-weight: 500; font-size: 11px; }
    .badge-other { background: #f1f3f5; color: #6c757d; padding: 2px 6px; border-radius: 4px; font-weight: 500; font-size: 11px; }
    .edge-positive { color: #2fb344; font-weight: 600; }
    .edge-negative { color: #e63946; font-weight: 600; }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const closeTradeModal = new bootstrap.Modal(document.getElementById('closeTradeGridModal'));
    const closeTradeForm = document.getElementById('closeTradeActionForm');
    const modalExitPriceInput = document.getElementById('modalExitPriceInput');
    const modalEntryPriceDisplay = document.getElementById('modalEntryPriceDisplay');

    // ---- Column Definitions ----
    const columnDefs = [
        {
            headerName: 'Market Question',
            field: 'market_question',
            flex: 3,
            minWidth: 260,
            filter: 'agTextColumnFilter',
            cellRenderer: params => {
                if (!params.value) return '';
                const url = `/markets/${params.data.market_id}`;
                return `<a href="${url}" class="text-reset text-decoration-none fw-medium" title="${params.value}">${params.value}</a>`;
            }
        },
        {
            headerName: 'Signal Source',
            field: 'trigger_source',
            width: 120,
            filter: 'agTextColumnFilter',
            cellRenderer: params => params.value ? `<span class="badge-rule">${params.value}</span>` : `<span class="badge-other">Manual</span>`
        },
        {
            headerName: 'Entry',
            field: 'entry_price',
            width: 140,
            filter: 'agNumberColumnFilter',
            cellRenderer: params => {
                if (!params.data) return '';
                const dir = (params.data.direction || 'YES').toUpperCase();
                const dirClass = dir === 'YES' ? 'prob-high' : 'prob-low';
                const rawVal = parseFloat(params.value || 0);
                const pct = Math.round(rawVal * 1000) / 10;
                const width = Math.round(rawVal * 100);
                const color = pct > 60 ? '#2fb344' : (pct < 40 ? '#e63946' : '#f76707');

                return `
                    <div class="d-flex flex-column justify-content-center h-100 py-1">
                        <div class="fw-bold ${dirClass}" style="font-size:11px; line-height:1.2;">${dir}</div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <div style="width:40px;height:5px;background:#e9ecef;border-radius:2px;overflow:hidden;flex-shrink:0;">
                                <div style="width:${width}%;height:100%;background:${color};"></div>
                            </div>
                            <span class="small text-muted" style="font-size:11px;">${pct}%</span>
                        </div>
                        <div class="text-muted" style="font-size:10px;">${params.data.entered_at || ''}</div>
                    </div>`;
            }
        },
        {
            headerName: 'Shares',
            field: 'shares',
            width: 100,
            filter: 'agNumberColumnFilter',
            valueFormatter: p => p.value ? Number(p.value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00'
        },
        {
            headerName: 'Size (USD)',
            field: 'position_size_usd',
            width: 110,
            filter: 'agNumberColumnFilter',
            valueFormatter: p => p.value ? '$' + Number(p.value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '$0.00'
        },
        {
            headerName: 'Current / Exit',
            field: 'current_or_exit_price',
            width: 130,
            filter: false,
            cellRenderer: params => {
                if (!params.data) return '';
                const isOpen = params.data.status?.toLowerCase() === 'open';
                const label = isOpen ? 'Current' : 'Exit';
                const rawVal = parseFloat(isOpen ? (params.data.current_price || 0) : (params.data.exit_price || 0));
                const pct = Math.round(rawVal * 1000) / 10;
                const width = Math.round(rawVal * 100);
                const color = pct > 60 ? '#2fb344' : (pct < 40 ? '#e63946' : '#f76707');

                return `
                    <div class="d-flex flex-column justify-content-center h-100 py-1">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:40px;height:5px;background:#e9ecef;border-radius:2px;overflow:hidden;flex-shrink:0;">
                                <div style="width:${width}%;height:100%;background:${color};"></div>
                            </div>
                            <span class="fw-medium" style="font-size:11px;">${pct}%</span>
                        </div>
                        <div class="text-muted" style="font-size:10px;">${label}</div>
                    </div>`;
            }
        },
        {
            headerName: 'PnL',
            field: 'pnl_usd',
            width: 100,
            filter: 'agNumberColumnFilter',
            cellRenderer: params => {
                if (!params.data) return '';
                const isOpen = params.data.status?.toLowerCase() === 'open';
                const pnl = parseFloat(isOpen ? (params.data.unrealized_pnl_usd || 0) : (params.value || 0));
                const isPositive = pnl >= 0;
                const sign = isPositive ? '+$' : '-$';
                const absPnl = Math.abs(pnl).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                const suffix = isOpen ? ' <span class="text-muted" style="font-size:10px;">(U)</span>' : '';
                return `<span class="${isPositive ? 'edge-positive' : 'edge-negative'}">${sign}${absPnl}${suffix}</span>`;
            }
        },
        {
            headerName: 'ROI',
            field: 'roi',
            width: 90,
            filter: 'agNumberColumnFilter',
            cellRenderer: params => {
                if (params.value === null || params.value === undefined || params.data?.status?.toLowerCase() === 'open') return '<span class="text-muted">—</span>';
                const roiVal = parseFloat(params.value) * 100;
                const isPositive = roiVal >= 0;
                return `<span class="${isPositive ? 'edge-positive' : 'edge-negative'}">${isPositive ? '+' : ''}${roiVal.toFixed(1)}%</span>`;
            }
        },
        {
            headerName: 'Status',
            field: 'status',
            width: 110,
            filter: false,
            cellRenderer: params => {
                if (!params.value) return '';
                const statusClean = params.value.toLowerCase();
                if (statusClean === 'open') return `<span class="badge bg-primary-lt">Open</span>`;
                const outcome = params.data?.outcome ? ` (${params.data.outcome})` : '';
                return `<span class="badge bg-secondary-lt">Closed${outcome}</span>`;
            }
        },
        {
            headerName: 'Action',
            field: 'id',
            width: 90,
            filter: false,
            sortable: false,
            cellRenderer: params => {
                if (!params.data || params.data.status?.toLowerCase() !== 'open') return '<span class="text-muted">—</span>';
                return `<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;">Close</button>`;
            },
            onCellClicked: params => {
                // Konfigurasi aksi Modal dengan menyertakan Entry Price
                if (params.data && params.data.status?.toLowerCase() === 'open' && params.colDef.headerName === 'Action') {
                    closeTradeForm.action = `/paper-trades/${params.value}/close`;
                    modalExitPriceInput.value = params.data.current_price || 0;
                    
                    const entryPct = (parseFloat(params.data.entry_price || 0) * 100).toFixed(1);
                    modalEntryPriceDisplay.textContent = entryPct + '%';
                    
                    closeTradeModal.show();
                }
            }
        }
    ];

    // ---- Grid Configuration ----
    const gridOptions = {
        columnDefs,
        rowModelType: 'infinite',
        cacheBlockSize: 100,
        maxBlocksInCache: 10,
        infiniteInitialRowCount: 100,
        defaultColDef: { sortable: true, resizable: true, filter: true, floatingFilter: true },
        onGridReady: params => loadData(params.api),
    };

    function loadData(api) {
        api.setGridOption('datasource', {
            getRows(params) {
                const status = document.getElementById('filterStatus').value.toLowerCase(); 

                fetch(`/api/paper-trades/grid?` + new URLSearchParams({
                    startRow: params.startRow,
                    endRow: params.endRow,
                    sortModel: JSON.stringify(params.sortModel),
                    filterModel: JSON.stringify(params.filterModel),
                    status: status,
                }), {
                    headers: { 
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    params.successCallback(data.rows, data.totalRows);
                    document.getElementById('gridRowCount').textContent = Number(data.totalRows).toLocaleString() + ' trades';
                })
                .catch(() => params.failCallback());
            }
        });
    }

    const grid = agGrid.createGrid(document.getElementById('paperTradesGrid'), gridOptions);

    // ---- Watchers (Filter & Auto Settings) ----
    document.getElementById('filterStatus').addEventListener('change', () => grid.api?.purgeInfiniteCache());
    
    document.getElementById('refreshGridBtn').addEventListener('click', () => {
        grid.api?.purgeInfiniteCache();
    });

    const settingsForm = document.getElementById('paperTradeSettingsForm');
    if (settingsForm) {
        settingsForm.querySelectorAll('input[type=checkbox][data-setting]').forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                const hiddenInput = document.getElementById(this.dataset.setting);
                if (hiddenInput) {
                    hiddenInput.value = this.checked ? '1' : '0';
                }
                settingsForm.submit();
            });
        });
    }
});
</script>
@endpush