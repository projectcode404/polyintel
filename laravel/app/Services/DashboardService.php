<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Market;
use App\Models\MarketSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * DashboardService
 *
 * Semua query agregasi untuk halaman dashboard.
 * Controller hanya memanggil service ini dan pass hasilnya ke view.
 * Zero business logic di controller.
 */
final class DashboardService
{
    /**
     * Stat cards utama di bagian atas dashboard.
     */
    public function getStats(): array
    {
        $totalMarkets    = Market::whereNull('deleted_at')->count();
        $activeMarkets   = Market::where('status', 'active')->whereNull('deleted_at')->count();
        $totalSnapshots  = MarketSnapshot::count();

        // Snapshots dalam 24 jam terakhir
        $snapshots24h = MarketSnapshot::where(
            'snapshotted_at', '>=', now()->subHours(24)
        )->count();

        // Latest BTC dan ETH price dari snapshot terbaru
        $latestSnapshot = MarketSnapshot::whereNotNull('btc_price_usd')
            ->orderByDesc('snapshotted_at')
            ->first();

        // Breakdown sub_category
        $bitcoinMarkets  = Market::where('sub_category', 'bitcoin')->where('status', 'active')->count();
        $ethereumMarkets = Market::where('sub_category', 'ethereum')->where('status', 'active')->count();

        // Markets expiring dalam 48 jam
        $expiringSoon = Market::where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now()->addHours(48))
            ->where('end_date', '>', now())
            ->count();

        return [
            'total_markets'    => $totalMarkets,
            'active_markets'   => $activeMarkets,
            'total_snapshots'  => $totalSnapshots,
            'snapshots_24h'    => $snapshots24h,
            'bitcoin_markets'  => $bitcoinMarkets,
            'ethereum_markets' => $ethereumMarkets,
            'expiring_soon'    => $expiringSoon,
            'btc_price'        => $latestSnapshot?->btc_price_usd,
            'eth_price'        => $latestSnapshot?->eth_price_usd,
            'fear_greed'       => $latestSnapshot?->fear_greed_index,
            'last_synced'      => Market::whereNotNull('last_synced_at')
                                    ->max('last_synced_at'),
        ];
    }

    /**
     * Top 10 markets berdasarkan volume untuk tabel di dashboard.
     */
    public function getTopMarkets(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return Market::where('status', 'active')
            ->whereNull('deleted_at')
            ->orderByDesc('volume_usd')
            ->limit($limit)
            ->get([
                'id', 'question', 'sub_category', 'status',
                'market_probability', 'volume_usd', 'liquidity_usd',
                'end_date', 'last_synced_at',
            ]);
    }

    /**
     * Data chart: jumlah snapshots per jam dalam 24 jam terakhir.
     * Dipakai untuk chart "Collection Activity".
     */
    public function getSnapshotActivityChart(): array
    {
        $rows = DB::select("
            SELECT
                date_trunc('hour', snapshotted_at) AS hour,
                COUNT(*) AS count
            FROM market_snapshots
            WHERE snapshotted_at >= NOW() - INTERVAL '24 hours'
            GROUP BY date_trunc('hour', snapshotted_at)
            ORDER BY hour ASC
        ");

        $labels = [];
        $data   = [];

        foreach ($rows as $row) {
            $labels[] = \Carbon\Carbon::parse($row->hour)->format('H:i') . ' UTC';
            $data[]   = (int) $row->count;
        }

        return compact('labels', 'data');
    }

    /**
     * Data chart: distribusi probability semua active markets.
     * Dipakai untuk histogram "Market Probability Distribution".
     */
    public function getProbabilityDistributionChart(): array
    {
        $buckets = [
            '0-10%'   => [0, 0.10],
            '10-20%'  => [0.10, 0.20],
            '20-30%'  => [0.20, 0.30],
            '30-40%'  => [0.30, 0.40],
            '40-50%'  => [0.40, 0.50],
            '50-60%'  => [0.50, 0.60],
            '60-70%'  => [0.60, 0.70],
            '70-80%'  => [0.70, 0.80],
            '80-90%'  => [0.80, 0.90],
            '90-100%' => [0.90, 1.01],
        ];

        $labels = [];
        $data   = [];

        foreach ($buckets as $label => [$min, $max]) {
            $labels[] = $label;
            $data[]   = Market::where('status', 'active')
                ->whereNotNull('market_probability')
                ->where('market_probability', '>=', $min)
                ->where('market_probability', '<', $max)
                ->count();
        }

        return compact('labels', 'data');
    }

    /**
     * Sub-category breakdown untuk pie chart.
     */
    public function getSubCategoryBreakdown(): array
    {
        $rows = Market::where('status', 'active')
            ->whereNull('deleted_at')
            ->select('sub_category', DB::raw('COUNT(*) as count'))
            ->groupBy('sub_category')
            ->orderByDesc('count')
            ->get();

        $labels = [];
        $data   = [];

        foreach ($rows as $row) {
            $labels[] = $row->sub_category ?? 'Other';
            $data[]   = $row->count;
        }

        return compact('labels', 'data');
    }
}
