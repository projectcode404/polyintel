<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — Polymarket Intelligence</title>

    {{-- Tabler CSS --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    {{-- AG Grid Community --}}
    <script src="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.2/dist/ag-grid-community.min.js"></script>

    <style>
        :root {
            --tblr-font-sans-serif: 'Inter', system-ui, -apple-system, sans-serif;
        }
        .ag-theme-alpine {
            --ag-font-size: 13px;
            --ag-row-height: 42px;
            --ag-header-height: 42px;
        }
        .probability-bar {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            overflow: hidden;
        }
        .probability-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        .nav-link-active {
            color: var(--tblr-primary) !important;
            background-color: rgba(var(--tblr-primary-rgb), 0.08) !important;
        }
    </style>

    @stack('styles')
</head>
<body class="antialiased">
<div class="wrapper">

    {{-- ================================================================
         SIDEBAR
    ================================================================ --}}
    <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
        <div class="container-fluid">

            {{-- Mobile toggle --}}
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu">
                <span class="navbar-toggler-icon"></span>
            </button>

            {{-- Brand --}}
            <h1 class="navbar-brand navbar-brand-autodark">
                <a href="{{ route('dashboard') }}">
                    <span class="fw-bold text-white">
                        📊 Polymarket Intel
                    </span>
                </a>
            </h1>

            {{-- User (mobile) --}}
            <div class="navbar-nav flex-row d-lg-none">
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">
                        <span class="avatar avatar-sm">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </span>
                    </a>
                </div>
            </div>

            {{-- Menu --}}
            <div class="collapse navbar-collapse" id="sidebar-menu">
                <ul class="navbar-nav pt-lg-3">

                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('dashboard') ? 'active nav-link-active' : '' }}"
                           href="{{ route('dashboard') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l-2 0l9 -9l9 9l-2 0"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"/><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6"/>
                                </svg>
                            </span>
                            <span class="nav-link-title">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('markets.*') ? 'active nav-link-active' : '' }}"
                           href="{{ route('markets.index') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 3m0 1a1 1 0 0 1 1 -1h16a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-16a1 1 0 0 1 -1 -1z"/><path d="M3 10h18"/><path d="M3 14h18"/><path d="M3 18h18"/>
                                </svg>
                            </span>
                            <span class="nav-link-title">Markets</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('signals.*') ? 'active nav-link-active' : '' }}"
                           href="{{ route('signals.index') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h1m8 -9v1m8 8h1m-15.4 -6.4l.7 .7m12.1 -.7l-.7 .7"/><circle cx="12" cy="12" r="4"/><path d="M3 21l3 -3m15 0l-3 -3"/>
                                </svg>
                            </span>
                            <span class="nav-link-title">Signals</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('paper-trades.*') ? 'active nav-link-active' : '' }}"
                           href="{{ route('paper-trades.index') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 3m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"/><path d="M3 9h18v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"/><path d="M9 13l2 2l4 -4"/>
                                </svg>
                            </span>
                            <span class="nav-link-title">Paper Trades</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('performance.*') ? 'active nav-link-active' : '' }}"
                           href="{{ route('performance.index') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 20l18 0"/><path d="M3 20l7.5 -14.5"/><path d="M10.5 5.5l3.5 7"/><path d="M14 12.5l6.5 7.5"/>
                                </svg>
                            </span>
                            <span class="nav-link-title">Performance</span>
                        </a>
                    </li>

                    @role('admin')
                    <li class="nav-item mt-3">
                        <div class="nav-link text-muted small text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.08em;">
                            Admin
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active nav-link-active' : '' }}"
                           href="{{ route('admin.users.index') }}">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/>
                                </svg>
                            </span>
                            <span class="nav-link-title">Users</span>
                        </a>
                    </li>
                    @endrole

                </ul>

                {{-- Bottom: user info --}}
                <div class="mt-auto d-none d-lg-block pb-3">
                    <div class="d-flex align-items-center px-2 py-2 rounded" style="background: rgba(255,255,255,0.05)">
                        <span class="avatar avatar-sm me-2" style="background: var(--tblr-primary)">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </span>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="text-white text-truncate small fw-medium">
                                {{ auth()->user()->name }}
                            </div>
                            <div class="text-muted small text-truncate">
                                <span class="badge badge-sm {{ auth()->user()->role_badge_class }}">
                                    {{ auth()->user()->role_label }}
                                </span>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-ghost-light" title="Logout">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"/><path d="M9 12h12l-3 -3"/><path d="M18 15l3 -3"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    {{-- ================================================================
         MAIN CONTENT
    ================================================================ --}}
    <div class="page-wrapper">

        {{-- Header --}}
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <h2 class="page-title">@yield('page-title', 'Dashboard')</h2>
                        @hasSection('page-subtitle')
                        <div class="text-muted mt-1">@yield('page-subtitle')</div>
                        @endif
                    </div>
                    <div class="col-auto ms-auto">
                        @yield('page-actions')
                    </div>
                </div>
            </div>
        </div>

        {{-- Flash messages --}}
        <div class="container-xl mt-2">
            @if(session('success'))
            <div class="alert alert-success alert-dismissible" role="alert">
                <div class="d-flex">
                    <div>{{ session('success') }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            @endif
            @if(session('error'))
            <div class="alert alert-danger alert-dismissible" role="alert">
                <div class="d-flex">
                    <div>{{ session('error') }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            @endif
        </div>

        {{-- Page content --}}
        <div class="page-body">
            <div class="container-xl">
                @yield('content')
            </div>
        </div>

        {{-- Footer --}}
        <footer class="footer footer-transparent d-print-none">
            <div class="container-xl">
                <div class="row text-center align-items-center">
                    <div class="col-12 col-lg-auto">
                        <span class="text-muted small">
                            Polymarket Intelligence — Data collected every 5 minutes — All times UTC
                        </span>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</div>

{{-- Tabler JS --}}
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>

@stack('scripts')
</body>
</html>
