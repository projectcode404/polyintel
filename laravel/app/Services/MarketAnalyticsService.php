<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Market;
use App\Models\MarketSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * MarketAnalyticsService
 *
 * Query layer untuk markets index (AG Grid server-side)
 * dan market detail (probability chart, snapshot history).
 */
final class MarketAnalyticsService
{
    /**
     * Server-side data untuk AG Grid.
     * Mendukung sorting, filtering, dan pagination dari AG Grid request.
     *
     * AG Grid server-side request format:
     *   startRow, endRow, sortModel[], filterModel{}
     */
    public function getMarketsForGrid(Request $request): array
    {
        $startRow  = (int) $request->input('startRow', 0);
        $endRow    = (int) $request->input('endRow', 100);
        $pageSize  = $endRow - $startRow;
        $sortModel = json_decode($request->input('sortModel', '[]'), true) ?? [];
        $filters   = json_decode($request->input('filterModel', '{}'), true) ?? [];

        // Extra filters dari URL params (category, sub_category, status)
        $category    = $request->input('category');
        $subCategory = $request->input('sub_category');
        $status      = $request->input('status', 'active');

        $query = Market::whereNull('deleted_at')
            ->select([
                'id', 'condition_id', 'question', 'category', 'sub_category',
                'status', 'market_probability', 'volume_usd', 'liquidity_usd',
                'num_traders', 'ai_probability', 'edge', 'end_date',
                'last_synced_at', 'is_tracked',
            ]);

        // Filters
        if ($status) {
            $query->where('status', $status);
        }
        if ($category) {
            $query->where('category', $category);
        }
        if ($subCategory) {
            $query->where('sub_category', $subCategory);
        }

        // AG Grid filter model
        foreach ($filters as $field => $filterConfig) {
            $this->applyAgGridFilter($query, $field, $filterConfig);
        }

        // Total count sebelum pagination
        $totalRows = $query->count();

        // Sorting dari AG Grid
        if (!empty($sortModel)) {
            foreach ($sortModel as $sort) {
                $col   = $this->sanitizeColumn($sort['colId'] ?? '');
                $dir   = ($sort['sort'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                if ($col) {
                    $query->orderBy($col, $dir);
                }
            }
        } else {
            // Default sort: volume DESC
            $query->orderByDesc('volume_usd');
        }

        $rows = $query->skip($startRow)->take($pageSize)->get();

        return [
            'rows'      => $rows->map(fn ($m) => $this->formatMarketRow($m)),
            'totalRows' => $totalRows,
        ];
    }

    /**
     * Data probability chart untuk halaman market detail.
     * Return array of {time, probability_yes} untuk Chart.js.
     *
     * @param int $hours  Berapa jam ke belakang (default: 24 jam)
     */
    public function getProbabilityChartData(Market $market, int $hours = 24): array
    {
        $snapshots = MarketSnapshot::where('market_id', $market->id)
            ->where('snapshotted_at', '>=', now()->subHours($hours))
            ->orderBy('snapshotted_at')
            ->get(['snapshotted_at', 'probability_yes', 'probability_no', 'volume_24h_usd']);

        $labels          = [];
        $probabilityYes  = [];
        $probabilityNo   = [];
        $volume24h       = [];

        foreach ($snapshots as $s) {
            $labels[]         = $s->snapshotted_at->format('Y-m-d H:i') . ' UTC';
            $probabilityYes[] = round($s->probability_yes * 100, 2);
            $probabilityNo[]  = round($s->probability_no * 100, 2);
            $volume24h[]      = round($s->volume_24h_usd, 2);
        }

        return compact('labels', 'probabilityYes', 'probabilityNo', 'volume24h');
    }

    /**
     * Statistik ringkas untuk info card di market detail.
     */
    public function getMarketStats(Market $market): array
    {
        $snapshotCount = MarketSnapshot::where('market_id', $market->id)->count();

        $stats = MarketSnapshot::where('market_id', $market->id)
            ->selectRaw('
                MIN(probability_yes) as min_prob,
                MAX(probability_yes) as max_prob,
                AVG(probability_yes) as avg_prob,
                MIN(snapshotted_at)  as first_snapshot,
                MAX(snapshotted_at)  as last_snapshot
            ')
            ->first();

        return [
            'snapshot_count'  => $snapshotCount,
            'min_probability' => $stats?->min_prob ? round($stats->min_prob * 100, 2) : null,
            'max_probability' => $stats?->max_prob ? round($stats->max_prob * 100, 2) : null,
            'avg_probability' => $stats?->avg_prob ? round($stats->avg_prob * 100, 2) : null,
            'first_snapshot'  => $stats?->first_snapshot,
            'last_snapshot'   => $stats?->last_snapshot,
        ];
    }

    /**
     * Recent snapshots untuk tabel di market detail page.
     * Server-side untuk AG Grid.
     */
    public function getSnapshotsForGrid(Market $market, Request $request): array
    {
        $startRow = (int) $request->input('startRow', 0);
        $endRow   = (int) $request->input('endRow', 50);
        $pageSize = $endRow - $startRow;

        $query = MarketSnapshot::where('market_id', $market->id)
            ->orderByDesc('snapshotted_at');

        $totalRows = $query->count();

        $rows = $query->skip($startRow)->take($pageSize)->get([
            'id', 'snapshotted_at', 'probability_yes', 'probability_no',
            'best_bid', 'best_ask', 'spread', 'volume_24h_usd',
            'liquidity_usd', 'btc_price_usd', 'eth_price_usd',
            'fear_greed_index',
        ]);

        return [
            'rows'      => $rows->map(fn ($s) => [
                'snapshotted_at'  => $s->snapshotted_at->format('Y-m-d H:i:s') . ' UTC',
                'probability_yes' => round($s->probability_yes * 100, 2) . '%',
                'probability_no'  => round($s->probability_no * 100, 2) . '%',
                'best_bid'        => $s->best_bid ? round($s->best_bid * 100, 2) . '%' : '-',
                'best_ask'        => $s->best_ask ? round($s->best_ask * 100, 2) . '%' : '-',
                'spread'          => $s->spread ? round($s->spread * 10000, 1) . ' bps' : '-',
                'volume_24h_usd'  => '$' . number_format($s->volume_24h_usd, 0),
                'liquidity_usd'   => '$' . number_format($s->liquidity_usd, 0),
                'btc_price_usd'   => $s->btc_price_usd ? '$' . number_format($s->btc_price_usd, 0) : '-',
                'eth_price_usd'   => $s->eth_price_usd ? '$' . number_format($s->eth_price_usd, 0) : '-',
                'fear_greed'      => $s->fear_greed_index ?? '-',
            ]),
            'totalRows' => $totalRows,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function formatMarketRow(Market $market): array
    {
        return [
            'id'                  => $market->id,
            'question'            => $market->question,
            'sub_category'        => $market->sub_category ?? 'other',
            'status'              => $market->status,
            'market_probability'  => $market->market_probability
                ? round($market->market_probability * 100, 1) . '%'
                : 'N/A',
            'market_probability_raw' => $market->market_probability,
            'volume_usd'          => '$' . number_format($market->volume_usd, 0),
            'volume_usd_raw'      => $market->volume_usd,
            'liquidity_usd'       => '$' . number_format($market->liquidity_usd, 0),
            'num_traders'         => number_format($market->num_traders),
            'ai_probability'      => $market->ai_probability
                ? round($market->ai_probability * 100, 1) . '%'
                : 'N/A',
            'edge'                => $market->edge
                ? ($market->edge >= 0 ? '+' : '') . round($market->edge * 100, 1) . '%'
                : 'N/A',
            'end_date'            => $market->end_date
                ? $market->end_date->format('Y-m-d H:i') . ' UTC'
                : 'N/A',
            'last_synced_at'      => $market->last_synced_at
                ? $market->last_synced_at->diffForHumans()
                : 'Never',
            'detail_url'          => route('markets.show', $market->id),
        ];
    }

    private function applyAgGridFilter($query, string $field, array $config): void
    {
        $col = $this->sanitizeColumn($field);
        if (!$col) return;

        $type   = $config['type'] ?? 'contains';
        $filter = $config['filter'] ?? null;

        if ($filter === null) return;

        match ($type) {
            'contains'    => $query->where($col, 'ilike', "%{$filter}%"),
            'equals'      => $query->where($col, $filter),
            'startsWith'  => $query->where($col, 'ilike', "{$filter}%"),
            'greaterThan' => $query->where($col, '>', $filter),
            'lessThan'    => $query->where($col, '<', $filter),
            default       => null,
        };
    }

    private function sanitizeColumn(string $col): ?string
    {
        // Whitelist kolom yang boleh di-sort/filter — cegah SQL injection
        $allowed = [
            'id', 'question', 'category', 'sub_category', 'status',
            'market_probability', 'volume_usd', 'liquidity_usd',
            'num_traders', 'edge', 'end_date', 'last_synced_at',
        ];

        return in_array($col, $allowed, true) ? $col : null;
    }
}
