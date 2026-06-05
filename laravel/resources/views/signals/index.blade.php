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

<div class="modal fade" id="contextModal" tabindex="-1" aria-labelledby="contextModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contextModalLabel">Snapshot Context Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light">
                <pre><code id="modalJsonContent" class="json text-dark" style="font-size: 12px;"></code></pre>
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
    .badge-rule { background: #eef2f7; color: #495057; padding: 2px 6px; border-radius: 4px; font-weight: 500; font-size: 11px; }
    .edge-positive { color: #2fb344; font-weight: 600; }
    .edge-negative { color: #e63946; font-weight: 600; }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const contextModal    = new bootstrap.Modal(document.getElementById('contextModal'));
    const modalJsonContent = document.getElementById('modalJsonContent');
    
    // ---- Snapshot data store (populated saat grid load) ----
    const snapshotStore = new Map();

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
            headerName: 'Rule Trigger',
            field: 'trigger_source',
            width: 130,
            filter: 'agTextColumnFilter',
            cellRenderer: params => params.value
                ? `<span class="badge-rule">${params.value}</span>`
                : ''
        },
        {
            headerName: 'Direction',
            field: 'direction',
            width: 100,
            filter: false,
            cellRenderer: params => {
                if (!params.value) return '';
                const isYes = params.value.toLowerCase() === 'yes';
                return `<span class="${isYes ? 'prob-high' : 'prob-low'} fw-bold" style="font-size:12px;">${isYes ? 'BUY YES' : 'BUY NO'}</span>`;
            }
        },
        {
            headerName: 'Prob Entry',
            field: 'market_probability_at_signal',
            width: 130,
            filter: 'agNumberColumnFilter',
            cellRenderer: params => {
                if (params.value === null || params.value === undefined) return 'N/A';
                const rawVal = parseFloat(params.value);
                const pct    = Math.round(rawVal * 1000) / 10;
                const width  = Math.round(rawVal * 100);
                const color  = pct > 60 ? '#2fb344' : (pct < 40 ? '#e63946' : '#f76707');
                const cls    = pct > 60 ? 'prob-high' : (pct < 40 ? 'prob-low' : 'prob-mid');
                return `
                    <div class="d-flex align-items-center gap-2" style="height:100%;">
                        <div style="width:40px;height:5px;background:#e9ecef;border-radius:2px;overflow:hidden;flex-shrink:0;">
                            <div style="width:${width}%;height:100%;background:${color};"></div>
                        </div>
                        <span class="${cls} fw-medium" style="font-size:12px;">${pct}%</span>
                    </div>`;
            }
        },
        {
            headerName: 'Edge',
            field: 'edge_at_signal',
            width: 90,
            filter: 'agNumberColumnFilter',
            cellRenderer: params => {
                if (params.value === null || params.value === undefined) return '0.0%';
                const pct        = (parseFloat(params.value) * 100).toFixed(1);
                const isPositive = pct >= 0;
                return `<span class="${isPositive ? 'edge-positive' : 'edge-negative'}">${isPositive ? '+' : ''}${pct}%</span>`;
            }
        },
        {
            headerName: 'Status',
            field: 'status',
            width: 100,
            filter: false,
            cellRenderer: params => {
                if (!params.value) return '';
                const map = {
                    pending:   'bg-warning-lt',
                    active:    'bg-success-lt',
                    cancelled: 'bg-secondary-lt',
                    closed:    'bg-blue-lt',
                };
                const bgClass = map[params.value] ?? 'bg-secondary-lt';
                return `<span class="badge ${bgClass}">${params.value.charAt(0).toUpperCase() + params.value.slice(1)}</span>`;
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
            // FIX: gunakan signal ID untuk lookup ke snapshotStore
            headerName: 'Context',
            field: 'id',
            width: 90,
            filter: false,
            sortable: false,
            cellRenderer: params => {
                const hasData = snapshotStore.has(params.value);
                if (!hasData) return '<span class="text-muted">—</span>';
                return `<button 
                    class="btn btn-sm btn-outline-secondary py-0 px-2" 
                    style="font-size:11px;"
                    onclick="showContext(${params.value})"
                >JSON</button>`;
            }
        },
        {
            headerName: 'Action',
            field: 'id',
            colId: 'action',          // colId unik agar tidak clash dengan kolom Context
            width: 140,
            filter: false,
            sortable: false,
            cellRenderer: params => {
                if (!params.data || params.data.status !== 'pending') return '<span class="text-muted">—</span>';
                const signalId = params.value;
                return `
                    <div class="d-flex gap-1 align-items-center h-100">
                        <button onclick="executeSignal(${signalId}, this)" class="btn btn-sm btn-primary py-0 px-2" style="font-size:11px;">Execute</button>
                        <button onclick="ignoreSignal(${signalId}, this)" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;">Ignore</button>
                    </div>`;
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
                        'Accept':       'application/json'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    // FIX: Populate snapshotStore dari response
                    data.rows.forEach(row => {
                        if (row.snapshot_data) {
                            snapshotStore.set(row.id, row.snapshot_data);
                        }
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

    // ---- Filters & Refresh ----
    ['filterStatus', 'filterDirection'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            snapshotStore.clear(); // reset store saat filter berubah
            grid.api?.purgeInfiniteCache();
        });
    });

    document.getElementById('refreshGridBtn').addEventListener('click', () => {
        snapshotStore.clear();
        grid.api?.purgeInfiniteCache();
    });

    // ---- Context Modal Handler ----
    window.showContext = function(signalId) {
        const data = snapshotStore.get(signalId);
        if (!data) {
            modalJsonContent.textContent = 'No snapshot data available.';
        } else {
            modalJsonContent.textContent = JSON.stringify(data, null, 2);
        }
        contextModal.show();
    };

    // ---- Signal Actions ----
    window.executeSignal = function(id, btn) {
        if (confirm('Execute paper trade for this signal?')) {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Exec...';
            btn.disabled  = true;
            submitFormPost(`/signals/${id}/execute`);
        }
    };

    window.ignoreSignal = function(id, btn) {
        if (confirm('Ignore this signal?')) {
            btn.innerHTML = '...';
            btn.disabled  = true;
            submitFormPost(`/signals/${id}/ignore`);
        }
    };

    function submitFormPost(url) {
        const form  = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        const token  = document.createElement('input');
        token.type   = 'hidden';
        token.name   = '_token';
        token.value  = document.querySelector('meta[name="csrf-token"]').content;
        form.appendChild(token);
        document.body.appendChild(form);
        form.submit();
    }
});
</script>
@endpush