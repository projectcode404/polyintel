<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Signal;
use App\Models\TradingAccount;
use App\Services\PaperTradingService;
use App\Services\SignalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SignalController extends Controller
{
    public function index()
    {
        $signals = Signal::with('market')->latest('fired_at')->paginate(50);
        return view('signals.index', compact('signals'));
    }

    public function execute(Signal $signal, PaperTradingService $tradingService)
    {
        $account = TradingAccount::where('user_id', Auth::id())->first();
        if (!$account) {
            return back()->with('error', 'Trading account not configured.');
        }

        try {
            $trade = $tradingService->openTrade($signal, $account);
            if ($trade) {
                return back()->with('success', 'Trade executed successfully.');
            }
            return back()->with('error', 'Trade could not be executed (e.g. low balance or expired).');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function ignore(Signal $signal, SignalService $signalService)
    {
        $signalService->ignoreSignal($signal);
        return back()->with('success', 'Signal ignored.');
    }
}
