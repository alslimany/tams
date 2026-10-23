<?php

namespace App\Services\Airline\Videcom\Monitor;

use RuntimeException;

/**
 * Allow-list guard for the read-only Videcom monitor.
 *
 * Only availability (A..~X), pricing quotes (i^ ... *r~x), fare notes (FN) and
 * seat maps are allowed. Anything that could save a PNR, issue, void, refund
 * or change a ticket aborts the whole monitor run.
 */
class ReadOnlyCommandGuard
{
    /** Patterns that must never appear anywhere in an outgoing command. */
    public const FORBIDDEN_SUBSTRINGS = ['E*R', 'EZT', 'EZRE', 'EZV', 'REF*', 'X1^E'];

    /** Patterns that must never start a ^-separated entry. */
    public const FORBIDDEN_ENTRY_REGEX = [
        '/^E/',        // E, ER, E*R, EZT, EZRE, EZV ... (end transaction / issue / void)
        '/^TV/',       // ticket void
        '/^REF/',      // refund
        '/^X\d/',      // cancel segment
        '/^\*R$/i',    // retrieve (not needed by the monitor)
        '/^MT/',       // ticket time limit / queue
        '/^4-/',       // seat select / SSR on a PNR
    ];

    /** Every command must match one of these. */
    public const ALLOWED_REGEX = [
        'availability' => '/^A\d{2}[A-Z]{3}[A-Z]{3}[A-Z]{3}(\[[^\]]*\])?~X$/',
        'pricing_quote' => '/^i\^.+\^\*r~x$/',
        'fare_rules' => '/^FN[0-9A-Z]+$/',
        'seat_map' => '/^(i\^)?ls\d{1,4}[A-Z]?\d{2}[A-Z]{3}[A-Z]{6}~x$/i',
    ];

    public bool $violated = false;

    /** @var list<array{command: string, reason: string}> */
    public array $violations = [];

    /** @var array<string, int> */
    public array $counts = [];

    public function check(string $command): string
    {
        $command = trim($command);

        foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
            if (str_contains($command, $needle)) {
                $this->fail($command, "forbidden substring {$needle}");
            }
        }

        foreach (explode('^', $command) as $index => $entry) {
            // The leading "i" (ignore/clear) entry is required and allowed.
            if ($index === 0 && $entry === 'i') {
                continue;
            }

            foreach (self::FORBIDDEN_ENTRY_REGEX as $regex) {
                if (preg_match($regex, $entry)) {
                    $this->fail($command, "forbidden entry '{$entry}' ({$regex})");
                }
            }
        }

        foreach (self::ALLOWED_REGEX as $kind => $regex) {
            if (preg_match($regex, $command)) {
                $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;

                return $kind;
            }
        }

        $this->fail($command, 'not on the read-only allow-list');
    }

    protected function fail(string $command, string $reason): never
    {
        $this->violated = true;
        $this->violations[] = ['command' => $command, 'reason' => $reason];

        throw new RuntimeException("videcom:monitor safety guard blocked command [{$command}]: {$reason}");
    }
}
