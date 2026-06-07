<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// =============================================================================
// TESTING SAFETY GUARD
// Force SQLite in-memory when running under PHPUnit.
// This prevents RefreshDatabase from wiping the production PostgreSQL database.
// =============================================================================
if (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'testing') {
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE']   = ':memory:';
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
