<?php

use App\Support\FareRulesFormatter;

test('toPlainText strips dotted line numbers', function () {
    $input = "1. CHANGES PERMITTED WITH FEE\n2. REFUNDS NOT PERMITTED";

    expect(FareRulesFormatter::toPlainText($input))->toBe("CHANGES PERMITTED WITH FEE\nREFUNDS NOT PERMITTED");
});

test('toPlainText strips spaced numeric prefixes', function () {
    $input = "  1 CHANGES PERMITTED\n  2 REFUNDS NOT PERMITTED";

    expect(FareRulesFormatter::toPlainText($input))->toBe("CHANGES PERMITTED\nREFUNDS NOT PERMITTED");
});

test('toPlainText preserves unnumbered lines', function () {
    $input = "CHANGES PERMITTED WITH FEE\nNO REFUND AFTER DEPARTURE";

    expect(FareRulesFormatter::toPlainText($input))->toBe($input);
});
