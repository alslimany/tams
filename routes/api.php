<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

/*
|--------------------------------------------------------------------------
| API Routes (Path-Based Tenancy)
|--------------------------------------------------------------------------
|
| Entry point for all API versions. The /agency/{tenant} prefix is set
| by routes/api.php. Each version file lives under routes/api/.
|
| All API calls follow: https://{central}/agency/{tenant}/api/v1/...
|
*/

Route::prefix(config('tenancy.tenant_path_prefix', 'agency').'/{tenant}')->group(function (): void {
    Route::middleware([
        InitializeTenancyByPath::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ])->group(function (): void {
        Route::prefix('api/v1')->group(__DIR__.'/api/v1.php');
    });
});
