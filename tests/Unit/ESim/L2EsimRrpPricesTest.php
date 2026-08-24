<?php

use App\Services\ESim\Pricing\L2EsimRrpPrices;

test('l2 esim rrp prices returns known package refs', function () {
    expect((float) L2EsimRrpPrices::get('esimd_1GB_7D_AX_V2'))->toBe(2.0)
        ->and((float) L2EsimRrpPrices::get('esimd_ULP_1D_AX_V2'))->toBe(2.5);
});

test('l2 esim rrp prices returns null for missing and na package refs', function () {
    expect(L2EsimRrpPrices::get('esimd_50GB_30D_RAF_V2'))->toBeNull()
        ->and(L2EsimRrpPrices::get('esim_unknown_package'))->toBeNull()
        ->and(L2EsimRrpPrices::get(''))->toBeNull();
});

test('l2 esim rrp prices all map is keyed by package ref', function () {
    $all = L2EsimRrpPrices::all();

    expect($all)->toBeArray()
        ->and($all)->toHaveKey('esimd_1GB_7D_AX_V2')
        ->and($all)->not->toHaveKey('esimd_50GB_30D_RAF_V2')
        ->and(count($all))->toBeGreaterThan(2000);
});
