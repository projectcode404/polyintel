<?php

/**
 * PATCH #2 — SmartExitEngineService.php
 *
 * FILE: app/Services/SmartExitEngineService.php
 *
 * ROOT CAUSE
 * ----------
 * isMomentumReversing() membaca $trade->signal->momentum_24h_percent.
 * Nilai ini adalah snapshot data SAAT SIGNAL DIBUAT, bukan kondisi saat ini.
 *
 * Dampak:
 * - Signal yang dibuat saat momentum = -15% akan langsung menutup trade
 *   di siklus monitoring pertama (1-5 menit setelah dibuka)
 * - Avg Holding Time = 0.02 jam (~1.2 menit) disebabkan oleh ini
 * - normalizeMomentum() menggunakan abs(), sehingga momentum -15%
 *   berkontribusi score tinggi (diterima) tapi langsung ditutup (ironi)
 *
 * FIX
 * ---
 * isMomentumReversing() harus membaca dari sumber data terkini.
 *
 * Sumber data yang tersedia:
 *   1. MarketSnapshot: probability_yes, liquidity_usd, spread, volume — TERSEDIA
 *   2. MarketDailyStat: momentum_24h_percent — tersedia tapi hanya ada 1 row per hari
 *   3. Signal (lama): momentum_24h_percent — data statis, jangan dipakai
 *
 * MarketSnapshot TIDAK punya kolom momentum_24h_percent.
 * MarketDailyStat punya, tapi perlu join.
 *
 * STRATEGI FIX:
 * Hitung momentum sendiri dari dua snapshot terbaru:
 *   momentum = (latest_price - price_24h_ago) / price_24h_ago * 100
 *
 * Ini lebih akurat dari MarketDailyStat (yang hanya update harian)
 * dan sepenuhnya real-time berdasarkan data yang sudah ada.
 *
 * FALLBACK:
 * - Jika snapshot terbaru tidak ada → return false (tidak trigger exit)
 * - Jika snapshot 24h lalu tidak ada → return false (data tidak cukup)
 * - Threshold tetap -10% (tidak berubah)
 *
 * DIFF isMomentumReversing() — method yang berubah:
 *
 * SEBELUM:
 *   private function isMomentumReversing(PaperTrade $trade): bool
 *   {
 *       $signal = $trade->signal;
 *       if (! $signal || $signal->momentum_24h_percent === null) {
 *           return false;
 *       }
 *       return (float) $signal->momentum_24h_percent < -10.0;
 *   }
 *
 * SESUDAH: lihat implementasi di bawah.
 *
 * TEST IMPLIKASI
 * --------------
 * Test existing "it_triggers_partial_exit_on_momentum_reversal" perlu diupdate:
 * - Hapus setup signal dengan momentum_24h_percent = -15
 * - Buat dua MarketSnapshot: satu "sekarang" dan satu "24 jam lalu"
 * - Lihat test file patch-05 untuk versi yang diperbarui
 *
 * RISIKO
 * ------
 * MEDIUM: Logic berubah dari "baca field" menjadi "query dua snapshot".
 * - Ada N+1 query risk per trade per siklus monitoring
 * - Mitigasi: SmartExitMonitor sudah eager load ->with(['signal', 'market', 'history'])
 *   tapi BELUM eager load snapshots. Perlu tambah 'market.snapshots' pada eager load,
 *   atau gunakan latestSnapshot relationship yang sudah ada.
 * - Untuk MVP, query per trade per siklus masih acceptable (jumlah trade terbatas)
 *
 * RESET DATA: TIDAK diperlukan untuk patch ini (logic change, bukan data fix).
 */

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\Market;
use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\PaperTradeSetting;
use App\Models\Signal;

final class SmartExitEngineService
{
    // =========================================================================
    // Main Evaluation
    // =========================================================================

