<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->json('snapshot_data')->nullable()
                  ->comment('Stores context at entry: rule_name, confidence, price_entry, volume_7d, oi_change, momentum');
        });

        Schema::table('paper_trades', function (Blueprint $table) {
            // trading_account_id is required, but we make it nullable first to allow migrating existing data 
            // if any exists, though Sprint 4 is just starting. 
            $table->foreignId('trading_account_id')->nullable()->constrained('trading_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->dropForeign(['trading_account_id']);
            $table->dropColumn('trading_account_id');
        });

        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn('snapshot_data');
        });
    }
};
