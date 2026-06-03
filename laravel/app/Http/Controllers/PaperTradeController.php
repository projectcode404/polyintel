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
        $request->validate([
            'is_auto_trade' => 'boolean',
            'is_auto_close' => 'boolean',
        ]);
        
        $account = $portfolioService->getAccountForUser(Auth::user());
        $portfolioService->updateSettings($account, $request->all());
        
        return back()->with('success', 'Settings updated.');
    }
}