    public function evaluate(PaperTrade $trade, float $currentPrice): SmartExitDecision
    {
        $entryPrice = (float) $trade->entry_price;
        $stopLoss   = $trade->stop_loss_price  ? (float) $trade->stop_loss_price  : null;
        $takeProfit = $trade->take_profit_price ? (float) $trade->take_profit_price : null;
        $breakeven  = $trade->breakeven_price   ? (float) $trade->breakeven_price   : null;

        // --- Priority 1: Stop Loss ---
        if ($stopLoss !== null && $currentPrice <= $stopLoss) {
            return SmartExitDecision::fullExit(
                "Stop loss hit: price {$currentPrice} <= SL {$stopLoss}"
            );
        }

        // --- Priority 2: Take Profit ---
        if ($takeProfit !== null && $currentPrice >= $takeProfit) {
            if ($this->hasTp1Fired($trade)) {
                // BUG FIX: TP2 must only fire ONCE. Without this check,
                // TP2 re-fires every monitoring cycle as long as
                // currentPrice >= TP2 price, causing repeated partial
                // closes (geometric decay of shares) and inflated
                // cumulative realized PnL — observed as ROI > 1000%.
                if ($this->hasTp2Fired($trade)) {
                    return SmartExitDecision::noAction();
                }

                $tp2 = $this->getTp2Price($trade, $entryPrice);
                if ($tp2 !== null && $currentPrice >= $tp2) {
                    return SmartExitDecision::partialExit(
                        "TP2 hit: price {$currentPrice} >= TP2 {$tp2}"
                    );
                }
            } else {
                return SmartExitDecision::partialExit(
                    "TP1 hit: price {$currentPrice} >= TP1 {$takeProfit}"
                );
            }
        }

        // --- Priority 3: Move to Breakeven ---
        if ($breakeven !== null
            && $currentPrice >= $breakeven
            && ! $this->hasBreakevenMoved($trade)
            && $stopLoss !== null
            && $stopLoss < $entryPrice
        ) {
            return SmartExitDecision::moveToBreakeven(
                "Breakeven trigger hit: price {$currentPrice} >= trigger {$breakeven}"
            );
        }

        // --- Priority 4: Smart Exit Rules ---
        if ($this->isSmartExitEnabled($trade)) {
            return $this->evaluateSmartRules($trade, $currentPrice);
        }

        return SmartExitDecision::noAction();
    }

    // =========================================================================
    // Smart Exit Rules
    // =========================================================================

    private function evaluateSmartRules(PaperTrade $trade, float $currentPrice): SmartExitDecision
    {
        if ($this->isNearExpiry($trade)) {
            return SmartExitDecision::fullExit('Near expiry: less than 6 hours remaining');
        }

        if ($this->hasOppositeSignal($trade)) {
            return SmartExitDecision::fullExit('Signal reversal: opposite signal with higher score detected');
        }

        if ($this->isMomentumReversing($trade) && ! $this->hasRecentSmartExit($trade)) {
            return SmartExitDecision::partialExit('Momentum reversal: momentum < -10%');
        }

        if ($this->isLiquidityDeteriorating($trade) && ! $this->hasRecentSmartExit($trade)) {
            return SmartExitDecision::partialExit('Liquidity deterioration: < 50% of entry liquidity');
        }

        if ($this->isSpreadWidening($trade)) {
            return SmartExitDecision::partialExit('Spread widening: > 2x entry spread');
        }

        return SmartExitDecision::noAction();
    }

    // =========================================================================
    // Rule Implementations
    // =========================================================================

    /**
     * Rule 1: Momentum reversal — probability dropped >10% in last 24 hours.
     *
     * PATCH #2 FIX: Sebelumnya membaca $trade->signal->momentum_24h_percent
     * yang merupakan data statis saat signal dibuat. Sekarang menghitung
     * momentum dari dua snapshot terbaru secara real-time.
     *
     * Formula: (price_now - price_24h_ago) / price_24h_ago * 100
     *
     * Fallback: return false jika data snapshot tidak tersedia.
     */
    private function isMomentumReversing(PaperTrade $trade): bool
    {
        $market = $trade->market;
        if (! $market) {
            return false;
        }

        // Snapshot paling baru
        $latestSnapshot = $market->snapshots()->latest('snapshotted_at')->first();
        if (! $latestSnapshot) {
            return false;
        }

        // Snapshot mendekati 24 jam lalu (window ±1 jam untuk toleransi gap collector)
        $snapshot24hAgo = $market->snapshots()
            ->where('snapshotted_at', '<=', now()->subHours(23))
            ->where('snapshotted_at', '>=', now()->subHours(25))
            ->latest('snapshotted_at')
            ->first();

        if (! $snapshot24hAgo) {
            // Fallback: tidak ada data 24h lalu → tidak trigger exit
            // Ini lebih aman daripada false positive yang menutup trade prematur
            return false;
        }

        $priceNow    = (float) $latestSnapshot->probability_yes;
        $price24hAgo = (float) $snapshot24hAgo->probability_yes;

        if ($price24hAgo <= 0) {
            return false;
        }

        $momentum24h = (($priceNow - $price24hAgo) / $price24hAgo) * 100.0;

        return $momentum24h < -10.0;
    }

