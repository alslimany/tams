<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\Airline\FlightDurationCalculator;
use Illuminate\Console\Command;

class ImportAirportTimezonesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'airports:import-timezones
                            {--file= : Path to the IATA/timezone JSON file}
                            {--dry-run : Report counts without writing}';

    /**
     * @var string
     */
    protected $description = 'Import IANA airport timezones from JSON into the landlord airports table';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/airports_timezones.json');

        if (! is_readable($path)) {
            $this->error("Timezone file not found or not readable: {$path}");

            return self::FAILURE;
        }

        $entries = json_decode((string) file_get_contents($path), true);

        if (! is_array($entries)) {
            $this->error('Invalid JSON payload.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skippedNumeric = 0;
        $skippedInvalid = 0;
        $missingAirport = 0;

        foreach ($entries as $entry) {
            $iata = strtoupper(trim((string) ($entry['iata'] ?? '')));
            $timezone = trim((string) ($entry['timezone'] ?? ''));

            if ($iata === '' || $timezone === '') {
                $skippedInvalid++;

                continue;
            }

            if (! FlightDurationCalculator::isValidTimezone($timezone)) {
                $skippedNumeric++;

                continue;
            }

            $airport = Airport::query()->where('iata_code', $iata)->first();

            if ($airport === null) {
                $missingAirport++;

                continue;
            }

            if (! $dryRun) {
                $airport->update(['timezone' => $timezone]);
            }

            $updated++;
        }

        $this->info(sprintf(
            'Processed %d entries: %d updated, %d skipped (numeric/invalid timezone), %d skipped (airport not found).',
            count($entries),
            $updated,
            $skippedNumeric + $skippedInvalid,
            $missingAirport,
        ));

        if ($dryRun) {
            $this->warn('Dry run only — no rows were updated.');
        }

        return self::SUCCESS;
    }
}
