<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'financial-schema-'.Str::random(4),
        'company_name' => 'Financial Schema Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('tenant financial schema includes required columns', function () {
    expect(Schema::hasColumns('tenant_providers', [
        'commission_domestic',
        'commission_international',
    ]))->toBeTrue();

    expect(Schema::hasColumns('order_items', [
        'product_type',
        'product_details',
        'commission_percent',
        'commission_amount',
        'net_after_commission',
        'wallet_transaction_id',
        'airline_transaction_id',
        'ledger_entry_id',
    ]))->toBeTrue();

    expect(Schema::hasColumns('orders', [
        'ledger_entry_id',
    ]))->toBeTrue();

    expect(Schema::getColumnType('orders', 'id'))->toBe('uuid')
        ->and(Schema::getColumnType('tenant_insurance_provider_transactions', 'order_id'))->toBe('uuid');

    $taxesColumnType = Schema::getColumnType('order_items', 'taxes');

    expect(in_array($taxesColumnType, ['json', 'text'], true))->toBeTrue();
});

test('airport countries central table exists', function () {
    tenancy()->end();

    expect(Schema::hasColumns('airport_countries', [
        'country_code',
        'country_name',
        'iso3_code',
        'is_active',
    ]))->toBeTrue();
});
