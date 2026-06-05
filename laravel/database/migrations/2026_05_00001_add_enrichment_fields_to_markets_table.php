<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: add_enrichment_fields_to_markets_table
 *
 * Menambah kolom-kolom dari Gamma API yang belum di-collect sebelumnya.
 * Semua kolom baru NULLABLE agar backward compatible dengan data lama.
 *
 * Konteks:
 *   - volume_24h_usd : dari Gamma field `volume24hr` (rolling 24h volume)
 *   - best_bid       : dari Gamma field `bestBid`
 *   - best_ask       : dari Gamma field `bestAsk`
 *   - spread         : dari Gamma field `spread`
 *   - price_change_1h: dari Gamma field `oneHourPriceChange`
 *   - price_change_1d: dari Gamma field `oneDayPriceChange`
 *
 *   num_traders diubah dari NOT NULL DEFAULT 0 ke NULLABLE
 *   karena Gamma API tidak expose field traders.
 *   Nilai NULL berarti "belum diketahui", bukan "0 trader".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            // Volume 24 jam rolling — data sangat berguna untuk analytics
            $table->decimal('volume_24h_usd', 20, 2)->default(0)->after('volume_usd');

            // Orderbook snapshot dari Gamma API
            // Lebih stale dari CLOB (~1-5 min) tapi tanpa extra API call
            $table->decimal('best_bid', 8, 6)->nullable()->after('liquidity_usd');
            $table->decimal('best_ask', 8, 6)->nullable()->after('best_bid');
            $table->decimal('spread', 8, 6)->nullable()->after('best_ask');

            // Price movement — untuk signal generation
            $table->decimal('price_change_1h', 10, 6)->nullable()->after('spread');
            $table->decimal('price_change_1d', 10, 6)->nullable()->after('price_change_1h');

            // num_traders: ubah ke nullable
            // Gamma API tidak expose traders count — field ini tidak bisa diisi
            // Perubahan: NOT NULL DEFAULT 0 → NULLABLE
            $table->integer('num_traders')->nullable()->change();
        });

        // Index untuk analytics queries
        Schema::table('markets', function (Blueprint $table) {
            $table->index('volume_24h_usd', 'markets_volume_24h_idx');
            $table->index('price_change_1d', 'markets_price_change_1d_idx');
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropIndex('markets_volume_24h_idx');
            $table->dropIndex('markets_price_change_1d_idx');

            $table->dropColumn([
                'volume_24h_usd',
                'best_bid',
                'best_ask',
                'spread',
                'price_change_1h',
                'price_change_1d',
            ]);

            // Kembalikan num_traders ke NOT NULL
            $table->integer('num_traders')->default(0)->nullable(false)->change();
        });
    }
};