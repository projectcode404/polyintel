<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row settings table for paper trading portfolio manager.
     * Only one active row should exist at any time.
     * Enforced at application layer via PaperTradeSetting::current().
     */
    public function up(): void
    {
        Schema::create('paper_trade_settings', function (Blueprint $table) {
            $table->id();

            // --- Capital ---
            $table->decimal('initial_capital', 15, 2)->default(1000.00);

            // --- Portfolio Limits ---
            $table->decimal('max_portfolio_exposure_percent', 5, 2)->default(50.00);
            $table->unsignedInteger('max_concurrent_trades')->default(10);
            $table->decimal('reserve_cash_percent', 5, 2)->default(20.00);
            $table->unsignedInteger('max_position_per_market')->default(1);
            $table->unsignedInteger('market_cooldown_minutes')->default(60);

            // --- Position Sizing ---
            $table->enum('position_size_mode', ['fixed_amount', 'fixed_percent', 'dynamic'])
                ->default('fixed_percent');
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->decimal('fixed_percent', 5, 2)->nullable()->default(2.00);
            $table->boolean('enable_dynamic_position_size')->default(false);

            // --- Signal Filters ---
            $table->boolean('enable_top_signal_filter')->default(true);
            $table->unsignedInteger('max_signals_per_cycle')->default(10);
            $table->decimal('minimum_signal_score', 8, 4)->default(0.7000);

            // --- Take Profit ---
            $table->boolean('enable_take_profit')->default(true);
            $table->enum('take_profit_mode', ['fixed_percent', 'r_multiple'])->default('r_multiple');
            $table->decimal('take_profit_r1', 5, 2)->nullable()->default(1.00);
            $table->decimal('take_profit_r2', 5, 2)->nullable();
            $table->decimal('take_profit_r3', 5, 2)->nullable();

            // --- Stop Loss ---
            $table->boolean('enable_stop_loss')->default(true);
            $table->enum('stop_loss_mode', ['fixed_percent', 'r_multiple'])->default('r_multiple');
            $table->decimal('stop_loss_value', 5, 2)->default(1.00);

            // --- Breakeven ---
            $table->boolean('enable_move_to_breakeven')->default(true);
            $table->decimal('breakeven_trigger_r', 5, 2)->default(1.00);

            // --- Partial Take Profit ---
            $table->boolean('enable_partial_take_profit')->default(false);
            $table->decimal('partial_tp1_percent', 5, 2)->nullable()->default(50.00);
            $table->decimal('partial_tp2_percent', 5, 2)->nullable()->default(30.00);
            $table->decimal('partial_tp3_percent', 5, 2)->nullable()->default(20.00);

            // --- Smart Exit ---
            $table->boolean('enable_smart_exit')->default(true);

            // --- Preset ---
            $table->enum('preset', ['conservative', 'balanced', 'aggressive', 'custom'])
                ->default('balanced');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trade_settings');
    }
};