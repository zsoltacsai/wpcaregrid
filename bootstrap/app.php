<?php

use App\Connector\ConnectorError;
use App\Http\Middleware\AuthenticateConnector;
use App\Http\Middleware\EnsureCustomerAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stripe signs webhook requests; they cannot carry a CSRF token.
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);

        $middleware->alias([
            'customer' => EnsureCustomerAccount::class,
            'connector.auth' => AuthenticateConnector::class,
        ]);

        // Identify the connector before throttling, so limits are per connector, not per IP.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateConnector::class);

        // Logged-in visitors of guest pages (login, reset) go to their account.
        $middleware->redirectUsersTo(fn () => route('account.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Connector API refusals: {"error": {"code", "message"}}.
        $exceptions->render(fn (ConnectorError $e) => AuthenticateConnector::error($e->errorCode, $e->getMessage(), $e->status, $e->details));
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => ['code' => 'INVALID_REQUEST', 'message' => 'The request is invalid.', 'fields' => $e->errors()]], 422);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.']], 429, $e->getHeaders());
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
