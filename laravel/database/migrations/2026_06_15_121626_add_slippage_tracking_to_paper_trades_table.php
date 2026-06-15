<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->decimal('midpoint_price_at_entry', 8, 6)->nullable()
                ->comment('market_probability at entry time, before slippage adjustment');
            $table->decimal('slippage_at_entry', 8, 6)->nullable()
                ->comment('entry_price minus midpoint adjusted for direction; positive = cost paid');
            $table->string('entry_price_source', 20)->nullable()
                ->comment('best_ask_snapshot | best_bid_snapshot | midpoint_fallback');
        });
    }

    public function down(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->dropColumn(['midpoint_price_at_entry', 'slippage_at_entry', 'entry_price_source']);
        });
    }
};
