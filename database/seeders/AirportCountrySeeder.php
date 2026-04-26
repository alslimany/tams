<?php

namespace Database\Seeders;

use App\Models\AirportCountry;
use Illuminate\Database\Seeder;

class AirportCountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Populates airport_countries with countries relevant to the
     * operated airlines (Libya-based carriers serving North Africa,
     * Middle East, and Europe).
     */
    public function run(): void
    {
        $countries = [
            ['country_code' => 'LY', 'country_name' => 'Libya', 'iso3_code' => 'LBY', 'is_active' => true],
            ['country_code' => 'TR', 'country_name' => 'Turkey', 'iso3_code' => 'TUR', 'is_active' => true],
            ['country_code' => 'IT', 'country_name' => 'Italy', 'iso3_code' => 'ITA', 'is_active' => true],
            ['country_code' => 'MT', 'country_name' => 'Malta', 'iso3_code' => 'MLT', 'is_active' => true],
            ['country_code' => 'TN', 'country_name' => 'Tunisia', 'iso3_code' => 'TUN', 'is_active' => true],
            ['country_code' => 'EG', 'country_name' => 'Egypt', 'iso3_code' => 'EGY', 'is_active' => true],
            ['country_code' => 'DZ', 'country_name' => 'Algeria', 'iso3_code' => 'DZA', 'is_active' => true],
            ['country_code' => 'MA', 'country_name' => 'Morocco', 'iso3_code' => 'MAR', 'is_active' => true],
            ['country_code' => 'SD', 'country_name' => 'Sudan', 'iso3_code' => 'SDN', 'is_active' => true],
            ['country_code' => 'SA', 'country_name' => 'Saudi Arabia', 'iso3_code' => 'SAU', 'is_active' => true],
            ['country_code' => 'AE', 'country_name' => 'United Arab Emirates', 'iso3_code' => 'ARE', 'is_active' => true],
            ['country_code' => 'JO', 'country_name' => 'Jordan', 'iso3_code' => 'JOR', 'is_active' => true],
            ['country_code' => 'IQ', 'country_name' => 'Iraq', 'iso3_code' => 'IRQ', 'is_active' => true],
            ['country_code' => 'KW', 'country_name' => 'Kuwait', 'iso3_code' => 'KWT', 'is_active' => true],
            ['country_code' => 'QA', 'country_name' => 'Qatar', 'iso3_code' => 'QAT', 'is_active' => true],
            ['country_code' => 'BH', 'country_name' => 'Bahrain', 'iso3_code' => 'BHR', 'is_active' => true],
            ['country_code' => 'OM', 'country_name' => 'Oman', 'iso3_code' => 'OMN', 'is_active' => true],
            ['country_code' => 'GB', 'country_name' => 'United Kingdom', 'iso3_code' => 'GBR', 'is_active' => true],
            ['country_code' => 'DE', 'country_name' => 'Germany', 'iso3_code' => 'DEU', 'is_active' => true],
            ['country_code' => 'FR', 'country_name' => 'France', 'iso3_code' => 'FRA', 'is_active' => true],
            ['country_code' => 'NL', 'country_name' => 'Netherlands', 'iso3_code' => 'NLD', 'is_active' => true],
            ['country_code' => 'ES', 'country_name' => 'Spain', 'iso3_code' => 'ESP', 'is_active' => true],
            ['country_code' => 'GR', 'country_name' => 'Greece', 'iso3_code' => 'GRC', 'is_active' => true],
            ['country_code' => 'CY', 'country_name' => 'Cyprus', 'iso3_code' => 'CYP', 'is_active' => true],
            ['country_code' => 'NE', 'country_name' => 'Niger', 'iso3_code' => 'NER', 'is_active' => true],
            ['country_code' => 'TD', 'country_name' => 'Chad', 'iso3_code' => 'TCD', 'is_active' => true],
            ['country_code' => 'ML', 'country_name' => 'Mali', 'iso3_code' => 'MLI', 'is_active' => true],
            ['country_code' => 'SN', 'country_name' => 'Senegal', 'iso3_code' => 'SEN', 'is_active' => true],
            ['country_code' => 'NG', 'country_name' => 'Nigeria', 'iso3_code' => 'NGA', 'is_active' => true],
            ['country_code' => 'KE', 'country_name' => 'Kenya', 'iso3_code' => 'KEN', 'is_active' => true],
        ];

        foreach ($countries as $country) {
            AirportCountry::query()->updateOrCreate(
                ['country_code' => $country['country_code']],
                $country,
            );
        }

        $this->command->info('Seeded '.count($countries).' airport countries.');
    }
}
