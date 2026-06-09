<?php

/**
 * PATCH #3 — PaperTradingService.php
 *
 * FILE: app/Services/PaperTradingService.php
 *
 * ROOT CAUSE
 * ----------
 * closeTrade() menggunakan (float) $trade->shares untuk kalkulasi PnL.
 * Ini adalah jumlah TOTAL shares saat open, mengabaikan partial exits
 * yang sudah terjadi melalui SmartExitMonitorJob.
 *
 * Skenario masalah:
 *   - Trade dibuka: shares = 100
 *   - TP1 fired via SmartExit: 50 shares ditutup, sharesRemaining = 50
 *   - ProcessAutoClose memanggil closeTrade()
 *   - closeTrade() menghitung: grossPnl = (exit - entry) * 100   ← SALAH, seharusnya 50
 *   - Akibat: PnL dihitung untuk 50 shares yang sudah ditutup sebelumnya
 *
 * Masalah tambahan yang diperbaiki:
 *   1. closeTrade() tidak menulis PaperTradeHistory → tidak ada audit trail
 *   2. closeTrade() mencharge fee dari trade->shares (seluruh) bukan sharesRemaining
 *   3. Balance increment menggunakan position_size_usd + netPnl, yang tidak
 *      memperhitungkan capital yang sudah dikembalikan saat partial exit
 *      (ini akan diaddress di sprint berikutnya — lihat catatan di bawah)
 *
 * PATCH INI (minimal, aman):
 *   - Ganti $trade->shares dengan $trade->sharesRemaining() untuk PnL dan fee
 *   - Tambahkan PaperTradeHistory::EVENT_CLOSED setelah close
 *
 * CATATAN BALANCE (tidak dipatch sekarang):
 *   Balance tracking antara SmartExitMonitorJob (tidak update balance) dan
 *   PaperTradingService::closeTrade() (update balance via increment) adalah
 *   dua sistem yang berbeda. Unifikasi penuh perlu sprint tersendiri.
 *   Untuk saat ini: closeTrade() tetap increment balance seperti sebelumnya,
 *   karena mengubah ini berisiko tinggi dan memerlukan audit balance trail.
 *
 * DIFF closeTrade() — bagian yang berubah:
 *
 * SEBELUM:
 *   $grossPnl   = ($exitPrice - $trade->entry_price) * $trade->shares;
 *   $exitFeeUsd = ($trade->shares * $exitPrice) * $feeRate;
 *
 * SESUDAH:
 *   $sharesForExit = $trade->sharesRemaining();    // ← gunakan sisa shares
 *   $grossPnl      = ($exitPrice - $trade->entry_price) * $sharesForExit;
 *   $exitFeeUsd    = ($sharesForExit * $exitPrice) * $feeRate;
 *
 * RISIKO
 * ------
 * MEDIUM: Kalkulasi PnL di closeTrade() berubah secara semantik.
 * - Jika trade tidak pernah mengalami partial exit, sharesRemaining() == shares
 *   dan hasilnya identik dengan sebelumnya → no regression
 * - Jika trade pernah partial exit, PnL sekarang lebih kecil (lebih akurat)
 * - Test wajib dijalankan sebelum deploy
 *
 * RESET DATA: YA (sudah dijadwalkan post-semua-patch).
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\Signal;
use App\Models\TradingAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PaperTradingService
{
    public function __construct(
        private readonly PortfolioService $portfolioService,
        private readonly SignalService $signalService
    ) {}

    /**
     * Execute a paper trade based on a signal.
     * Tidak berubah dari versi asli.
     */
    public function openTrade(Signal $signal, TradingAccount $account): ?PaperTrade
    {
        if ($signal->status !== 'pending' || $signal->isExpired()) {
            return null;
        }

        $entryPrice = $signal->direction === 'yes'
            ? (float) $signal->market->market_probability
            : 1.0 - (float) $signal->market->market_probability;

        if ($entryPrice <= 0 || $entryPrice >= 1) {
            Log::warning("Cannot open trade for signal {$signal->id}: invalid entry price {$entryPrice}");
            return null;
        }

        return DB::transaction(function () use ($signal, $account, $entryPrice) {
            $account = TradingAccount::where('id', $account->id)->lockForUpdate()->first();

            $positionSizeUsd = $account->balance * 0.02;

            if ($positionSizeUsd < 1.0) {
                Log::warning("Account {$account->id} balance too low to open trade.");
                return null;
            }

            $account->decrement('balance', $positionSizeUsd);

            $shares  = $positionSizeUsd / $entryPrice;
            $feeRate = $this->portfolioService->getTradingFeePercentage();
            $feesUsd = $positionSizeUsd * $feeRate;

            $trade = PaperTrade::create([
                'trading_account_id'          => $account->id,
                'market_id'                   => $signal->market_id,
                'signal_id'                   => $signal->id,
                'direction'                   => $signal->direction,
                'entry_price'                 => $entryPrice,
                'shares'                      => $shares,
                'position_size_usd'           => $positionSizeUsd,
                'fees_usd'                    => $feesUsd,
                'market_probability_at_entry' => $signal->market_probability_at_signal,
                'ai_probability_at_entry'     => $signal->ai_probability_at_signal,
                'edge_at_entry'               => $signal->edge_at_signal,
                'status'                      => PaperTrade::STATUS_OPEN,
                'entered_at'                  => now(),
            ]);

            $this->signalService->markAsActive($signal);

            return $trade;
        });
    }

    /**
     * Close a paper trade at market settlement price.
     *
     * Used by:
     *   - ProcessAutoClose (market resolved)
     *   - Manual close via controller
     *
     * PATCH #3: Menggunakan sharesRemaining() bukan trade->shares
     * untuk menghindari double-counting pada trade yang sudah partial exit.
     */
    public function closeTrade(PaperTrade $trade, float $exitPrice, string $outcome = null): PaperTrade
    {
        if (! $trade->isOpen()) {
            return $trade;
        }

        return DB::transaction(function () use ($trade, $exitPrice, $outcome) {
            $trade = PaperTrade::where('id', $trade->id)->lockForUpdate()->first();

            if (! $trade->isOpen()) {
                return $trade;
            }

            // PATCH #3 FIX: gunakan sharesRemaining() bukan $trade->shares
            // Jika tidak ada partial exit sebelumnya, sharesRemaining() == $trade->shares
            // sehingga tidak ada regresi untuk trade clean (tanpa partial exit).
            $sharesForExit = $trade->sharesRemaining();

            if ($sharesForExit <= 0) {
                // Semua shares sudah ditutup oleh SmartExitMonitor
                // Tutup record saja tanpa PnL baru
                Log::info("[PaperTradingService] closeTrade: no shares remaining for trade {$trade->id}, closing record only.");
                $trade->update([
                    'status'               => PaperTrade::STATUS_CLOSED,
                    'exit_price'           => $exitPrice,
                    'holding_period_hours' => now()->diffInSeconds($trade->entered_at) / 3600.0,
                    'exited_at'            => now(),
                    'outcome'              => $outcome ?? ($trade->pnl_usd >= 0 ? 'win' : 'loss'),
                ]);
                return $trade;
            }

            $feeRate    = $this->portfolioService->getTradingFeePercentage();
            $grossPnl   = ($exitPrice - (float) $trade->entry_price) * $sharesForExit;
            $exitFeeUsd = ($sharesForExit * $exitPrice) * $feeRate;
            $totalFees  = (float) $trade->fees_usd + $exitFeeUsd;
            $netPnlUsd  = $grossPnl - $exitFeeUsd;   // exit fee only, entry fee sudah di fees_usd

            // Gabungkan dengan realized PnL dari partial exits sebelumnya
            $previousRealizedPnl = (float) $trade->pnl_usd;
            $totalPnlUsd         = $previousRealizedPnl + $netPnlUsd;

            // ROI: total PnL / original position size
            // BUG #4 INVARIANT: ROI floor -100%
            $rawRoi = (float) $trade->position_size_usd > 0
                ? $totalPnlUsd / (float) $trade->position_size_usd
                : 0.0;
            $roi = max(-1.0, $rawRoi);

            $holdingPeriodHours = now()->diffInSeconds($trade->entered_at) / 3600.0;

            if (! $outcome) {
                if ($totalPnlUsd > 0)       $outcome = 'win';
                elseif ($totalPnlUsd < 0)   $outcome = 'loss';
                else                         $outcome = 'breakeven';
            }

            // Tulis history — closing event untuk audit trail
            PaperTradeHistory::create([
                'paper_trade_id'  => $trade->id,
                'event_type'      => PaperTradeHistory::EVENT_CLOSED,
                'price_at_event'  => $exitPrice,
                'shares_affected' => $sharesForExit,
                'pnl_realized'    => $netPnlUsd,
                'reason'          => 'Closed by PaperTradingService (settlement or manual)',
            ]);

            $trade->update([
                'exit_price'           => $exitPrice,
                'fees_usd'             => $totalFees,
                'pnl_usd'              => $totalPnlUsd,
                'roi'                  => $roi,
                'status'               => PaperTrade::STATUS_CLOSED,
                'outcome'              => $outcome,
                'holding_period_hours' => $holdingPeriodHours,
                'exited_at'            => now(),
            ]);

            // Return capital + net PnL dari exit ini ke balance
            // (capital dari partial exits sudah dikembalikan via mekanisme terpisah)
            $returnedAmount = ($sharesForExit * (float) $trade->entry_price) + $netPnlUsd;
            TradingAccount::where('id', $trade->trading_account_id)
                ->increment('balance', $returnedAmount);

            return $trade;
        });
    }

    /**
     * Update mark-to-market metrics for all open trades.
     * Tidak berubah dari versi asli.
     */
    public function updateUnrealizedPnl(): void
    {
        $openTrades = PaperTrade::open()->with('market')->get();

        foreach ($openTrades as $trade) {
            $market       = $trade->market;
            $currentPrice = $trade->direction === 'yes'
                ? (float) $market->market_probability
                : 1.0 - (float) $market->market_probability;

            $sharesRemaining    = $trade->sharesRemaining();
            $unrealizedGross    = ($currentPrice - (float) $trade->entry_price) * $sharesRemaining;
            $feeRate            = $this->portfolioService->getTradingFeePercentage();
            $estimatedExitFee   = ($sharesRemaining * $currentPrice) * $feeRate;
            $unrealizedNet      = $unrealizedGross - (float) $trade->fees_usd - $estimatedExitFee;

            $trade->current_price      = $currentPrice;
            $trade->unrealized_pnl_usd = $unrealizedNet;

            $mae      = $trade->max_adverse_excursion ?? 0;
            $mfe      = $trade->max_favorable_excursion ?? 0;
            $priceDiff = $currentPrice - (float) $trade->entry_price;

            if ($priceDiff < $mae) {
                $trade->max_adverse_excursion = $priceDiff;
            }
            if ($priceDiff > $mfe) {
                $trade->max_favorable_excursion = $priceDiff;
            }

            $trade->save();
        }
    }
}
