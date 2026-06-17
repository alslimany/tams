<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

uses(Tests\TestCase::class);

test('tenant database suffix is empty when the tenant driver is mysql', function () {
    config()->set('tenancy.database.suffix', '');

    expect(config('tenancy.database.suffix'))->toBe('');
});

test('tenant connection falls back to the application database connection', function () {
    expect(config('database.connections.tenant.driver'))->toBe(config('database.default'));
});

test('tenant api routes initialize tenancy before route model binding', function () {
    $router = app('router');

    $route = collect($router->getRoutes())->first(
        fn ($route) => $route->uri() === 'agency/{tenant}/api/v1/orders/{order}',
    );

    expect($route)->not->toBeNull();

    $middleware = app('router')->gatherRouteMiddleware($route);

    $tenancyIndex = array_search(InitializeTenancyByPath::class, $middleware, true);
    $bindingsIndex = array_search(SubstituteBindings::class, $middleware, true);

    expect($tenancyIndex)->not->toBeFalse();
    expect($bindingsIndex)->not->toBeFalse();
    expect($tenancyIndex)->toBeLessThan($bindingsIndex);
});
