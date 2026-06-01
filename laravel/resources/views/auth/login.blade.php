<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <title>Login — Polymarket Intelligence</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <style>
        body { background: #0f172a; }
        .card { border: 1px solid rgba(255,255,255,0.08); }
    </style>
</head>
<body class="d-flex flex-column" data-bs-theme="dark">
<div class="page page-center">
    <div class="container container-tight py-4">
        <div class="text-center mb-4">
            <span class="fs-2 fw-bold text-white">📊 Polymarket Intelligence</span>
            <div class="text-muted small mt-1">Prediction Market Analytics Platform</div>
        </div>

        <div class="card card-md">
            <div class="card-body">
                <h2 class="h2 text-center mb-4">Sign in</h2>

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email"
                               name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}"
                               placeholder="your@email.com"
                               autofocus
                               required>
                        @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Password</label>
                        <div class="input-group input-group-flat">
                            <input type="password"
                                   name="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   placeholder="Password"
                                   autocomplete="current-password"
                                   required>
                            @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-check">
                            <input type="checkbox" name="remember" class="form-check-input"/>
                            <span class="form-check-label">Remember me</span>
                        </label>
                    </div>

                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary w-100">
                            Sign in
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="text-center text-muted mt-3 small">
            All times displayed in UTC
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
</body>
</html>
