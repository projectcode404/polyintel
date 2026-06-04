<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaperTrade;
use App\Models\TradingAccount;
use App\Services\PaperTradingService;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaperTradeController extends Controller
{
    public function gridData(Request $request, PortfolioService $portfolioService)
    {
        $account = $portfolioService->getAccountForUser(Auth::user());
        
        $query = PaperTrade::with(['market', 'signal'])
                           ->where('trading_account_id', $account->id);

        // 1. Filter Status (Aman dari Case Sensitive Open vs open)
        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));
            $query->whereRaw('LOWER(status) = ?', [$status]);
        }

        // 2. Sorting Model AG Grid
        if ($request->filled('sortModel')) {
            $sortModel = json_decode($request->input('sortModel'), true);
            foreach ($sortModel as $sort) {
                // Hindari sorting relasi untuk mencegah SQL error jika tanpa join
                if (!in_array($sort['colId'], ['market_question', 'current_or_exit_price'])) {
                    $query->orderBy($sort['colId'], $sort['sort'] === 'asc' ? 'asc' : 'desc');
                }
            }
        } else {
            $query->latest('entered_at'); // Default sort
        }

        // 3. Server-side Pagination
        $startRow = (int) $request->input('startRow', 0);
        $endRow = (int) $request->input('endRow', 100);
        $limit = $endRow - $startRow;

        $totalRows = $query->count();
        $trades = $query->offset($startRow)->limit($limit)->get();

        // 4. Data Transformation DTO untuk Frontend
        $rows = $trades->map(function ($trade) {
            return [
                'id' => $trade->id,
                'market_id' => $trade->market_id,
                'market_question' => $trade->market->question ?? '-',
                'trigger_source' => $trade->signal->trigger_source ?? null,
                'direction' => $trade->direction,
                'entry_price' => $trade->entry_price,
                'current_price' => $trade->current_price,
                'exit_price' => $trade->exit_price,
                'shares' => $trade->shares,
                'position_size_usd' => $trade->position_size_usd,
                'unrealized_pnl_usd' => $trade->unrealized_pnl_usd,
                'pnl_usd' => $trade->pnl_usd,
                'roi' => $trade->roi,
                'status' => $trade->status,
                'outcome' => $trade->outcome,
                'entered_at' => $trade->entered_at?->format('Y-m-d H:i'),
            ];
        });

        return response()->json([
            'rows' => $rows,
            'totalRows' => $totalRows
        ]);
    }
    
    public function index(PortfolioService $portfolioService)
    {
        $account = $portfolioService->getAccountForUser(Auth::user());
        
        $trades = PaperTrade::where('trading_account_id', $account->id)
            ->with(['market', 'signal'])
            ->latest('entered_at')
            ->paginate(50);
            
        return view('paper-trades.index', compact('trades', 'account'));
    }

    public function close(PaperTrade $trade, Request $request, PaperTradingService $tradingService)
    {
        $request->validate([
            'exit_price' => 'required|numeric|min:0|max:1',
        ]);
        
        // Ensure trade belongs to user
        if ($trade->tradingAccount->user_id !== Auth::id()) {
            abort(403);
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
