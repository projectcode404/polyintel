<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE paper_trades
            SET status = CASE
                WHEN LOWER(status) = 'open'    THEN 'OPEN'
                WHEN LOWER(status) = 'closed'  THEN 'CLOSED'
                WHEN LOWER(status) = 'partial' THEN 'PARTIAL'
                WHEN LOWER(status) = 'stopped' THEN 'STOPPED'
                ELSE UPPER(status)
            END
            WHERE status != UPPER(status)
        ");

        // PostgreSQL only
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE paper_trades
                ALTER COLUMN status SET DEFAULT 'OPEN'
            ");
            DB::statement("
                COMMENT ON COLUMN paper_trades.status IS
                'OPEN | PARTIAL | CLOSED | STOPPED | TAKE_PROFIT | SMART_EXIT | EXPIRED'
            ");
        }
    }

    public function down(): void
    {
        DB::statement("
            UPDATE paper_trades
            SET status = LOWER(status)
            WHERE status = UPPER(status)
        ");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE paper_trades
                ALTER COLUMN status SET DEFAULT 'open'
            ");
        }
    }
};
