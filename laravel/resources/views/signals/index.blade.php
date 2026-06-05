@extends('layouts.app')

@section('title', 'Signals')
@section('page-title', 'Signals')
@section('page-subtitle', 'Pending and active signals from the Python rules engine')

@section('page-actions')
<div class="d-flex gap-2 align-items-center flex-wrap">
    <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" id="refreshGridBtn" title="Refresh Data">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21v-5h5"/></svg>
        Refresh
    </button>

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

    <span class="text-muted small d-flex align-items-center" id="gridRowCount"></span>
</div>
@endsection

@section('content')
<div class="card p-0" style="height: calc(100vh - 200px); min-height: 500px;">
    <div id="signalsGrid" class="ag-theme-alpine w-100 h-100"></div>
</div>

{{-- Modal: Execute Signal --}}
<div class="modal fade" id="executeSignalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body pt-4 pb-3 px-4">
                <div class="mb-3 text-center">
                    <span class="avatar avatar-lg bg-primary-lt mb-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                    </span>
                    <h5 class="fw-bold mb-1">Execute Paper Trade</h5>
                    <p class="text-muted small mb-0" id="executeModalQuestion" style="font-size:12px;"></p>
                </div>

                <div class="bg-light rounded p-2 mb-3" style="font-size:12px;">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Direction</span>
                        <strong id="executeModalDirection" class="text-success"></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Market Prob</span>
                        <strong id="executeModalProb"></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Edge</span>
                        <strong id="executeModalEdge" class="text-success"></strong>
                    </div>
                </div>

                <p class="text-muted small text-center mb-0" style="font-size:11px;">
                    2% of current balance will be allocated to this position.
                </p>
            </div>
            <div class="modal-footer py-2 border-top">
                <button type="button" class="btn btn-sm btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary px-3" id="executeConfirmBtn">
                    Execute Trade
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Ignore Signal --}}
<div class="modal fade" id="ignoreSignalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body pt-4 pb-3 px-4 text-center">
                <span class="avatar avatar-lg bg-danger-lt mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </span>
                <h5 class="fw-bold mb-1">Ignore Signal</h5>
                <p class="text-muted small mb-0" id="ignoreModalQuestion" style="font-size:12px;"></p>
                <p class="text-muted mt-2 mb-0" style="font-size:11px;">This signal will be marked as cancelled and removed from the pending list.</p>
            </div>
            <div class="modal-footer py-2 border-top">
                <button type="button" class="btn btn-sm btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger px-3" id="ignoreConfirmBtn">
                    Ignore Signal
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Context JSON --}}
<div class="modal fade" id="contextModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title small fw-bold">Snapshot Context Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-dark p-0">
                <pre class="m-0 p-3" style="font-size:11px;color:#e9ecef;max-height:500px;overflow:auto;"><code id="modalJsonContent"></code></pre>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-theme-alpine.css">
