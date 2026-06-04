<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Signal;
use App\Models\TradingAccount;
use App\Services\PaperTradingService;
use App\Services\PortfolioService;
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

    // INI ADALAH FUNGSI EXECUTE YANG DIPAKAI
    public function execute(Signal $signal, PaperTradingService $tradingService, PortfolioService $portfolioService)
    {
        $account = $portfolioService->getAccountForUser(Auth::user());
        
        try {
            $trade = $tradingService->openTrade($signal, $account);

            if ($trade) {
                // Sukses: Bawa user langsung ke halaman Paper Trades
                return redirect()->route('paper-trades.index')
                                 ->with('success', 'Signal berhasil dieksekusi menjadi Paper Trade!');
            }

            return back()->with('error', 'Gagal mengeksekusi signal. Pastikan balance cukup dan status signal masih pending.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function ignore(Signal $signal, SignalService $signalService)
    {
        $signalService->ignoreSignal($signal);
        return back()->with('success', 'Signal ignored.');
    }

    // Endpoint API untuk AG Grid
    public function gridData(Request $request)
    {
        $query = Signal::with('market');

        // 1. Filter Status & Direction
        if ($request->filled('status')) {
            $query->whereRaw('LOWER(status) = ?', [strtolower($request->input('status'))]);
        }
        if ($request->filled('direction')) {
            $query->whereRaw('LOWER(direction) = ?', [strtolower($request->input('direction'))]);
        }

        // 2. Sorting
        if ($request->filled('sortModel')) {
            $sortModel = json_decode($request->input('sortModel'), true);
            foreach ($sortModel as $sort) {
                // Jangan sorting relasi 'market_question' secara langsung di DB untuk performa, kecuali di-join.
                if ($sort['colId'] !== 'market_question') { 
                    $query->orderBy($sort['colId'], $sort['sort'] === 'asc' ? 'asc' : 'desc');
                }
            }
        } else {
            $query->latest('fired_at');
        }

        // 3. Pagination
        $startRow  = (int) $request->input('startRow', 0);
        $endRow    = (int) $request->input('endRow', 100);
        $limit     = max(1, $endRow - $startRow);

        $totalRows = (clone $query)->count();  // ← clone agar tidak mutate
        $signals   = $query->offset($startRow)->limit($limit)->get();

        $rows = $signals->map(function ($sig) {
            return [
                'id' => $sig->id,
                'market_id' => $sig->market_id,
                'market_question' => $sig->market->question ?? '-',
                'trigger_source' => $sig->trigger_source,
                'direction' => $sig->direction,
                'market_probability_at_signal' => $sig->market_probability_at_signal,
                'edge_at_signal' => $sig->edge_at_signal,
                'status' => $sig->status,
                'fired_at' => $sig->fired_at?->format('Y-m-d H:i:s'),
                'snapshot_data' => $sig->snapshot_data,
            ];
        });

        return response()->json([
            'rows' => $rows,
            'totalRows' => $totalRows
        ]);
    }
}