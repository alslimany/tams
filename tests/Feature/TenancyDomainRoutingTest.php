<?php

use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

test('central domain home resolves without tenancy exception', function () {
    $response = $this->get('http://tams.test/');

    $response->assertOk();
});

test('central domain can switch public language', function () {
    $response = $this
        ->from('http://tams.test/')
        ->get('http://tams.test/language/switch?locale=ar');

    $response->assertRedirect('http://tams.test/');

    expect(session('locale'))->toBe('ar');
});

test('tenant home route keeps tenancy middleware protections', function () {
    $route = app('router')->getRoutes()->getByName('home');

    expect($route)->not->toBeNull();
    expect($route->uri())->toBe('/');
    expect($route->gatherMiddleware())->toContain(InitializeTenancyByDomain::class);
    expect($route->gatherMiddleware())->toContain(PreventAccessFromCentralDomains::class);
});
