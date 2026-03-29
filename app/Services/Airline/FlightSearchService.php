<?php

namespace App\Services\Airline;

use App\Models\TenantProvider;
use App\Services\Airline\Videcom\VidecomScraper;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FlightSearchService
{
    /**
     * Search flights across all enabled providers for the current tenant.
     *
     * @param  array  $params  [origin, destination, date, qty, is_return]
     */
    public function search(array $params): Collection
    {
        $results = collect();
        $providers = TenantProvider::where('is_active', true)->get();

        foreach ($providers as $providerConfig) {
            try {
                // 1. Authenticated Command Path (Transactional)
                $provider = ProviderFactory::make($providerConfig);
                $commandResults = $provider->searchAvailability($params);
                $results = $results->merge($commandResults);

                // 2. Web Scraper Path (Discovery - Optional/Background)
                if ($providerConfig->provider_type === 'videcom') {
                    $scraper = new VidecomScraper($providerConfig->airline_code, $providerConfig->credentials['base_url'] ?? null);
                    $scrapedResults = $scraper->search($params);

                    // Merge and de-duplicate (prefer command results for accuracy)
                    foreach ($scrapedResults as $scraped) {
                        if (! $this->isDuplicate($results, $scraped)) {
                            $results->push($scraped);
                        }
                    }
                }
            } catch (Exception $e) {
                Log::error("Search failed for provider {$providerConfig->airline_name}: ".$e->getMessage());
            }
        }

        return $results->sortBy('pricing.total');
    }

    /**
     * Simple de-duplication logic.
     */
    protected function isDuplicate(Collection $existing, $new): bool
    {
        return $existing->contains(function ($item) use ($new) {
            return $item->flight_number === $new->flight_number &&
                   $item->departure_time === $new->departure_time;
        });
    }
}
