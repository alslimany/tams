<?php

use App\Support\Iso8601Duration;

test('fromMinutes returns PT0M for zero or negative minutes', function () {
    expect(Iso8601Duration::fromMinutes(0))->toBe('PT0M')
        ->and(Iso8601Duration::fromMinutes(-10))->toBe('PT0M');
});

test('fromMinutes returns hours and minutes format', function () {
    expect(Iso8601Duration::fromMinutes(130))->toBe('PT2H10M')
        ->and(Iso8601Duration::fromMinutes(800))->toBe('PT13H20M');
});

test('fromMinutes returns hours only when minutes remainder is zero', function () {
    expect(Iso8601Duration::fromMinutes(120))->toBe('PT2H');
});

test('fromMinutes returns minutes only when under one hour', function () {
    expect(Iso8601Duration::fromMinutes(45))->toBe('PT45M');
});
