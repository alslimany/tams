<?php

use App\Services\Airline\CabinClassFilter;

test('cabin class all does not filter offers', function () {
    $offers = [
        ['pricing' => ['cabin_type' => 'Y'], 'segments' => [['class' => 'Y']]],
        ['pricing' => ['cabin_type' => 'C'], 'segments' => [['class' => 'C']]],
    ];

    expect(CabinClassFilter::filter($offers, 'all'))->toHaveCount(2);
});

test('cabin class Y keeps economy offers only', function () {
    $offers = [
        ['pricing' => ['cabin_type' => 'Y'], 'segments' => [['class' => 'Y']]],
        ['pricing' => ['cabin_type' => 'C'], 'segments' => [['class' => 'C']]],
    ];

    $filtered = CabinClassFilter::filter($offers, 'Y');

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['pricing']['cabin_type'])->toBe('Y');
});

test('economy label maps to Y cabin filter', function () {
    expect(CabinClassFilter::normalize('economy'))->toBe('Y')
        ->and(CabinClassFilter::normalize('business'))->toBe('C')
        ->and(CabinClassFilter::normalize('all'))->toBeNull();
});
