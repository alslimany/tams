<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Airport;

class ImportAirportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-airports';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import airports from CSV file into the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $csvPath = base_path('.trae/docs/airports.csv');
        if (!file_exists($csvPath)) {
            $this->error("File not found at: {$csvPath}");
            return;
        }

        $this->info("Importing airports from CSV...");

        $file = fopen($csvPath, 'r');
        $header = fgetcsv($file); 

        $airportsToInsert = [];
        $count = 0;
        
        while (($row = fgetcsv($file)) !== false) {
            $data = array_combine($header, $row);

            if ($data['type'] === 'closed') {
                continue;
            }

            $airportsToInsert[] = [
                'name' => json_encode(['en' => $data['name']]),
                'city' => json_encode(['en' => $data['municipality'] ?: 'Unknown']),
                'country' => json_encode(['en' => $data['iso_country']]),
                'iata_code' => $data['iata_code'] ?: null,
                'icao_code' => $data['icao_code'] ?: null,
                'latitude' => $data['latitude_deg'] ? (float) $data['latitude_deg'] : null,
                'longitude' => $data['longitude_deg'] ? (float) $data['longitude_deg'] : null,
                'elevation_ft' => $data['elevation_ft'] ? (int) $data['elevation_ft'] : null,
                'type' => $data['type'],
                'data' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($airportsToInsert) >= 1000) {
                DB::table('airports')->insert($airportsToInsert);
                $count += count($airportsToInsert);
                $this->info("Inserted {$count} CSV rows...");
                $airportsToInsert = [];
            }
        }

        if (count($airportsToInsert) > 0) {
            DB::table('airports')->insert($airportsToInsert);
            $count += count($airportsToInsert);
        }

        fclose($file);

        $this->info("Successfully imported {$count} airports!");
    }
}
