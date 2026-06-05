<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaperTrade;
use App\Services\PaperTradingService;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaperTradeController extends Controller
{
    public function index(PortfolioService $portfolioService)
    {
        $account = $portfolioService->getAccountForUser(Auth::user());
        return view('paper-trades.index', compact('account'));
    }

    public function gridData(Request $request, PortfolioService $portfolioService)
    {
        $account = $portfolioService->getAccountForUser(Auth::user());

        $query = PaperTrade::with(['market', 'signal'])
                           ->where('trading_account_id', $account->id);

        // Filter Status
        if ($request->filled('status')) {
            $query->whereRaw('LOWER(status) = ?', [strtolower($request->input('status'))]);
        }

        // Sorting
        $allowedSort = [
            'entry_price', 'shares', 'position_size_usd',
            'pnl_usd', 'roi', 'status', 'entered_at', 'direction',
        ];

        if ($request->filled('sortModel')) {
            $sortModel = json_decode($request->input('sortModel'), true) ?? [];
            foreach ($sortModel as $sort) {
                if (in_array($sort['colId'], $allowedSort)) {
                    $query->orderBy($sort['colId'], $sort['sort'] === 'asc' ? 'asc' : 'desc');
                }
            }
        } else {
            $query->latest('entered_at');
        }

        $startRow  = (int) $request->input('startRow', 0);
        $endRow    = (int) $request->input('endRow', 100);
        $limit     = max(1, $endRow - $startRow);
        $totalRows = (clone $query)->count();
        $trades    = $query->offset($startRow)->limit($limit)->get();

        $rows = $trades->map(function ($trade) {
            $isOpen = strtolower($trade->status ?? '') === 'open';

            return [
                'id'                    => $trade->id,
                'market_id'             => $trade->market_id,
                'market_question'       => $trade->market->question ?? '-',
                'trigger_source'        => $trade->signal->trigger_source ?? null,
                'direction'             => $trade->direction,
                'entry_price'           => $trade->entry_price,
                'current_price'         => $isOpen
                    ? ($trade->current_price ?? $trade->entry_price)
                    : null,
                'current_or_exit_price' => $isOpen
                    ? ($trade->current_price ?? $trade->entry_price)
                    : $trade->exit_price,
                'exit_price'            => $trade->exit_price,
                'shares'                => $trade->shares,
                'position_size_usd'     => $trade->position_size_usd,
                'unrealized_pnl_usd'    => $isOpen
                    ? ($trade->unrealized_pnl_usd ?? 0.0)
                    : null,
                'pnl_usd'               => $trade->pnl_usd,
                'roi'                   => $trade->roi,
                'status'                => $trade->status,
                'outcome'               => $trade->outcome,
                'entered_at'            => $trade->entered_at?->format('Y-m-d H:i'),
            ];
        });

        return response()->json([
            'rows'      => $rows,
            'totalRows' => $totalRows,
        ]);
    }

    public function close(PaperTrade $trade, Request $request, PaperTradingService $tradingService)
    {
        $request->validate([
            'exit_price' => 'required|numeric|min:0|max:1',
        ]);

        // FIX: gunakan trading_account_id bukan relasi tradingAccount->user_id
        // karena sebelumnya relasi tidak ada di model
        $account = $trade->tradingAccount;

        if (!$account || $account->user_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }

        if ($trade->status !== 'open') {
            return back()->with('error', 'Trade is already closed.');
        }

        try {
            $tradingService->closeTrade($trade, (float) $request->input('exit_price'));
            return back()->with('success', 'Trade closed successfully.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updateSettings(Request $request, PortfolioService $portfolioService)
    {
        $validated = $request->validate([
            'is_auto_trade' => 'required|in:0,1',
            'is_auto_close' => 'required|in:0,1',
        ]);

        $account = $portfolioService->getAccountForUser(Auth::user());
        $portfolioService->updateSettings($account, [
            'is_auto_trade' => (bool) (int) $validated['is_auto_trade'],
            'is_auto_close' => (bool) (int) $validated['is_auto_close'],
        ]);

        return back()->with('success', 'Settings updated.');
    }
}