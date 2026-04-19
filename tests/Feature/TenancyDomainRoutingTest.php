<?php

use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

test('central domain home resolves without tenancy exception', function () {
    $response = $this->get('http://tams.test/');

    $response->assertOk();
});

test('tenant home route keeps tenancy middleware protections', function () {
    $route = app('router')->getRoutes()->getByName('home');

    expect($route)->not->toBeNull();
    expect($route->uri())->toBe('/');
    expect($route->gatherMiddleware())->toContain(InitializeTenancyByDomain::class);
    expect($route->gatherMiddleware())->toContain(PreventAccessFromCentralDomains::class);
});
