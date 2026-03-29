<?php

namespace App\Services\Airline\Videcom;

use App\DTOs\Airline\FlightOption;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VidecomScraper
{
    protected string $baseUrl;

    protected string $airlineCode;

    public function __construct(string $airlineCode, ?string $baseUrl = null)
    {
        $this->airlineCode = $airlineCode;
        $this->baseUrl = $baseUrl ?? "https://customer3.videcom.com/{$airlineCode}/VARS/Public/CustomerPanels/requirementsBS.aspx";
    }

    /**
     * Search for flights using the web scraper path.
     *
     * @param  array  $params  [origin, destination, date, qty, is_return]
     * @return array<FlightOption>
     */
    public function search(array $params): array
    {
        try {
            // 1. Get initial page to extract ViewState/EventValidation
            $initialResponse = Http::get($this->baseUrl);
            if ($initialResponse->failed()) {
                throw new Exception('Failed to fetch initial scraper page.');
            }

            $crawler = new Crawler($initialResponse->body());
            $viewState = $crawler->filter('#__VIEWSTATE')->attr('value');
            $eventValidation = $crawler->filter('#__EVENTVALIDATION')->attr('value');

            // 2. Perform the search post
            // Note: Field names often vary by Videcom implementation,
            // these are standard VRS panel names.
            $searchData = [
                '__VIEWSTATE' => $viewState,
                '__EVENTVALIDATION' => $eventValidation,
                'ctl00$ContentPlaceHolder1$txtDepCity' => $params['origin'],
                'ctl00$ContentPlaceHolder1$txtArrCity' => $params['destination'],
                'ctl00$ContentPlaceHolder1$txtDepDate' => $params['date'], // Expects DD/MM/YYYY or similar
                'ctl00$ContentPlaceHolder1$ddlAdults' => $params['qty'] ?? 1,
                'ctl00$ContentPlaceHolder1$btnSearch' => 'Search',
            ];

            $searchResponse = Http::asForm()->post($this->baseUrl, $searchData);

            if ($searchResponse->failed()) {
                throw new Exception('Scraper search request failed.');
            }

            return $this->parseResults($searchResponse->body());
        } catch (Exception $e) {
            Log::error('Videcom Scraper Error: '.$e->getMessage(), [
                'airline' => $this->airlineCode,
                'params' => $params,
            ]);

            return [];
        }
    }

    /**
     * Parse the search results HTML into FlightOption DTOs.
     */
    protected function parseResults(string $html): array
    {
        $options = [];
        $crawler = new Crawler($html);

        // This selector depends heavily on the specific airline's panel layout.
        // Usually, results are in a table or a series of divs with classes like 'flight-row'
        $crawler->filter('.flight-row, .itinerary-row, tr.flight').each(function (Crawler $node) use (&$options) {
            try {
                // Extract data using common VRS selectors
                // This is a placeholder structure until actual HTML is inspected
                $flightNo = $node->filter('.flight-number')->text('');
                $depTime = $node->filter('.departure-time')->text('');
                $arrTime = $node->filter('.arrival-time')->text('');
                $price = (float) preg_replace('/[^0-9.]/', '', $node->filter('.price-total, .fare-amount')->text('0'));

                if ($flightNo) {
                    $options[] = new FlightOption(
                        id: 'scraped-'.$flightNo.'-'.uniqid(),
                        airline_code: $this->airlineCode,
                        airline_name: $this->airlineCode, // Will be enriched by service
                        flight_number: $flightNo,
                        departure_airport: '', // To be parsed from context
                        arrival_airport: '',
                        departure_time: $depTime,
                        arrival_time: $arrTime,
                        segments: [],
                        pricing: [
                            'currency' => 'LYD',
                            'total' => $price,
                        ],
                        available_seats: 9
                    );
                }
            } catch (Exception $e) {
                // Skip invalid rows
            }
        });

        return $options;
    }
}
