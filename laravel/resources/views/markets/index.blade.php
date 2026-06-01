@extends('layouts.app')

@section('title', 'Markets')
@section('page-title', 'Markets')
@section('page-subtitle', 'All tracked crypto prediction markets')

@section('page-actions')
<div class="d-flex gap-2">
    <select id="filterStatus" class="form-select form-select-sm" style="width: auto">
        <option value="active" selected>Active</option>
        <option value="resolved">Resolved</option>
        <option value="paused">Paused</option>
        <option value="">All Status</option>
    </select>
    <select id="filterSubCategory" class="form-select form-select-sm" style="width: auto">
        <option value="">All Categories</option>
        <option value="bitcoin">Bitcoin</option>
        <option value="ethereum">Ethereum</option>
        <option value="solana">Solana</option>
        <option value="bnb">BNB</option>
        <option value="xrp">XRP</option>
        <option value="defi">DeFi</option>
    </select>
    <span class="text-muted small d-flex align-items-center" id="gridRowCount"></span>
</div>
@endsection

@section('content')
<div class="card p-0" style="height: calc(100vh - 200px); min-height: 500px;">
    <div id="marketsGrid" class="ag-theme-alpine w-100 h-100"></div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/styles/ag-theme-alpine.css">
<style>
    .ag-theme-alpine .ag-header { background-color: #f8fafc; font-weight: 600; }
    .ag-theme-alpine .ag-row-hover { background-color: #f0f7ff !important; cursor: pointer; }
    .prob-high  { color: #2fb344; font-weight: 600; }
    .prob-low   { color: #e63946; font-weight: 600; }
    .prob-mid   { color: #f76707; font-weight: 600; }
    .badge-bitcoin  { background: #f7931a22; color: #f7931a; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-ethereum { background: #627eea22; color: #627eea; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-solana   { background: #9945ff22; color: #9945ff; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-other    { background: #6c757d22; color: #6c757d; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ---- Column Definitions ----
    const columnDefs = [
        {
            headerName: 'Market Question',
            field: 'question',
            flex: 3,
            minWidth: 280,
            filter: 'agTextColumnFilter',
            cellRenderer: params => {
                if (!params.value) return '';
                return `<a href="${params.data.detail_url}"
                           class="text-reset text-decoration-none fw-medium"
                           title="${params.value}">
                           ${params.value}
                        </a>`;
            },
        },
        {
            headerName: 'Category',
            field: 'sub_category',
            width: 110,
            filter: false,
            cellRenderer: params => {
                const cat = params.value || 'other';
                return `<span class="badge-${cat.toLowerCase()}">${cat}</span>`;
            },
        },
        {
            headerName: 'Probability',
            field: 'market_probability_raw',
            width: 140,
            filter: 'agNumberColumnFilter',
            sort: 'desc',
            cellRenderer: params => {
                if (params.value === null || params.value === undefined) return 'N/A';
                const pct   = Math.round(params.value * 1000) / 10;
                const cls   = pct > 60 ? 'prob-high' : (pct < 40 ? 'prob-low' : 'prob-mid');
                const width = Math.round(params.value * 100);
                return `
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:60px;height:6px;background:#e9ecef;border-radius:3px;overflow:hidden;">
                            <div style="width:${width}%;height:100%;border-radius:3px;background:${pct > 60 ? '#2fb344' : (pct < 40 ? '#e63946' : '#f76707')};"></div>
                        </div>
                        <span class="${cls}">${pct}%</span>
                    </div>`;
            },
        },
        {
            headerName: 'Volume (USD)',
            field: 'volume_usd_raw',
            width: 130,
            filter: 'agNumberColumnFilter',
            valueFormatter: p => p.value ? '$' + Number(p.value).toLocaleString('en-US', {maximumFractionDigits: 0}) : 'N/A',
        },
        {
            headerName: 'Liquidity',
            field: 'liquidity_usd',
            width: 120,
            filter: false,
        },
        {
            headerName: 'Traders',
            field: 'num_traders',
            width: 100,
            filter: 'agNumberColumnFilter',
        },
        {
            headerName: 'AI Prob',
            field: 'ai_probability',
            width: 90,
            filter: false,
            cellStyle: { color: '#ae3ec9', fontWeight: 600 },
        },
        {
            headerName: 'Edge',
            field: 'edge',
            width: 90,
            filter: false,
            cellStyle: params => ({
                color: params.value && params.value !== 'N/A'
                    ? (params.value.startsWith('+') ? '#2fb344' : '#e63946')
                    : '#6c757d',
                fontWeight: 600,
            }),
        },
        {
            headerName: 'Expires (UTC)',
            field: 'end_date',
            width: 155,
            filter: false,
            cellStyle: { color: '#6c757d', fontSize: '12px' },
        },
        {
            headerName: 'Last Sync',
            field: 'last_synced_at',
            width: 110,
            filter: false,
            cellStyle: { color: '#6c757d', fontSize: '12px' },
        },
    ];

    // ---- Grid Options ----
    const gridOptions = {
        columnDefs,
        rowModelType: 'infinite',
        cacheBlockSize: 100,
        maxBlocksInCache: 10,
        infiniteInitialRowCount: 100,
        defaultColDef: {
            sortable:    true,
            resizable:   true,
            filter:      true,
            floatingFilter: true,
        },
        onRowClicked: params => {
            if (params.data?.detail_url) {
                window.location.href = params.data.detail_url;
            }
        },
        onGridReady: params => loadData(params.api),
    };

    // ---- Data source ----
    function buildDatasource(api) {
        return {
            getRows(params) {
                const sortModel   = params.sortModel;
                const filterModel = params.filterModel;
                const status      = document.getElementById('filterStatus').value;
                const subCat      = document.getElementById('filterSubCategory').value;

                fetch(`/api/markets/grid?` + new URLSearchParams({
                    startRow:    params.startRow,
                    endRow:      params.endRow,
                    sortModel:   JSON.stringify(sortModel),
                    filterModel: JSON.stringify(filterModel),
                    status:      status,
                    sub_category: subCat,
                }), {
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                })
                .then(r => r.json())
                .then(data => {
                    params.successCallback(data.rows, data.totalRows);
                    document.getElementById('gridRowCount').textContent =
                        Number(data.totalRows).toLocaleString() + ' markets';
                })
                .catch(() => params.failCallback());
            }
        };
    }

    function loadData(api) {
        api.setGridOption('datasource', buildDatasource(api));
    }

    // ---- Create grid ----
    const gridEl = document.getElementById('marketsGrid');
    const grid   = agGrid.createGrid(gridEl, gridOptions);

    // ---- Filter change handlers ----
    ['filterStatus', 'filterSubCategory'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            grid.api?.purgeInfiniteCache();
        });
    });
});
</script>
@endpush
