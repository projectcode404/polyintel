{{-- ============================================================
     Closed Trades — AG Grid
     Data diambil via AJAX dari /api/paper-trades/closed
============================================================ --}}
<div class="row row-deck row-cards mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    Closed Trades
                    <span class="badge bg-secondary ms-2" id="closedTradesCount">—</span>
                </h3>
                <div class="card-options d-flex gap-2">
                    <select id="closedTradesExitFilter" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Exits</option>
                        <option value="TAKE_PROFIT">Take Profit</option>
                        <option value="STOPPED">Stop Loss</option>
                        <option value="SMART_EXIT">Smart Exit</option>
                        <option value="EXPIRED">Expired</option>
                        <option value="CLOSED">Manual</option>
                    </select>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="closedTradesGrid"
                     class="ag-theme-alpine w-100"
                     style="height: 360px;">
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const gridEl = document.getElementById('closedTradesGrid');
    if (!gridEl || typeof agGrid === 'undefined') return;

    const exitBadge = {
        'TAKE_PROFIT': 'bg-success-lt text-success',
        'STOPPED':     'bg-danger-lt text-danger',
        'SMART_EXIT':  'bg-purple-lt text-purple',
        'EXPIRED':     'bg-orange-lt text-orange',
        'CLOSED':      'bg-secondary-lt',
    };

    const columnDefs = [
        {
            headerName: 'Market',
            field: 'market',
            flex: 3,
            minWidth: 180,
            tooltipField: 'market_full',
            cellRenderer: params => {
                if (!params.data) return params.value ?? '';
                const dir = params.data.direction;
                const badge = dir === 'YES'
                    ? '<span class="badge bg-success-lt me-1">YES</span>'
                    : '<span class="badge bg-danger-lt me-1">NO</span>';
                return badge + (params.value ?? '');
            },
        },
        {
            headerName: 'Entry',
            field: 'entry_price',
            width: 80,
            cellRenderer: params => params.value + '%',
        },
        {
            headerName: 'Exit',
            field: 'exit_price',
            width: 80,
            cellRenderer: params => params.value ? params.value + '%' : '—',
        },
        {
            headerName: 'PnL $',
            field: 'pnl_usd',
            width: 95,
            sort: 'desc',
            cellClass: params => params.value >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold',
            cellRenderer: params => params.value != null ? (params.value >= 0 ? '+' : '') + '$' + params.value.toFixed(2) : '—',
        },
        {
            headerName: 'ROI',
            field: 'roi_percent',
            width: 85,
            cellClass: params => params.value >= 0 ? 'text-success' : 'text-danger',
            cellRenderer: params => params.value != null ? (params.value >= 0 ? '+' : '') + params.value.toFixed(2) + '%' : '—',
        },
        {
            headerName: 'Exit Reason',
            field: 'exit_reason',
            width: 120,
            cellRenderer: params => {
                const cls = exitBadge[params.value] || 'bg-secondary-lt';
                return `<span class="badge ${cls}">${params.value}</span>`;
            },
        },
        {
            headerName: 'Duration',
            field: 'duration',
            width: 90,
            cellRenderer: params => params.value || '—',
        },
        {
            headerName: 'Closed At',
            field: 'exited_at',
            width: 130,
            cellRenderer: params => params.value || '—',
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

    let currentExitFilter = '';

    function createDatasource() {
        return {
            getRows(params) {
                const body = {
                    startRow:   params.startRow,
                    endRow:     params.endRow,
                    sortModel:  JSON.stringify(params.sortModel ?? []),
                    exitReason: currentExitFilter,
                };
                const qs = new URLSearchParams(body).toString();
                fetch('/api/paper-trades/closed?' + qs, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    params.successCallback(data.rows, data.totalRows);
                    document.getElementById('closedTradesCount').textContent = data.totalRows;
                })
                .catch(() => params.failCallback());
            }
        };
    }

    const gridOptions = {
        columnDefs,
        defaultColDef: {
            sortable: true,
            resizable: true,
        },
        rowModelType: 'infinite',
        datasource: createDatasource(),
        cacheBlockSize: 50,
        maxBlocksInCache: 10,
        pagination: true,
        paginationPageSize: 25,
        paginationPageSizeSelector: [10, 25, 50, 100],
        tooltipShowDelay: 300,
        suppressCellFocus: true,
    };

    const grid = agGrid.createGrid(gridEl, gridOptions);

    // Exit filter
    document.getElementById('closedTradesExitFilter')?.addEventListener('change', function () {
        currentExitFilter = this.value;
        grid.api.setDatasource(createDatasource());
    });
})();
</script>
@endpush
