<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

class AccountNumberingService
{
    /**
     * Account-type ranges used when suggesting top-level codes.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const TYPE_RANGES = [
        'asset' => [1000, 1999],
        'liability' => [2000, 2999],
        'equity' => [3000, 3999],
        'revenue' => [4000, 4999],
        'expense' => [7000, 7999],
        'purchase' => [6000, 6999],
    ];

    /**
     * Given a parent account code, return the next available child code.
     *
     * Examples:
     *   parent "4000" → children step by 100 → next unused of 4100, 4200, ...
     *   parent "4100" → children step by 10  → next unused of 4110, 4120, ...
     */
    public function nextAvailableCode(string $parentCode): string
    {
        $existing = DB::table('coa_settings')
            ->where('parent_code', $parentCode)
            ->pluck('code')
            ->map(fn ($code) => (int) $code)
            ->sort()
            ->values();

        $parentValue = (int) $parentCode;
        $step = $this->childStep($parentValue);

        for ($candidate = $parentValue + $step; $candidate < $parentValue + ($step * 10); $candidate += $step) {
            if (! $existing->contains($candidate) && ! $this->codeExists((string) $candidate)) {
                return (string) $candidate;
            }
        }

        // Standard range is full — fall back to the next sequential code.
        $last = (int) ($existing->last() ?? $parentValue);

        do {
            $last++;
        } while ($this->codeExists((string) $last));

        return (string) $last;
    }

    /**
     * Suggest a code when creating a top-level account under a type group.
     */
    public function nextTopLevelCode(string $accountType): string
    {
        [$start, $end] = self::TYPE_RANGES[$accountType] ?? [9000, 9999];

        $existing = DB::table('coa_settings')
            ->whereNull('parent_code')
            ->pluck('code')
            ->map(fn ($code) => (int) $code)
            ->filter(fn (int $code) => $code >= $start && $code <= $end)
            ->sort()
            ->values();

        $last = (int) ($existing->last() ?? ($start - 100));
        $candidate = min($last + 100, $end);

        while ($this->codeExists((string) $candidate) && $candidate < $end) {
            $candidate += 100;
        }

        return (string) min($candidate, $end);
    }

    /**
     * Determine the numbering step for children of the given parent code.
     * Top-level thousands (4000) step by 100; hundreds (4100) step by 10; deeper by 1.
     */
    private function childStep(int $parentValue): int
    {
        if ($parentValue % 1000 === 0) {
            return 100;
        }

        if ($parentValue % 100 === 0) {
            return 10;
        }

        return 1;
    }

    /**
     * Check both coa_settings and ledger_accounts so suggestions never collide
     * with an account that exists in only one of the two tables.
     */
    private function codeExists(string $code): bool
    {
        if (DB::table('ledger_accounts')->where('code', $code)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return DB::table('coa_settings')->where('code', $code)->exists()
            && ! DB::table('ledger_accounts')->where('code', $code)->exists();
    }
}
