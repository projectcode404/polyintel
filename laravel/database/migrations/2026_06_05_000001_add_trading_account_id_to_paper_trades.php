<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->foreignId('trading_account_id')
                ->nullable()
                ->after('id')
                ->constrained('trading_accounts')
                ->nullOnDelete();

            $table->index(['trading_account_id', 'status'], 'trades_account_status_idx');
        });

        // Assign semua trade NULL ke account id=1 (default account)
        DB::table('paper_trades')
            ->whereNull('trading_account_id')
            ->update(['trading_account_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->dropForeign(['trading_account_id']);
            $table->dropColumn('trading_account_id');
        });
    }
};