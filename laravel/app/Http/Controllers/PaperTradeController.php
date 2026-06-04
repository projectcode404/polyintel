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
