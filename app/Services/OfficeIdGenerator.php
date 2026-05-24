<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates Amadeus-style 9-character Office IDs for tenant agencies.
 *
 * Format: [CCC][AA][NN][TT]
 *   CCC — 3-letter IATA city/airport code (e.g. MJI for Tripoli)
 *   AA  — 2-letter agency corporate code derived from company name
 *   NN  — 2-digit sequence (01–99) for same city+code combo
 *   TT  — 2-letter business type (AA = standard travel agency)
 */
class OfficeIdGenerator
{
    public const DEFAULT_CITY_IATA = 'MJI';

    public const BUSINESS_TYPE = 'AA';

    /**
     * Generate a unique Office ID for the given city and company name.
     * Tries sequences 01–99 until a free slot is found.
     *
     * @throws \RuntimeException if all 99 sequences are exhausted
     */
    public function generate(string $cityIata, string $companyName): string
    {
        $city = strtoupper(substr(trim($cityIata), 0, 3));
        $code = $this->agencyCode($companyName);

        for ($seq = 1; $seq <= 99; $seq++) {
            $officeId = sprintf('%s%s%02d%s', $city, $code, $seq, self::BUSINESS_TYPE);

            if (! $this->exists($officeId)) {
                return $officeId;
            }
        }

        throw new \RuntimeException("All Office ID sequences exhausted for city {$city} and code {$code}.");
    }

    /**
     * Derive a 2-letter uppercase agency code from the company name.
     * Uses the first 2 ASCII alpha characters found in the name.
     * Falls back to '1A' (Amadeus default) if fewer than 2 alpha chars exist.
     */
    public function agencyCode(string $companyName): string
    {
        // Strip non-ASCII, keep only letters
        $letters = preg_replace('/[^a-zA-Z]/', '', Str::ascii($companyName));

        if (strlen($letters) >= 2) {
            return strtoupper(substr($letters, 0, 2));
        }

        if (strlen($letters) === 1) {
            return strtoupper($letters[0]).'A';
        }

        return '1A';
    }

    private function exists(string $officeId): bool
    {
        return Tenant::where('office_id', $officeId)->exists()
            || DB::table('tenants')->where('id', $officeId)->exists();
    }
}
