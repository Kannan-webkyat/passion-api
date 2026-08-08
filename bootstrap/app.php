<?php

use App\Exceptions\JournalPostingException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum', 'active']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only app: never redirect guests to a web "login" route (causes 500).
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (JournalPostingException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = match (true) {
                str_contains($e->getMessage(), 'pos_void_waste') => 'POS void waste table is missing. Run database migrations on the server.',
                str_contains($e->getMessage(), 'journal_entries'),
                str_contains($e->getMessage(), 'chart_of_accounts') => 'Accounting tables are missing or incomplete. Run database migrations on the server.',
                default => 'Database error while saving. Contact support if this continues.',
            };

            return response()->json(['message' => $message], 422);
        });
    })->create();
