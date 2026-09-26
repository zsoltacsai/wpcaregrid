<?php

use App\Connector\ConnectorError;
use App\Http\Middleware\AuthenticateConnector;
use App\Http\Middleware\EnsureCustomerAccount;
use App\Http\Middleware\EnsureOperator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::middleware('web')->group(__DIR__.'/../routes/operator.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stripe signs webhook requests; they cannot carry a CSRF token.
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);

        $middleware->alias([
            'customer' => EnsureCustomerAccount::class,
            'operator' => EnsureOperator::class,
            'connector.auth' => AuthenticateConnector::class,
        ]);

        // Identify the connector before throttling, so limits are per connector, not per IP.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateConnector::class);

        // Logged-in visitors of guest pages (login, reset) go to their account;
        // the operator console has its own login and its own landing page.
        $isOperatorArea = fn (Request $request) => $request->is('operator', 'operator/*');
        $middleware->redirectUsersTo(fn (Request $request) => $isOperatorArea($request) ? route('operator.dashboard') : route('account.dashboard'));
        $middleware->redirectGuestsTo(fn (Request $request) => $isOperatorArea($request) ? route('operator.login') : route('login'));
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

        // Operator console: a plain "not permitted" page, never a stack trace.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('operator', 'operator/*') && ! $request->expectsJson() && Auth::guard('operator')->check()) {
                Log::notice('Operator action refused.', ['operator' => Auth::guard('operator')->id(), 'route' => $request->route()?->getName()]);

                return response()->view('operator.errors.forbidden', [], 403);
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
