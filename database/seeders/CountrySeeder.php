<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $path = resource_path('data/countries.csv');

        if (! file_exists($path)) {
            $this->command->warn('countries.csv not found at '.$path);

            return;
        }

        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);

        $idIdx = array_search('id', $headers);
        $alpha2Idx = array_search('alpha2', $headers);
        $alpha3Idx = array_search('alpha3', $headers);
        $enIdx = array_search('en', $headers);
        $arIdx = array_search('ar', $headers);
        $frIdx = array_search('fr', $headers);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $id = (int) ($row[$idIdx] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'alpha2' => strtolower((string) ($row[$alpha2Idx] ?? '')),
                'alpha3' => strtolower((string) ($row[$alpha3Idx] ?? '')),
                'name_en' => (string) ($row[$enIdx] ?? ''),
                'name_ar' => (string) ($row[$arIdx] ?? '') ?: null,
                'name_fr' => (string) ($row[$frIdx] ?? '') ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        fclose($handle);

        foreach (array_chunk($rows, 50) as $chunk) {
            Country::upsert($chunk, ['id'], ['alpha2', 'alpha3', 'name_en', 'name_ar', 'name_fr']);
        }

        $this->command->info('Seeded '.count($rows).' countries.');
    }
}
