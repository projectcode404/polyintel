<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add CLOB token IDs to markets table.
     *
     * These come from Gamma API's `clobTokenIds` field (already fetched
     * by markets_collector.py on every sync — no additional API calls
     * needed). Required for live trading executor to place orders via
     * Polymarket CLOB API, which identifies markets by token_id rather
     * than condition_id.
     *
     * clobTokenIds is a JSON array paired with `outcomes` (e.g.
     * ["Yes","No"] -> [yes_token_id, no_token_id]). Matching is done by
     * outcome label, not array index, since outcome ordering is not
     * guaranteed to always be Yes-first across all markets.
     */
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->string('clob_token_id_yes')->nullable()->after('condition_id');
            $table->string('clob_token_id_no')->nullable()->after('clob_token_id_yes');
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn(['clob_token_id_yes', 'clob_token_id_no']);
        });
    }
};
