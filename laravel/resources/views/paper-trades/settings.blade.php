@extends('layouts.app')

@section('title', 'Paper Trade Settings')
@section('page-title', 'Paper Trade Settings')
@section('page-subtitle', 'Configure strategy, position sizing, risk management, and automation')

@section('page-actions')
<a href="{{ route('paper-trades.index') }}" class="btn btn-outline-secondary btn-sm">
    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l14 0"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/>
    </svg>
    Back to Paper Trades
</a>
@endsection

@push('styles')
<style>
    /* Sticky footer bar */
    .settings-save-bar {
        position: sticky;
        bottom: 0;
        z-index: 100;
        background: var(--tblr-bg-surface);
        border-top: 1px solid var(--tblr-border-color);
        padding: .875rem 0;
    }

    /* Accordion sections look like cards */
    .accordion-button {
        font-weight: 600;
        font-size: .9rem;
    }
    .accordion-button:not(.collapsed) {
        background-color: rgba(var(--tblr-primary-rgb), .06);
        color: var(--tblr-primary);
    }

    /* Consistent form-switch label alignment */
    .form-check-label {
        cursor: pointer;
    }

    /* Preset badge pills */
    .preset-badge {
        font-size: .7rem;
        padding: .25em .6em;
        letter-spacing: .03em;
    }

    /* Section icon circle */
    .section-icon {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: .75rem;
        flex-shrink: 0;
    }
</style>
@endpush

@section('content')

{{-- Validation errors --}}
@if ($errors->any())
<div class="alert alert-danger alert-dismissible mb-3" role="alert">
    <div class="d-flex">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
            <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.871l-8.106 -13.534a1.914 1.914 0 0 0 -3.274 0z"/><path d="M12 16h.01"/>
        </svg>
        <div>
            <h4 class="alert-title">Please fix the following errors</h4>
            <ul class="mb-0 mt-1 small">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- Main form --}}
