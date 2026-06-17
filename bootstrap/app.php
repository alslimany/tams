<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\TrackUserActivity::class,
            \App\Http\Middleware\CheckActiveUser::class,
        ]);

        // $middleware->redirectTo(
        //     guests: '/login',
        //     users: '/flights' // Change this from '/dashboard' to '/'
        // );

        $middleware->priority([
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\HandleInertiaRequests::class, // Must be prioritized
            \Illuminate\Auth\Middleware\Authenticate::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'landlord.auth' => \App\Http\Middleware\EnsureLandlordAuthenticated::class,
            'tenant.status' => \App\Http\Middleware\CheckTenantOperationalStatus::class,
            'wallet.balance' => \App\Http\Middleware\EnsureSufficientWalletBalance::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'idempotency' => \App\Http\Middleware\IdempotencyMiddleware::class,
            'audit.api' => \App\Http\Middleware\AuditApiRequestMiddleware::class,
            'audit.recorder' => \App\Audit\AuditRecorderMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 419) {
                return back()->with('error', __('common.page_expired'));
            }

            return $response;
        });

        $exceptions->render(function (\Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException $e, \Illuminate\Http\Request $request) {
            return response()->view('errors.tenant-not-found', [], 404);
        });
    })->create();
