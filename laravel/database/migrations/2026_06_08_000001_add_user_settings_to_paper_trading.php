<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah user_id ke paper_trade_settings
        Schema::table('paper_trade_settings', function (Blueprint $table) {
            $table->foreignId('user_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('users')
                  ->onDelete('cascade');
        });

        // 2. Tambah paper_trade_setting_id ke trading_accounts
        Schema::table('trading_accounts', function (Blueprint $table) {
            $table->foreignId('paper_trade_setting_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('paper_trade_settings')
                  ->onDelete('set null');
        });

        // 3. Assign existing settings (id=1) ke semua existing trading accounts
        $settingId = DB::table('paper_trade_settings')->value('id');
        if ($settingId) {
            DB::table('trading_accounts')->update([
                'paper_trade_setting_id' => $settingId,
            ]);
        }

        // 4. Assign existing trading accounts user_id ke settings
        $accounts = DB::table('trading_accounts')->get();
        foreach ($accounts as $account) {
            DB::table('paper_trade_settings')
                ->where('id', $account->paper_trade_setting_id)
                ->update(['user_id' => $account->user_id]);
        }
    }

    public function down(): void
    {
        Schema::table('trading_accounts', function (Blueprint $table) {
            $table->dropForeign(['paper_trade_setting_id']);
            $table->dropColumn('paper_trade_setting_id');
        });

        Schema::table('paper_trade_settings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