<form method="POST" action="{{ route('paper-trades.settings.update') }}" id="settingsForm">
    @csrf
    @method('PUT')

    {{-- ================================================================
         PRESET QUICK-APPLY CARD
    ================================================================ --}}
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <span class="section-icon bg-purple-lt me-2">⚡</span>
                Quick Presets
            </h3>
            <div class="card-options">
                <span class="badge preset-badge
                    {{ $settings->preset === 'conservative' ? 'bg-green-lt text-green' :
                       ($settings->preset === 'aggressive'  ? 'bg-red-lt text-red' :
                       ($settings->preset === 'balanced'    ? 'bg-blue-lt text-blue' : 'bg-muted-lt text-muted')) }}">
                    Active: {{ ucfirst($settings->preset) }}
                </span>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Applying a preset will overwrite <strong>all strategy fields</strong> with proven defaults.
                Your initial capital is preserved.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" name="apply_preset" value="conservative"
                        class="btn btn-outline-success btn-sm"
                        onclick="return confirm('Apply Conservative preset? All strategy settings will be overwritten.')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"/>
                    </svg>
                    Apply Conservative
                </button>
                <button type="submit" name="apply_preset" value="balanced"
                        class="btn btn-outline-primary btn-sm"
                        onclick="return confirm('Apply Balanced preset? All strategy settings will be overwritten.')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 21l10 -18"/><path d="M4 10h7"/><path d="M13 14h7"/>
                    </svg>
                    Apply Balanced
                </button>
                <button type="submit" name="apply_preset" value="aggressive"
                        class="btn btn-outline-danger btn-sm"
                        onclick="return confirm('Apply Aggressive preset? All strategy settings will be overwritten.')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11"/>
                    </svg>
                    Apply Aggressive
                </button>
            </div>
        </div>
    </div>

    {{-- ================================================================
         ACCORDION: ALL SETTINGS SECTIONS
    ================================================================ --}}
    <div class="accordion mb-5" id="settingsAccordion">

        {{-- ────────────────────────────────────────────────────────────
             SECTION 1 — GENERAL
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingGeneral">
                <button class="accordion-button" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseGeneral"
                        aria-expanded="true" aria-controls="collapseGeneral">
                    <span class="section-icon bg-blue-lt me-2">1</span>
                    General
                    <span class="ms-auto me-2 badge bg-blue-lt text-blue preset-badge">{{ ucfirst($settings->preset) }}</span>
                </button>
            </h2>
            <div id="collapseGeneral" class="accordion-collapse collapse show"
                 aria-labelledby="headingGeneral">
                <div class="accordion-body">
                    <div class="row g-3">

                        {{-- Preset --}}
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="preset">
                                Preset
                                <span class="ms-1 text-muted small" data-bs-toggle="tooltip"
                                      title="The preset name tracks which risk profile is active. Changing manually marks it as Custom.">ⓘ</span>
                            </label>
                            <select id="preset" name="preset" class="form-select @error('preset') is-invalid @enderror">
                                <option value="conservative" {{ old('preset', $settings->preset) === 'conservative' ? 'selected' : '' }}>Conservative — Low risk, capital preservation</option>
                                <option value="balanced"     {{ old('preset', $settings->preset) === 'balanced'     ? 'selected' : '' }}>Balanced — Moderate risk/reward</option>
                                <option value="aggressive"   {{ old('preset', $settings->preset) === 'aggressive'   ? 'selected' : '' }}>Aggressive — High risk, max returns</option>
                                <option value="custom"       {{ old('preset', $settings->preset) === 'custom'       ? 'selected' : '' }}>Custom — Manual configuration</option>
                            </select>
                            @error('preset')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        {{-- Initial Capital --}}
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="initial_capital">Initial Capital</label>
                            <div class="input-group @error('initial_capital') is-invalid @enderror">
                                <span class="input-group-text">$</span>
                                <input type="number" id="initial_capital" name="initial_capital"
                                       class="form-control @error('initial_capital') is-invalid @enderror"
                                       value="{{ old('initial_capital', $settings->initial_capital) }}"
                                       min="10" max="1000000" step="0.01">
                                <span class="input-group-text">USD</span>
                            </div>
                            @error('initial_capital')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">Starting virtual capital for paper trading.</small>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 2 — PORTFOLIO
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingPortfolio">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePortfolio"
                        aria-expanded="false" aria-controls="collapsePortfolio">
                    <span class="section-icon bg-cyan-lt me-2">2</span>
                    Portfolio Limits
                </button>
            </h2>
            <div id="collapsePortfolio" class="accordion-collapse collapse"
                 aria-labelledby="headingPortfolio">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="max_portfolio_exposure_percent">
                                Max Portfolio Exposure
                            </label>
                            <div class="input-group">
                                <input type="number" id="max_portfolio_exposure_percent"
                                       name="max_portfolio_exposure_percent"
                                       class="form-control @error('max_portfolio_exposure_percent') is-invalid @enderror"
                                       value="{{ old('max_portfolio_exposure_percent', $settings->max_portfolio_exposure_percent) }}"
                                       min="1" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('max_portfolio_exposure_percent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">Max % of capital deployed at any time.</small>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="max_concurrent_trades">Max Concurrent Trades</label>
                            <div class="input-group">
                                <input type="number" id="max_concurrent_trades" name="max_concurrent_trades"
                                       class="form-control @error('max_concurrent_trades') is-invalid @enderror"
                                       value="{{ old('max_concurrent_trades', $settings->max_concurrent_trades) }}"
                                       min="1" max="100" step="1">
                                <span class="input-group-text">trades</span>
                            </div>
                            @error('max_concurrent_trades')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="reserve_cash_percent">Reserve Cash</label>
                            <div class="input-group">
                                <input type="number" id="reserve_cash_percent" name="reserve_cash_percent"
                                       class="form-control @error('reserve_cash_percent') is-invalid @enderror"
                                       value="{{ old('reserve_cash_percent', $settings->reserve_cash_percent) }}"
                                       min="0" max="90" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('reserve_cash_percent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">Keep this % of capital undeployed at all times.</small>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="max_position_per_market">Max Positions Per Market</label>
                            <div class="input-group">
                                <input type="number" id="max_position_per_market" name="max_position_per_market"
                                       class="form-control @error('max_position_per_market') is-invalid @enderror"
                                       value="{{ old('max_position_per_market', $settings->max_position_per_market) }}"
                                       min="1" max="10" step="1">
                                <span class="input-group-text">pos</span>
                            </div>
                            @error('max_position_per_market')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="market_cooldown_minutes">Market Cooldown</label>
                            <div class="input-group">
                                <input type="number" id="market_cooldown_minutes" name="market_cooldown_minutes"
                                       class="form-control @error('market_cooldown_minutes') is-invalid @enderror"
                                       value="{{ old('market_cooldown_minutes', $settings->market_cooldown_minutes) }}"
                                       min="0" max="10080" step="1">
                                <span class="input-group-text">min</span>
                            </div>
                            @error('market_cooldown_minutes')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">Wait this long before re-entering a market.</small>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 3 — POSITION SIZING
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingPosition">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePosition"
                        aria-expanded="false" aria-controls="collapsePosition">
                    <span class="section-icon bg-yellow-lt me-2">3</span>
                    Position Sizing
                </button>
            </h2>
            <div id="collapsePosition" class="accordion-collapse collapse"
                 aria-labelledby="headingPosition">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="position_size_mode">Position Size Mode</label>
                            <select id="position_size_mode" name="position_size_mode"
                                    class="form-select @error('position_size_mode') is-invalid @enderror"
                                    onchange="togglePositionSizeFields(this.value)">
                                <option value="fixed_amount"  {{ old('position_size_mode', $settings->position_size_mode) === 'fixed_amount'  ? 'selected' : '' }}>Fixed Amount — per-trade USD</option>
                                <option value="fixed_percent" {{ old('position_size_mode', $settings->position_size_mode) === 'fixed_percent' ? 'selected' : '' }}>Fixed Percent — % of balance</option>
                                <option value="dynamic"       {{ old('position_size_mode', $settings->position_size_mode) === 'dynamic'       ? 'selected' : '' }}>Dynamic — edge-based sizing</option>
                            </select>
                            @error('position_size_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 col-lg-4" id="field_fixed_amount">
                            <label class="form-label fw-medium" for="fixed_amount">Fixed Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" id="fixed_amount" name="fixed_amount"
                                       class="form-control @error('fixed_amount') is-invalid @enderror"
                                       value="{{ old('fixed_amount', $settings->fixed_amount) }}"
                                       min="1" max="100000" step="0.01">
                            </div>
                            @error('fixed_amount')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 col-lg-4" id="field_fixed_percent">
                            <label class="form-label fw-medium" for="fixed_percent">Fixed Percent</label>
                            <div class="input-group">
                                <input type="number" id="fixed_percent" name="fixed_percent"
                                       class="form-control @error('fixed_percent') is-invalid @enderror"
                                       value="{{ old('fixed_percent', $settings->fixed_percent) }}"
                                       min="0.1" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('fixed_percent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">% of available balance per trade.</small>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="enable_dynamic_position_size" name="enable_dynamic_position_size"
                                       value="1"
                                       {{ old('enable_dynamic_position_size', $settings->enable_dynamic_position_size) ? 'checked' : '' }}>
                                <label class="form-check-label" for="enable_dynamic_position_size">
                                    Enable Dynamic Position Size
                                    <small class="text-muted d-block">Scale position by edge strength — higher edge = larger position.</small>
                                </label>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 4 — SIGNAL FILTERS
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingSignals">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSignals"
                        aria-expanded="false" aria-controls="collapseSignals">
                    <span class="section-icon bg-orange-lt me-2">4</span>
                    Signal Filters
                </button>
            </h2>
            <div id="collapseSignals" class="accordion-collapse collapse"
                 aria-labelledby="headingSignals">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="enable_top_signal_filter" name="enable_top_signal_filter"
                                       value="1"
                                       {{ old('enable_top_signal_filter', $settings->enable_top_signal_filter) ? 'checked' : '' }}>
                                <label class="form-check-label" for="enable_top_signal_filter">
                                    Enable Top Signal Filter
                                    <small class="text-muted d-block">Only trade the top-ranked signals per cycle, sorted by score.</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="max_signals_per_cycle">Max Signals Per Cycle</label>
                            <div class="input-group">
                                <input type="number" id="max_signals_per_cycle" name="max_signals_per_cycle"
                                       class="form-control @error('max_signals_per_cycle') is-invalid @enderror"
                                       value="{{ old('max_signals_per_cycle', $settings->max_signals_per_cycle) }}"
                                       min="1" max="100" step="1">
                                <span class="input-group-text">signals</span>
                            </div>
                            @error('max_signals_per_cycle')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">Max trades opened per scheduler cycle.</small>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="minimum_signal_score">Minimum Signal Score</label>
                            <div class="input-group">
                                <input type="number" id="minimum_signal_score" name="minimum_signal_score"
                                       class="form-control @error('minimum_signal_score') is-invalid @enderror"
                                       value="{{ old('minimum_signal_score', $settings->minimum_signal_score) }}"
                                       min="0" max="1" step="0.01">
                                <span class="input-group-text">/ 1.0</span>
                            </div>
                            @error('minimum_signal_score')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">Signals below this score are ignored (0–1).</small>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 5 — TAKE PROFIT
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingTP">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseTP"
                        aria-expanded="false" aria-controls="collapseTP">
                    <span class="section-icon bg-green-lt me-2">5</span>
                    Take Profit
                    @if($settings->enable_take_profit)
                        <span class="ms-2 badge bg-green text-white preset-badge">ON</span>
                    @else
                        <span class="ms-2 badge bg-secondary preset-badge">OFF</span>
                    @endif
                </button>
            </h2>
            <div id="collapseTP" class="accordion-collapse collapse"
                 aria-labelledby="headingTP">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="enable_take_profit" name="enable_take_profit"
                                       value="1"
                                       {{ old('enable_take_profit', $settings->enable_take_profit) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="enable_take_profit">
                                    Enable Take Profit
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="take_profit_mode">Take Profit Mode</label>
                            <select id="take_profit_mode" name="take_profit_mode"
                                    class="form-select @error('take_profit_mode') is-invalid @enderror">
                                <option value="r_multiple"   {{ old('take_profit_mode', $settings->take_profit_mode) === 'r_multiple'   ? 'selected' : '' }}>R-Multiple — X times initial risk</option>
                                <option value="fixed_percent" {{ old('take_profit_mode', $settings->take_profit_mode) === 'fixed_percent' ? 'selected' : '' }}>Fixed Percent — % from entry</option>
                            </select>
                            @error('take_profit_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4 col-lg-2">
                            <label class="form-label fw-medium" for="take_profit_r1">TP1</label>
                            <div class="input-group">
                                <input type="number" id="take_profit_r1" name="take_profit_r1"
                                       class="form-control @error('take_profit_r1') is-invalid @enderror"
                                       value="{{ old('take_profit_r1', $settings->take_profit_r1) }}"
                                       min="0.1" max="100" step="0.1" placeholder="1.0">
                                <span class="input-group-text">R</span>
                            </div>
                            @error('take_profit_r1')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4 col-lg-2">
                            <label class="form-label fw-medium" for="take_profit_r2">TP2 <span class="text-muted small">(optional)</span></label>
                            <div class="input-group">
                                <input type="number" id="take_profit_r2" name="take_profit_r2"
                                       class="form-control @error('take_profit_r2') is-invalid @enderror"
                                       value="{{ old('take_profit_r2', $settings->take_profit_r2) }}"
                                       min="0.1" max="100" step="0.1" placeholder="—">
                                <span class="input-group-text">R</span>
                            </div>
                            @error('take_profit_r2')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4 col-lg-2">
                            <label class="form-label fw-medium" for="take_profit_r3">TP3 <span class="text-muted small">(optional)</span></label>
                            <div class="input-group">
                                <input type="number" id="take_profit_r3" name="take_profit_r3"
                                       class="form-control @error('take_profit_r3') is-invalid @enderror"
                                       value="{{ old('take_profit_r3', $settings->take_profit_r3) }}"
                                       min="0.1" max="100" step="0.1" placeholder="—">
                                <span class="input-group-text">R</span>
                            </div>
                            @error('take_profit_r3')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 6 — STOP LOSS
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingSL">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSL"
                        aria-expanded="false" aria-controls="collapseSL">
                    <span class="section-icon bg-red-lt me-2">6</span>
                    Stop Loss
                    @if($settings->enable_stop_loss)
                        <span class="ms-2 badge bg-green text-white preset-badge">ON</span>
                    @else
                        <span class="ms-2 badge bg-secondary preset-badge">OFF</span>
                    @endif
                </button>
            </h2>
            <div id="collapseSL" class="accordion-collapse collapse"
                 aria-labelledby="headingSL">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="enable_stop_loss" name="enable_stop_loss"
                                       value="1"
                                       {{ old('enable_stop_loss', $settings->enable_stop_loss) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="enable_stop_loss">
                                    Enable Stop Loss
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="stop_loss_mode">Stop Loss Mode</label>
                            <select id="stop_loss_mode" name="stop_loss_mode"
                                    class="form-select @error('stop_loss_mode') is-invalid @enderror">
                                <option value="r_multiple"   {{ old('stop_loss_mode', $settings->stop_loss_mode) === 'r_multiple'   ? 'selected' : '' }}>R-Multiple</option>
                                <option value="fixed_percent" {{ old('stop_loss_mode', $settings->stop_loss_mode) === 'fixed_percent' ? 'selected' : '' }}>Fixed Percent</option>
                            </select>
                            @error('stop_loss_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="stop_loss_value">Stop Loss Value</label>
                            <div class="input-group">
                                <input type="number" id="stop_loss_value" name="stop_loss_value"
                                       class="form-control @error('stop_loss_value') is-invalid @enderror"
                                       value="{{ old('stop_loss_value', $settings->stop_loss_value) }}"
                                       min="0.1" max="100" step="0.1">
                                <span class="input-group-text">R / %</span>
                            </div>
                            @error('stop_loss_value')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 7 — BREAKEVEN
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingBE">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBE"
                        aria-expanded="false" aria-controls="collapseBE">
                    <span class="section-icon bg-teal-lt me-2">7</span>
                    Breakeven
                    @if($settings->enable_move_to_breakeven)
                        <span class="ms-2 badge bg-green text-white preset-badge">ON</span>
                    @else
                        <span class="ms-2 badge bg-secondary preset-badge">OFF</span>
                    @endif
                </button>
            </h2>
            <div id="collapseBE" class="accordion-collapse collapse"
                 aria-labelledby="headingBE">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="enable_move_to_breakeven" name="enable_move_to_breakeven"
                                       value="1"
                                       {{ old('enable_move_to_breakeven', $settings->enable_move_to_breakeven) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="enable_move_to_breakeven">
                                    Enable Move to Breakeven
                                    <small class="text-muted d-block">Automatically move stop loss to entry when TP trigger is hit.</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-medium" for="breakeven_trigger_r">Breakeven Trigger</label>
                            <div class="input-group">
                                <input type="number" id="breakeven_trigger_r" name="breakeven_trigger_r"
                                       class="form-control @error('breakeven_trigger_r') is-invalid @enderror"
                                       value="{{ old('breakeven_trigger_r', $settings->breakeven_trigger_r) }}"
                                       min="0.1" max="100" step="0.1">
                                <span class="input-group-text">R</span>
                            </div>
                            @error('breakeven_trigger_r')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-muted">Move SL to entry when price reaches this R multiple.</small>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 8 — PARTIAL TAKE PROFIT
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingPartialTP">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePartialTP"
                        aria-expanded="false" aria-controls="collapsePartialTP">
                    <span class="section-icon bg-lime-lt me-2">8</span>
                    Partial Take Profit
                    @if($settings->enable_partial_take_profit)
                        <span class="ms-2 badge bg-green text-white preset-badge">ON</span>
                    @else
                        <span class="ms-2 badge bg-secondary preset-badge">OFF</span>
                    @endif
                </button>
            </h2>
            <div id="collapsePartialTP" class="accordion-collapse collapse"
                 aria-labelledby="headingPartialTP">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="enable_partial_take_profit" name="enable_partial_take_profit"
                                       value="1"
                                       {{ old('enable_partial_take_profit', $settings->enable_partial_take_profit) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="enable_partial_take_profit">
                                    Enable Partial Take Profit
                                    <small class="text-muted d-block">Close a portion of the position at each TP level. Total must equal 100%.</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="partial_tp1_percent">TP1 Close</label>
                            <div class="input-group">
                                <input type="number" id="partial_tp1_percent" name="partial_tp1_percent"
                                       class="form-control @error('partial_tp1_percent') is-invalid @enderror"
                                       value="{{ old('partial_tp1_percent', $settings->partial_tp1_percent) }}"
                                       min="1" max="100" step="1" placeholder="50">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('partial_tp1_percent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="partial_tp2_percent">TP2 Close</label>
                            <div class="input-group">
                                <input type="number" id="partial_tp2_percent" name="partial_tp2_percent"
                                       class="form-control @error('partial_tp2_percent') is-invalid @enderror"
                                       value="{{ old('partial_tp2_percent', $settings->partial_tp2_percent) }}"
                                       min="1" max="100" step="1" placeholder="30">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('partial_tp2_percent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="partial_tp3_percent">TP3 Close</label>
                            <div class="input-group">
                                <input type="number" id="partial_tp3_percent" name="partial_tp3_percent"
                                       class="form-control @error('partial_tp3_percent') is-invalid @enderror"
                                       value="{{ old('partial_tp3_percent', $settings->partial_tp3_percent) }}"
                                       min="1" max="100" step="1" placeholder="20">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('partial_tp3_percent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <div class="alert alert-info py-2 mb-0 small" role="alert">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 8h.01"/><path d="M11 12h1v4h1"/>
                                    <circle cx="12" cy="12" r="9"/>
                                </svg>
                                TP1 + TP2 + TP3 percentages should total <strong>100%</strong> for a complete position close.
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             SECTION 9 — SMART EXIT
        ──────────────────────────────────────────────────────────────── --}}
        <div class="accordion-item card mb-2">
            <h2 class="accordion-header" id="headingSmartExit">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSmartExit"
                        aria-expanded="false" aria-controls="collapseSmartExit">
                    <span class="section-icon bg-azure-lt me-2">9</span>
                    Smart Exit
                    @if($settings->enable_smart_exit)
                        <span class="ms-2 badge bg-green text-white preset-badge">ON</span>
                    @else
                        <span class="ms-2 badge bg-secondary preset-badge">OFF</span>
                    @endif
                </button>
            </h2>
            <div id="collapseSmartExit" class="accordion-collapse collapse"
                 aria-labelledby="headingSmartExit">
                <div class="accordion-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="enable_smart_exit" name="enable_smart_exit"
                                       value="1"
                                       {{ old('enable_smart_exit', $settings->enable_smart_exit) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="enable_smart_exit">
                                    Enable Smart Exit
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="card bg-azure-lt border-0">
                                <div class="card-body py-3">
                                    <div class="d-flex align-items-start gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon text-azure mt-1 flex-shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2l4 -4"/>
                                        </svg>
                                        <div>
                                            <div class="fw-medium text-azure-fg mb-2">Smart Exit monitors these conditions in real time:</div>
                                            <ul class="mb-0 small text-muted" style="line-height: 1.8">
                                                <li><strong>Momentum deterioration</strong> — 24h price momentum reverses against position</li>
                                                <li><strong>Liquidity deterioration</strong> — available liquidity drops below threshold</li>
                                                <li><strong>Volume decline</strong> — 24h trading volume falls significantly</li>
                                                <li><strong>Spread expansion</strong> — bid/ask spread widens beyond acceptable range</li>
                                                <li><strong>Market expiry</strong> — market approaching end date with open position</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>{{-- /accordion --}}

    {{-- ================================================================
         STICKY SAVE BAR
    ================================================================ --}}
    <div class="settings-save-bar">
        <div class="container-xl">
            <div class="d-flex align-items-center justify-content-between gap-3">
                <div class="text-muted small d-none d-md-block">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9"/><path d="M12 8h.01"/><path d="M11 12h1v4h1"/>
                    </svg>
                    Changes apply to new trades only. Open positions are not affected.
                </div>
                <div class="d-flex gap-2 ms-auto">
                    <a href="{{ route('paper-trades.index') }}" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2"/><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M14 4l0 4l-6 0l0 -4"/>
                        </svg>
                        Save Settings
                    </button>
                </div>
            </div>
        </div>
    </div>

</form>{{-- /form --}}

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Position size field visibility toggle
    // -----------------------------------------------------------------------
    function togglePositionSizeFields(mode) {
        const fieldAmount  = document.getElementById('field_fixed_amount');
        const fieldPercent = document.getElementById('field_fixed_percent');

        if (!fieldAmount || !fieldPercent) return;

        if (mode === 'fixed_amount') {
            fieldAmount.classList.remove('d-none');
            fieldPercent.classList.add('d-none');
        } else if (mode === 'fixed_percent') {
            fieldAmount.classList.add('d-none');
            fieldPercent.classList.remove('d-none');
        } else {
            // dynamic — show percent as a base, amount hidden
            fieldAmount.classList.add('d-none');
            fieldPercent.classList.remove('d-none');
        }
    }

    // Expose for inline onchange
    window.togglePositionSizeFields = togglePositionSizeFields;

    // Run on page load
    const modeSelect = document.getElementById('position_size_mode');
    if (modeSelect) {
        togglePositionSizeFields(modeSelect.value);
        modeSelect.addEventListener('change', function () {
            togglePositionSizeFields(this.value);
        });
    }

    // -----------------------------------------------------------------------
    // Mark preset as "custom" if user edits any field manually
    // -----------------------------------------------------------------------
    const presetSelect = document.getElementById('preset');
    const formInputs   = document.querySelectorAll(
        '#settingsForm input:not([name="preset"]):not([name="apply_preset"]), #settingsForm select:not([name="preset"])'
    );

    formInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            if (presetSelect) presetSelect.value = 'custom';
        });
    });

    // -----------------------------------------------------------------------
    // Partial TP percent live sum hint
    // -----------------------------------------------------------------------
    const tp1Input = document.getElementById('partial_tp1_percent');
    const tp2Input = document.getElementById('partial_tp2_percent');
    const tp3Input = document.getElementById('partial_tp3_percent');

    function updatePartialTPSum() {
        const t1 = parseFloat(tp1Input?.value || 0);
        const t2 = parseFloat(tp2Input?.value || 0);
        const t3 = parseFloat(tp3Input?.value || 0);
        const sum = t1 + t2 + t3;

        [tp1Input, tp2Input, tp3Input].forEach(function (el) {
            if (!el) return;
            el.classList.toggle('is-valid',   Math.round(sum) === 100);
            el.classList.toggle('is-invalid', sum > 0 && Math.round(sum) !== 100);
        });
    }

    [tp1Input, tp2Input, tp3Input].forEach(function (el) {
        if (el) el.addEventListener('input', updatePartialTPSum);
    });

    // -----------------------------------------------------------------------
    // Save button loading state
    // -----------------------------------------------------------------------
    const form    = document.getElementById('settingsForm');
    const saveBtn = document.getElementById('saveBtn');

    if (form && saveBtn) {
        form.addEventListener('submit', function (e) {
            // Only show spinner for the Save button, not preset buttons
            const submitter = e.submitter;
            if (submitter && submitter.id === 'saveBtn') {
                saveBtn.disabled = true;
                saveBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Saving…';
            }
        });
    }

    // -----------------------------------------------------------------------
    // Initialise Tabler tooltips
    // -----------------------------------------------------------------------
    if (typeof bootstrap !== 'undefined') {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el, { trigger: 'hover' });
        });
    }

})();
</script>
@endpush
