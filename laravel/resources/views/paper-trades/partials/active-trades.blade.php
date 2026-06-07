{{-- ============================================================
     Active Trades — AG Grid
     Data diambil via AJAX dari /api/paper-trades/active
============================================================ --}}
<div class="row row-deck row-cards mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    Active Trades
                    <span class="badge bg-blue ms-2" id="activeTradesCount">—</span>
                </h3>
                <div class="card-options">
                    <span class="text-muted small" id="activeTradesLastUpdated"></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="activeTradesGrid"
                     class="ag-theme-alpine w-100"
                     style="height: 340px;">
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const gridEl = document.getElementById('activeTradesGrid');
    if (!gridEl || typeof agGrid === 'undefined') return;

    const columnDefs = [
        {
            headerName: 'Market',
            field: 'market',
            flex: 3,
            minWidth: 180,
            tooltipField: 'market_full',
            cellRenderer: params => {
                const dir = params.data.direction;
                const badge = dir === 'YES'
                    ? '<span class="badge bg-success-lt me-1">YES</span>'
                    : '<span class="badge bg-danger-lt me-1">NO</span>';
                return badge + params.value;
            },
        },
        {
            headerName: 'Entry',
            field: 'entry_price',
            width: 80,
            cellRenderer: params => params.value + '%',
        },
        {
            headerName: 'Current',
            field: 'current_price',
            width: 85,
            cellRenderer: params => params.value + '%',
        },
        {
            headerName: 'Size',
            field: 'position_size',
            width: 90,
            cellRenderer: params => '$' + params.value.toFixed(2),
        },
        {
            headerName: 'PnL $',
            field: 'pnl_usd',
            width: 90,
            cellClass: params => params.value >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold',
            cellRenderer: params => (params.value >= 0 ? '+' : '') + '$' + params.value.toFixed(2),
            sort: 'desc',
        },
        {
            headerName: 'PnL %',
            field: 'pnl_percent',
            width: 85,
            cellClass: params => params.value >= 0 ? 'text-success' : 'text-danger',
            cellRenderer: params => (params.value >= 0 ? '+' : '') + params.value.toFixed(2) + '%',
        },
        {
            headerName: 'Score',
            field: 'signal_score',
            width: 80,
            cellRenderer: params => params.value ? params.value + '%' : '—',
        },
        {
            headerName: 'TP',
            field: 'take_profit',
            width: 72,
            cellRenderer: params => params.value ? params.value + '%' : '—',
        },
        {
            headerName: 'SL',
            field: 'stop_loss',
            width: 72,
            cellRenderer: params => params.value ? params.value + '%' : '—',
        },
        {
            headerName: 'Holding',
            field: 'holding_time',
            width: 85,
        },
        {
            headerName: 'Status',
            field: 'status',
            width: 110,
            cellRenderer: params => {
                const map = {
                    'OPEN':        'bg-blue-lt text-blue',
                    'PARTIAL':     'bg-yellow-lt text-yellow',
                };
                const cls = map[params.value] || 'bg-secondary-lt';
                return `<span class="badge ${cls}">${params.value}</span>`;
            },
        },
        {
            headerName: '',
            field: 'id',
            width: 70,
            sortable: false,
            cellRenderer: params => {
                return `<a href="/paper-trades/${params.value}" class="btn btn-sm btn-ghost-secondary py-0 px-2">Detail</a>`;
            },
        },
    ];

    const gridOptions = {
        columnDefs,
        defaultColDef: {
            sortable: true,
            resizable: true,
            suppressMovable: false,
        },
        rowModelType: 'serverSide',
        serverSideDatasource: {
            getRows(params) {
                const body = {
                    startRow:  params.request.startRow,
                    endRow:    params.request.endRow,
                    sortModel: JSON.stringify(params.request.sortModel),
                };
                const qs = new URLSearchParams(body).toString();

                fetch('/api/paper-trades/active?' + qs, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    params.success({ rowData: data.rows, rowCount: data.totalRows });
                    document.getElementById('activeTradesCount').textContent = data.totalRows;
                    document.getElementById('activeTradesLastUpdated').textContent =
                        'Updated ' + new Date().toLocaleTimeString();
                })
                .catch(() => params.fail());
            }
        },
        cacheBlockSize: 50,
        pagination: true,
        paginationPageSize: 25,
        suppressPaginationPanel: false,
        tooltipShowDelay: 300,
        suppressCellFocus: true,
    };

    agGrid.createGrid(gridEl, gridOptions);

    // Expose refresh function untuk auto-refresh
    window.refreshActiveTradesGrid = function () {
        gridEl.__agGrid?.api?.refreshServerSide({ purge: true });
    };
})();
</script>
@endpush
