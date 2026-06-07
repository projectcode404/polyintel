<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePaperTradeSettingsRequest;
use App\Models\PaperTradeSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class PaperTradeSettingsController extends Controller
{
    public function index(): View
    {
        $settings = PaperTradeSetting::current();

        return view('paper-trades.settings', compact('settings'));
    }

    public function update(UpdatePaperTradeSettingsRequest $request): RedirectResponse
    {
        $settings = PaperTradeSetting::current();

        // If a named preset was requested via preset-button, delegate to applyPreset()
        // which overwrites all strategy fields. The hidden input name is "apply_preset".
        if ($request->filled('apply_preset')) {
            $preset = $request->input('apply_preset');
            try {
                $settings->applyPreset($preset);
            } catch (\InvalidArgumentException $e) {
                return back()->with('error', 'Unknown preset: ' . $preset);
            }

            return back()->with('success', ucfirst($preset) . ' preset applied successfully.');
        }

        // Normal save: update all validated fields
        $settings->fill($request->validated());
        $settings->save();

        return back()->with('success', 'Settings saved successfully.');
    }
}
