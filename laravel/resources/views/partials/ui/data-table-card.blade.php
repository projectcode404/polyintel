{{--
    Full-height table card matching markets AG Grid container.
    @param string $tableId Optional id for the table element
--}}
<div class="card p-0 list-table-card">
    <div class="table-scroll">
        <div class="table-responsive">
            <table @if(!empty($tableId)) id="{{ $tableId }}" @endif class="table list-table text-nowrap">
                {{ $slot }}
            </table>
        </div>
    </div>
    @isset($footer)
    <div class="card-footer d-flex align-items-center">
        {{ $footer }}
    </div>
    @endisset
</div>
