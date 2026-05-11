<?php

test('tenant database suffix is empty when the tenant driver is mysql', function () {
    config()->set('tenancy.database.suffix', '');

    expect(config('tenancy.database.suffix'))->toBe('');
});

test('tenant connection falls back to the application database connection', function () {
    expect(config('database.connections.tenant.driver'))->toBe(config('database.default'));
});
