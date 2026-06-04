{{-- Shared list/grid styles — markets is the reference design system --}}
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
    .badge-rule     { background: #ae3ec922; color: #ae3ec9; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
    .list-table-card {
        height: calc(100vh - 200px);
        min-height: 500px;
        display: flex;
        flex-direction: column;
    }
    .list-table-card .table-scroll {
        flex: 1;
        overflow: auto;
    }
    .list-table {
        width: 100%;
        font-size: 13px;
        margin-bottom: 0;
    }
    .list-table thead th {
        background-color: #f8fafc;
        font-weight: 600;
        padding: 10px 12px;
        border-bottom: 1px solid #dde2eb;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .list-table tbody td {
        padding: 10px 12px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
    }
    .list-table tbody tr:hover {
        background-color: #f0f7ff !important;
    }
    .list-table tbody tr[data-hidden="true"] {
        display: none;
    }
    .list-table .market-link {
        color: inherit;
        text-decoration: none;
        font-weight: 500;
    }
    .list-table .market-link:hover {
        color: var(--tblr-primary);
        text-decoration: underline;
    }
    .list-table .text-meta {
        color: #6c757d;
        font-size: 12px;
    }
    .edge-positive { color: #2fb344; font-weight: 600; }
    .edge-negative { color: #e63946; font-weight: 600; }
</style>