    /**
     * Rule 2: Liquidity < 50% of liquidity at signal time.
     * Tidak berubah — sudah membaca current snapshot dengan benar.
     */
    private function isLiquidityDeteriorating(PaperTrade $trade): bool
    {
        $signal = $trade->signal;
        if (! $signal || $signal->liquidity_usd === null) {
            return false;
        }

        $entryLiquidity   = (float) $signal->liquidity_usd;
        $currentLiquidity = $this->getCurrentLiquidity($trade);

        if ($currentLiquidity === null || $entryLiquidity <= 0) {
            return false;
        }

        return $currentLiquidity < ($entryLiquidity * 0.50);
    }

    /**
     * Rule 3: Spread > 2x spread at signal time.
     * Tidak berubah — sudah membaca current snapshot dengan benar.
     */
    private function isSpreadWidening(PaperTrade $trade): bool
    {
        $signal = $trade->signal;
        if (! $signal || $signal->spread === null) {
            return false;
        }

        $entrySpread   = (float) $signal->spread;
        $currentSpread = $this->getCurrentSpread($trade);

        if ($currentSpread === null || $entrySpread <= 0) {
            return false;
        }

        return $currentSpread > ($entrySpread * 2.0);
    }

    /**
     * Rule 4: Market expires within 6 hours.
     */
    private function isNearExpiry(PaperTrade $trade): bool
    {
        $market = $trade->market;
        if (! $market || empty($market->end_date)) {
            return false;
        }

        $endDate = \Carbon\Carbon::parse($market->end_date);
        if ($endDate->isPast()) {
            return true;
        }

        return now()->diffInHours($endDate, absolute: false) < 6;
    }

    /**
     * Rule 5: Opposite direction signal exists for same market.
     */
    private function hasOppositeSignal(PaperTrade $trade): bool
    {
        $oppositeDirection = strtolower((string) $trade->direction) === 'yes' ? 'no' : 'yes';

        return Signal::where('market_id', $trade->market_id)
            ->where('direction', $oppositeDirection)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    // =========================================================================
    // History Checks
    // =========================================================================

    public function hasTp1Fired(PaperTrade $trade): bool
    {
        return $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_TP1)
            ->exists();
    }

    public function hasTp2Fired(PaperTrade $trade): bool
    {
        return $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_TP2)
            ->exists();
    }

    public function hasBreakevenMoved(PaperTrade $trade): bool
    {
        return $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_BREAKEVEN_MOVED)
            ->exists();
    }

    /**
     * Prevent momentum partial exit from firing more than once per 30 minutes.
     */
    private function hasRecentSmartExit(PaperTrade $trade, int $minutes = 30): bool
    {
        $result = PaperTradeHistory::where('paper_trade_id', $trade->id)
            ->where('event_type', PaperTradeHistory::EVENT_SMART_EXIT)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
        \Illuminate\Support\Facades\Log::debug('[SmartExit] hasRecentSmartExit', [
            'trade_id' => $trade->id,
            'result'   => $result,
            'since'    => now()->subMinutes($minutes)->toDateTimeString(),
        ]);
        return $result;
    }

    // =========================================================================
    // Market Data Helpers
    // =========================================================================

    private function getCurrentLiquidity(PaperTrade $trade): ?float
    {
        $snapshot = $trade->market?->latestSnapshot;
        return $snapshot ? (float) ($snapshot->liquidity_usd ?? 0) : null;
    }

    private function getCurrentSpread(PaperTrade $trade): ?float
    {
        $snapshot = $trade->market?->latestSnapshot;
        return $snapshot ? (float) ($snapshot->spread ?? 0) : null;
    }

    private function getTp2Price(PaperTrade $trade, float $entryPrice): ?float
    {
        $settings = PaperTradeSetting::current();

        if (! $settings->enable_take_profit || $settings->take_profit_r2 === null) {
            return null;
        }

        $stopLoss = $trade->stop_loss_price ? (float) $trade->stop_loss_price : null;
        if ($stopLoss === null) {
            return null;
        }

        $riskPerShare = $entryPrice - $stopLoss;
        if ($riskPerShare <= 0) {
            return null;
        }

        return round($entryPrice + ($riskPerShare * (float) $settings->take_profit_r2), 8);
    }

    private function isSmartExitEnabled(PaperTrade $trade): bool
    {
        return (bool) PaperTradeSetting::current()->enable_smart_exit;
    }
}
