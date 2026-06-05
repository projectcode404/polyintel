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
                   {{ $account->is_auto_trade ? 'checked' : '' }} data-setting="is_auto_trade">
            <label class="form-check-label text-muted small" for="autoTradeToggle">Auto Trade</label>
        </div>
        <div class="form-check form-switch m-0 d-flex align-items-center gap-1">
            <input class="form-check-input m-0" type="checkbox" value="1" id="autoCloseToggle"
                   {{ $account->is_auto_close ? 'checked' : '' }} data-setting="is_auto_close">
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

{{-- Modal: Close Trade --}}
<div class="modal fade" id="closeTradeGridModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <form id="closeTradeActionForm" method="POST" action="">
                @csrf
                <div class="modal-body pt-4 pb-3 px-4">
                    <h5 class="fw-bold mb-3">Close Position</h5>

                    <div class="bg-light rounded p-2 mb-3" style="font-size:12px;">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Market</span>
                            <strong id="modalMarketQuestion" class="text-end ms-3" style="font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Direction</span>
                            <strong id="modalDirection"></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Entry Price</span>
                            <strong id="modalEntryPriceDisplay"></strong>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small fw-medium mb-1">Exit Price <span class="text-muted">(0 – 1)</span></label>
                        <input type="number" step="0.0001" min="0" max="1"
                               name="exit_price" id="modalExitPriceInput"
                               class="form-control form-control-sm" required>
                        <div class="text-muted mt-1" style="font-size:11px;">Enter current market price for your side.</div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger px-3"
                            onclick="this.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span>Closing...'; this.disabled=true; this.form.submit();">
                        Confirm Close
                    </button>
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
    /* ---- Direction ---- */
    .dir-yes { color: #2fb344; font-weight: 700; font-size: 11px; }
    .dir-no  { color: #e63946; font-weight: 700; font-size: 11px; }

    /* ---- Edge / PnL ---- */
    .val-positive { color: #2fb344; font-weight: 600; }
    .val-negative { color: #e63946; font-weight: 600; }
    .val-neutral  { color: #6c757d; }

    /* ---- Status pill ---- */
    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.6;
        white-space: nowrap;
    }
    .status-open   { background: #dce7fd; color: #1a56db; }
    .status-closed { background: #f1f3f5; color: #6c757d; }

    /* ---- Rule badge ---- */
    .badge-rule {
        background: #eef2f7;
        color: #495057;
        padding: 2px 7px;
        border-radius: 4px;
        font-weight: 500;
        font-size: 11px;
        white-space: nowrap;
    }

    /* ---- Prob bar ---- */
    .prob-cell {
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        gap: 2px;
    }
    .prob-bar-row {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .prob-bar-track {
        width: 40px;
        height: 4px;
        background: #e9ecef;
        border-radius: 2px;
        overflow: hidden;
        flex-shrink: 0;
    }
    .prob-bar-fill { height: 100%; border-radius: 2px; }

    /* ---- AG Grid cell align ---- */
    .ag-cell { display: flex !important; align-items: center !important; }
    .ag-cell-wrapper { width: 100%; }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/dist/ag-grid-community.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const closeTradeModal        = new bootstrap.Modal(document.getElementById('closeTradeGridModal'));
    const closeTradeForm         = document.getElementById('closeTradeActionForm');
    const modalExitPriceInput    = document.getElementById('modalExitPriceInput');
    const modalEntryPriceDisplay = document.getElementById('modalEntryPriceDisplay');
    const modalDirection         = document.getElementById('modalDirection');
    const modalMarketQuestion    = document.getElementById('modalMarketQuestion');

    // ---- Helpers ----
    function probColor(pct) {
        return pct > 60 ? '#2fb344' : (pct < 40 ? '#e63946' : '#f76707');
    }

    function probBar(rawVal, label = null, subLabel = null) {
        const pct   = Math.round(parseFloat(rawVal) * 1000) / 10;
        const width = Math.round(parseFloat(rawVal) * 100);
        const color = probColor(pct);
        const valCls = pct > 60 ? 'val-positive' : (pct < 40 ? 'val-negative' : 'val-neutral');

        return `<div class="prob-cell">
                    ${label ? `<div class="${label.cls} mb-0" style="font-size:11px;font-weight:700;line-height:1.2;">${label.text}</div>` : ''}
                    <div class="prob-bar-row">
                        <div class="prob-bar-track">
                            <div class="prob-bar-fill" style="width:${width}%;background:${color};"></div>
                        </div>
                        <span class="${valCls} fw-semibold" style="font-size:11px;">${pct}%</span>
                    </div>
                    ${subLabel ? `<div class="text-muted" style="font-size:10px;line-height:1.2;">${subLabel}</div>` : ''}
                </div>`;
    }

    // ---- Column Definitions ----
    const columnDefs = [
        {
            headerName: 'Market Question',
            field: 'market_question',
            flex: 3,
            minWidth: 240,
            filter: 'agTextColumnFilter',
            cellRenderer: params => {
                if (!params.value) return '';
                const url = `/markets/${params.data.market_id}`;
                return `<a href="${url}" class="text-reset text-decoration-none fw-medium" style="font-size:12px;" title="${params.value}">${params.value}</a>`;
            }
        },
        {
            headerName: 'Signal',
            field: 'trigger_source',
            width: 130,
            filter: 'agTextColumnFilter',
            cellRenderer: params => params.value
                ? `<span class="badge-rule">${params.value}</span>`
                : `<span class="text-muted" style="font-size:11px;">Manual</span>`
        },
        {
            headerName: 'Entry',
            field: 'entry_price',
            width: 140,
            filter: 'agNumberColumnFilter',
            cellRenderer: params => {
                if (!params.data) return '';
                const dir    = (params.data.direction || 'YES').toUpperCase();
                const dirCls = dir === 'YES' ? 'dir-yes' : 'dir-no';
                const arrow  = dir === 'YES' ? '▲' : '▼';
                const sub    = params.data.entered_at ?? '';
                return probBar(
                    params.value,
                    { text: `${arrow} ${dir}`, cls: dirCls },
                    sub
                );
            }
        },
        {
            headerName: 'Shares',
            field: 'shares',
            width: 100,
            filter: 'agNumberColumnFilter',
            cellStyle: { justifyContent: 'flex-end' },
            valueFormatter: p => p.value
                ? Number(p.value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '—'
        },
        {
            headerName: 'Size (USD)',
            field: 'position_size_usd',
            width: 105,
            filter: 'agNumberColumnFilter',
            cellStyle: { justifyContent: 'flex-end' },
            valueFormatter: p => p.value
                ? '$' + Number(p.value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '—'
        },
        {
            headerName: 'Current / Exit',
            field: 'current_or_exit_price',
            width: 130,
            filter: false,
            cellRenderer: params => {
                if (!params.data) return '';
                const isOpen = params.data.status?.toLowerCase() === 'open';
                const val    = parseFloat(params.data.current_or_exit_price ?? 0);
                return probBar(val, null, isOpen ? 'Current' : 'Exit');
            }
        },
        {
            headerName: 'PnL',
            field: 'pnl_usd',
            width: 110,
            filter: 'agNumberColumnFilter',
            cellStyle: { justifyContent: 'flex-end' },
            cellRenderer: params => {
                if (!params.data) return '';
                const isOpen = params.data.status?.toLowerCase() === 'open';
                const pnl    = parseFloat(isOpen
                    ? (params.data.unrealized_pnl_usd ?? 0)
                    : (params.value ?? 0));
                const isPos  = pnl >= 0;
                const sign   = isPos ? '+$' : '-$';
                const abs    = Math.abs(pnl).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const tag    = isOpen ? ' <span class="text-muted" style="font-size:10px;">(U)</span>' : '';
                return `<span class="${isPos ? 'val-positive' : 'val-negative'}" style="font-size:12px;">${sign}${abs}${tag}</span>`;
            }
        },
        {
            headerName: 'ROI',
            field: 'roi',
            width: 85,
            filter: 'agNumberColumnFilter',
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                const isOpen = params.data?.status?.toLowerCase() === 'open';
                if (isOpen || params.value === null || params.value === undefined) {
                    return '<span class="text-muted" style="font-size:11px;">—</span>';
                }
                const roiVal = parseFloat(params.value) * 100;
                const isPos  = roiVal >= 0;
                return `<span class="${isPos ? 'val-positive' : 'val-negative'}" style="font-size:12px;">${isPos ? '+' : ''}${roiVal.toFixed(1)}%</span>`;
            }
        },
        {
            headerName: 'Status',
            field: 'status',
            width: 120,
            filter: false,
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                if (!params.value) return '';
                const isOpen  = params.value.toLowerCase() === 'open';
                const cls     = isOpen ? 'status-open' : 'status-closed';
                const outcome = !isOpen && params.data?.outcome
                    ? ` <span class="text-muted" style="font-size:10px;">(${params.data.outcome})</span>`
                    : '';
                const label   = params.value.charAt(0).toUpperCase() + params.value.slice(1);
                return `<span class="status-pill ${cls}">${label}</span>${outcome}`;
            }
        },
        {
            headerName: 'Action',
            field: 'id',
            width: 90,
            filter: false,
            sortable: false,
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                if (!params.data || params.data.status?.toLowerCase() !== 'open') {
                    return '<span class="text-muted" style="font-size:11px;">—</span>';
                }
                return `<button type="button"
                            class="btn btn-sm btn-outline-danger py-0 px-2"
                            style="font-size:11px;"
                            onclick="openCloseModal(${params.value}, this)">Close</button>`;
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
        rowHeight: 52,
        headerHeight: 38,
        defaultColDef: {
            sortable: true,
            resizable: true,
            filter: true,
            floatingFilter: true,
            suppressMovable: true,
        },
        onGridReady: params => loadData(params.api),
    };

    function loadData(api) {
        api.setGridOption('datasource', {
            getRows(params) {
                const status = document.getElementById('filterStatus').value.toLowerCase();

                fetch(`/api/paper-trades/grid?` + new URLSearchParams({
                    startRow:    params.startRow,
                    endRow:      params.endRow,
                    sortModel:   JSON.stringify(params.sortModel),
                    filterModel: JSON.stringify(params.filterModel),
                    status,
                }), {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    }
                })
                .then(r => r.json())
                .then(data => {
                    params.successCallback(data.rows, data.totalRows);
                    document.getElementById('gridRowCount').textContent =
                        Number(data.totalRows).toLocaleString() + ' trades';
                })
                .catch(() => params.failCallback());
            }
        });
    }

    const grid = agGrid.createGrid(document.getElementById('paperTradesGrid'), gridOptions);

    // ---- Watchers ----
    document.getElementById('filterStatus').addEventListener('change', () => grid.api?.purgeInfiniteCache());
    document.getElementById('refreshGridBtn').addEventListener('click', () => grid.api?.purgeInfiniteCache());

    // ---- Close Trade Modal ----
    window.openCloseModal = function(tradeId, btn) {
        // Ambil row data dari grid
        let rowData = null;
        grid.api?.forEachNode(node => { if (node.data?.id === tradeId) rowData = node.data; });

        closeTradeForm.action = `/paper-trades/${tradeId}/close`;

        const entryPct = rowData ? (parseFloat(rowData.entry_price) * 100).toFixed(1) + '%' : '—';
        const currentVal = rowData?.current_price ?? rowData?.entry_price ?? 0;

        modalEntryPriceDisplay.textContent = entryPct;
        modalExitPriceInput.value          = parseFloat(currentVal).toFixed(4);
        modalMarketQuestion.textContent    = rowData?.market_question ?? '';
        modalMarketQuestion.title          = rowData?.market_question ?? '';

        const dir    = (rowData?.direction ?? 'yes').toUpperCase();
        const dirCls = dir === 'YES' ? 'dir-yes' : 'dir-no';
        modalDirection.textContent  = dir === 'YES' ? '▲ YES' : '▼ NO';
        modalDirection.className    = dirCls;

        closeTradeModal.show();
    };

    // ---- Auto Trade / Auto Close settings ----
    const settingsForm = document.getElementById('paperTradeSettingsForm');
    if (settingsForm) {
        settingsForm.querySelectorAll('input[type=checkbox][data-setting]').forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                const hidden = document.getElementById(this.dataset.setting);
                if (hidden) hidden.value = this.checked ? '1' : '0';
                settingsForm.submit();
            });
        });
    }
});
</script>
@endpush