@include('partials.ui.grid-styles')
<style>
    /* ---- Badge rule trigger ---- */
    .badge-rule {
        background: #eef2f7;
        color: #495057;
        padding: 2px 7px;
        border-radius: 4px;
        font-weight: 500;
        font-size: 11px;
        white-space: nowrap;
    }

    /* ---- Direction badges ---- */
    .dir-yes {
        color: #2fb344;
        font-weight: 700;
        font-size: 11px;
        letter-spacing: .3px;
    }
    .dir-no {
        color: #e63946;
        font-weight: 700;
        font-size: 11px;
        letter-spacing: .3px;
    }

    /* ---- Edge / PnL colors ---- */
    .edge-positive { color: #2fb344; font-weight: 600; }
    .edge-negative { color: #e63946; font-weight: 600; }

    /* ---- Status badges (pill style) ---- */
    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .2px;
        line-height: 1.6;
    }
    .status-pending  { background: #fff3cd; color: #856404; }
    .status-active   { background: #d1f0da; color: #176d31; }
    .status-cancelled{ background: #f1f3f5; color: #6c757d; }
    .status-closed   { background: #e8eaed; color: #495057; }

    /* ---- Prob bar ---- */
    .prob-bar-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
        height: 100%;
    }
    .prob-bar-track {
        width: 44px;
        height: 4px;
        background: #e9ecef;
        border-radius: 2px;
        overflow: hidden;
        flex-shrink: 0;
    }
    .prob-bar-fill { height: 100%; border-radius: 2px; }

    /* ---- AG Grid cell vertical center fix ---- */
    .ag-cell { display: flex !important; align-items: center !important; }
    .ag-cell-wrapper { width: 100%; }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/dist/ag-grid-community.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const executeModal     = new bootstrap.Modal(document.getElementById('executeSignalModal'));
    const ignoreModal      = new bootstrap.Modal(document.getElementById('ignoreSignalModal'));
    const contextModal     = new bootstrap.Modal(document.getElementById('contextModal'));
    const modalJsonContent = document.getElementById('modalJsonContent');

    let pendingExecuteId = null;
    let pendingIgnoreId  = null;
    const snapshotStore  = new Map();

    function probBar(rawVal) {
        const pct   = Math.round(rawVal * 1000) / 10;
        const width = Math.round(rawVal * 100);
        const color = pct > 60 ? '#2fb344' : (pct < 40 ? '#e63946' : '#f76707');
        const cls   = pct > 60 ? 'edge-positive' : (pct < 40 ? 'edge-negative' : '');
        return `<div class="prob-bar-wrap">
                    <div class="prob-bar-track">
                        <div class="prob-bar-fill" style="width:${width}%;background:${color};"></div>
                    </div>
                    <span class="${cls} fw-semibold" style="font-size:12px;">${pct}%</span>
                </div>`;
    }

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
            headerName: 'Rule Trigger',
            field: 'trigger_source',
            width: 140,
            filter: 'agTextColumnFilter',
            cellRenderer: params => params.value
                ? `<span class="badge-rule">${params.value}</span>`
                : '<span class="text-muted" style="font-size:11px;">—</span>'
        },
        {
            headerName: 'Direction',
            field: 'direction',
            width: 100,
            filter: false,
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                if (!params.value) return '';
                const isYes = params.value.toLowerCase() === 'yes';
                return `<span class="${isYes ? 'dir-yes' : 'dir-no'}">${isYes ? '▲ BUY YES' : '▼ BUY NO'}</span>`;
            }
        },
        {
            headerName: 'Prob Entry',
            field: 'market_probability_at_signal',
            width: 130,
            filter: 'agNumberColumnFilter',
            cellRenderer: params => {
                if (params.value === null || params.value === undefined) return '<span class="text-muted">N/A</span>';
                return probBar(parseFloat(params.value));
            }
        },
        {
            headerName: 'Edge',
            field: 'edge_at_signal',
            width: 90,
            filter: 'agNumberColumnFilter',
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                if (params.value === null || params.value === undefined) return '<span class="text-muted">—</span>';
                const pct = (parseFloat(params.value) * 100).toFixed(1);
                const isPos = pct >= 0;
                return `<span class="${isPos ? 'edge-positive' : 'edge-negative'}" style="font-size:12px;">${isPos ? '+' : ''}${pct}%</span>`;
            }
        },
        {
            headerName: 'Status',
            field: 'status',
            width: 110,
            filter: false,
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                if (!params.value) return '';
                const map = { pending: 'status-pending', active: 'status-active', cancelled: 'status-cancelled', closed: 'status-closed' };
                const cls   = map[params.value] ?? 'status-closed';
                const label = params.value.charAt(0).toUpperCase() + params.value.slice(1);
                return `<span class="status-pill ${cls}">${label}</span>`;
            }
        },
        {
            headerName: 'Fired At (UTC)',
            field: 'fired_at',
            width: 145,
            filter: false,
            cellStyle: { color: '#6c757d', fontSize: '11px' }
        },
        {
            headerName: 'Context',
            field: 'id',
            colId: 'context',
            width: 85,
            filter: false,
            sortable: false,
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                const has = snapshotStore.has(params.value);
                if (!has) {
                    return `<a href="/markets/${params.data?.market_id}" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;">Market</a>`;
                }
                return `<button onclick="showContext(${params.value})" class="btn btn-sm btn-outline-info py-0 px-2" style="font-size:11px;">JSON</button>`;
            }
        },
        {
            headerName: 'Action',
            field: 'id',
            colId: 'action',
            width: 150,
            filter: false,
            sortable: false,
            cellStyle: { justifyContent: 'center' },
            cellRenderer: params => {
                if (!params.data || params.data.status !== 'pending') {
                    return '<span class="text-muted" style="font-size:11px;">—</span>';
                }
                
                const encoded = encodeURIComponent(JSON.stringify(params.data));
                return `<div class="d-flex gap-1 align-items-center">
                            <button onclick="openExecuteModal('${encoded}')" class="btn btn-sm btn-primary py-0 px-2" style="font-size:11px;">Execute</button>
                            <button onclick="openIgnoreModal('${encoded}')"  class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;">Ignore</button>
                        </div>`;
            }
        }
    ];

    const gridOptions = {
        columnDefs,
        rowModelType: 'infinite',
        cacheBlockSize: 100,
        maxBlocksInCache: 10,
        infiniteInitialRowCount: 100,
        rowHeight: 48,
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
                const status    = document.getElementById('filterStatus').value;
                const direction = document.getElementById('filterDirection').value;

                fetch(`/api/signals/grid?` + new URLSearchParams({
                    startRow:    params.startRow,
                    endRow:      params.endRow,
                    sortModel:   JSON.stringify(params.sortModel),
                    filterModel: JSON.stringify(params.filterModel),
                    status,
                    direction,
                }), {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    }
                })
                .then(r => r.json())
                .then(data => {
                    data.rows.forEach(row => {
                        if (row.snapshot_data) snapshotStore.set(row.id, row.snapshot_data);
                    });
                    params.successCallback(data.rows, data.totalRows);
                    document.getElementById('gridRowCount').textContent =
                        Number(data.totalRows).toLocaleString() + ' signals';
                })
                .catch(() => params.failCallback());
            }
        });
    }

    const grid = agGrid.createGrid(document.getElementById('signalsGrid'), gridOptions);

    ['filterStatus', 'filterDirection'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            snapshotStore.clear();
            grid.api?.purgeInfiniteCache();
        });
    });
    document.getElementById('refreshGridBtn').addEventListener('click', () => {
        snapshotStore.clear();
        grid.api?.purgeInfiniteCache();
    });

    // ---- Execute Modal ----
    window.openExecuteModal = function(encoded) {
        const rowData = JSON.parse(decodeURIComponent(encoded));
        pendingExecuteId = rowData.id;

        document.getElementById('executeModalQuestion').textContent =
            rowData.market_question ?? `Signal #${rowData.id}`;

        const isYes = rowData.direction?.toLowerCase() === 'yes';
        const dirEl = document.getElementById('executeModalDirection');
        dirEl.textContent = isYes ? '▲ BUY YES' : '▼ BUY NO';
        dirEl.className   = isYes ? 'dir-yes' : 'dir-no';

        document.getElementById('executeModalProb').textContent =
            rowData.market_probability_at_signal !== null && rowData.market_probability_at_signal !== undefined
                ? (parseFloat(rowData.market_probability_at_signal) * 100).toFixed(1) + '%'
                : '—';

        const edge = parseFloat(rowData.edge_at_signal);
        document.getElementById('executeModalEdge').textContent =
            isNaN(edge) ? '—' : (edge >= 0 ? '+' : '') + (edge * 100).toFixed(1) + '%';

        executeModal.show();
    };

    document.getElementById('executeConfirmBtn').addEventListener('click', function () {
        if (!pendingExecuteId) return;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Executing...';
        this.disabled = true;
        executeModal.hide();
        submitFormPost(`/signals/${pendingExecuteId}/execute`);
    });

    // ---- Ignore Modal ----
    window.openIgnoreModal = function(encoded) {
        const rowData = JSON.parse(decodeURIComponent(encoded));
        pendingIgnoreId = rowData.id;
        document.getElementById('ignoreModalQuestion').textContent =
            rowData.market_question ?? `Signal #${rowData.id}`;
        ignoreModal.show();
    };

    document.getElementById('ignoreConfirmBtn').addEventListener('click', function () {
        if (!pendingIgnoreId) return;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Ignoring...';
        this.disabled = true;
        ignoreModal.hide();
        submitFormPost(`/signals/${pendingIgnoreId}/ignore`);
    });

    // ---- Context Modal ----
    window.showContext = function(signalId) {
        const data = snapshotStore.get(signalId);
        modalJsonContent.textContent = data
            ? JSON.stringify(data, null, 2)
            : 'No snapshot data available.';
        contextModal.show();
    };

    // ---- Form POST helper ----
    function submitFormPost(url) {
        const form  = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        const token = document.createElement('input');
        token.type  = 'hidden';
        token.name  = '_token';
        token.value = document.querySelector('meta[name="csrf-token"]').content;
        form.appendChild(token);
        document.body.appendChild(form);
        form.submit();
    }
});
</script>
@endpush