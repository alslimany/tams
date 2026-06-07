<?php

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

class AirportTranslationSeeder extends Seeder
{
    /**
     * Update airport name / city / country translations for the airports
     * we serve. Matched by IATA code; rows whose IATA code does not yet
     * exist in the airports table are skipped gracefully.
     */
    public function run(): void
    {
        $airports = [
            [
                'iata' => 'TOB',
                'name' => ['en' => 'Tobruk International Airport', 'ar' => 'مطار طبرق', 'fr' => 'Aéroport de Tobrouk'],
                'city' => ['en' => 'Tobruk', 'ar' => 'طبرق', 'fr' => 'Tobrouk'],
                'country' => ['en' => 'Libya', 'ar' => 'ليبيا', 'fr' => 'Libye'],
            ],
            [
                'iata' => 'LAQ',
                'name' => ['en' => 'Al Abraq International Airport', 'ar' => 'مطار الأبرق الدولي', 'fr' => "Aéroport international d'Al Abraq"],
                'city' => ['en' => 'Al Abraq', 'ar' => 'البيضاء', 'fr' => 'Bayda'],
                'country' => ['en' => 'Libya', 'ar' => 'ليبيا', 'fr' => 'Libye'],
            ],
            [
                'iata' => 'BEN',
                'name' => ['en' => 'Benina International Airport', 'ar' => 'مطار بنينا الدولي', 'fr' => 'Aéroport international de Benina'],
                'city' => ['en' => 'Benghazi', 'ar' => 'بنغازي', 'fr' => 'Benghazi'],
                'country' => ['en' => 'Libya', 'ar' => 'ليبيا', 'fr' => 'Libye'],
            ],
            [
                'iata' => 'GHT',
                'name' => ['en' => 'Ghat Airport', 'ar' => 'مطار غات', 'fr' => 'Aéroport de Ghat'],
                'city' => ['en' => 'Ghat', 'ar' => 'غات', 'fr' => 'Ghat'],
                'country' => ['en' => 'Libya', 'ar' => 'ليبيا', 'fr' => 'Libye'],
            ],
            [
                'iata' => 'AKF',
                'name' => ['en' => 'Kufra Airport', 'ar' => 'مطار الكفرة', 'fr' => 'Aéroport de Koufra'],
                'city' => ['en' => 'Kufra', 'ar' => 'الكفرة', 'fr' => 'Koufra'],
                'country' => ['en' => 'Libya', 'ar' => 'ليبيا', 'fr' => 'Libye'],
            ],
            [
                'iata' => 'SEB',
                'name' => ['en' => 'Sabha Airport', 'ar' => 'مطار سبها', 'fr' => 'Aéroport de Sebha'],
                'city' => ['en' => 'Sabha', 'ar' => 'سبها', 'fr' => 'Sebha'],
                'country' => ['en' => 'Libya', 'ar' => 'ليبيا', 'fr' => 'Libye'],
            ],
            [
                'iata' => 'MJI',
                'name' => ['en' => 'Mitiga International Airport', 'ar' => 'مطار معيتيقة الدولي', 'fr' => 'Aéroport international de Mitiga'],
                'city' => ['en' => 'Tripoli', 'ar' => 'طرابلس', 'fr' => 'Tripoli'],
                'country' => ['en' => 'Libya', 'ar' => 'ليبيا', 'fr' => 'Libye'],
            ],
            [
                'iata' => 'AUH',
                'name' => ['en' => 'Zayed International Airport', 'ar' => 'مطار زايد الدولي', 'fr' => 'Aéroport international Zayed'],
                'city' => ['en' => 'Abu Dhabi', 'ar' => 'أبوظبي', 'fr' => 'Abou Dabi'],
                'country' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة', 'fr' => 'Émirats arabes unis'],
            ],
            [
                'iata' => 'DXB',
                'name' => ['en' => 'Dubai International Airport', 'ar' => 'مطار دبي الدولي', 'fr' => 'Aéroport international de Dubaï'],
                'city' => ['en' => 'Dubai', 'ar' => 'دبي', 'fr' => 'Dubaï'],
                'country' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة', 'fr' => 'Émirats arabes unis'],
            ],
            [
                'iata' => 'DWC',
                'name' => ['en' => 'Al Maktoum International Airport', 'ar' => 'مطار آل مكتوم الدولي', 'fr' => 'Aéroport international Al Maktoum'],
                'city' => ['en' => 'Dubai', 'ar' => 'دبي', 'fr' => 'Dubaï'],
                'country' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة', 'fr' => 'Émirats arabes unis'],
            ],
            [
                'iata' => 'SHJ',
                'name' => ['en' => 'Sharjah International Airport', 'ar' => 'مطار الشارقة الدولي', 'fr' => 'Aéroport international de Charjah'],
                'city' => ['en' => 'Sharjah', 'ar' => 'الشارقة', 'fr' => 'Charjah'],
                'country' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة', 'fr' => 'Émirats arabes unis'],
            ],
            [
                'iata' => 'FRA',
                'name' => ['en' => 'Frankfurt Main Airport', 'ar' => 'مطار فرانكفورت', 'fr' => 'Aéroport de Francfort'],
                'city' => ['en' => 'Frankfurt am Main', 'ar' => 'فرانكفورت', 'fr' => 'Francfort'],
                'country' => ['en' => 'Germany', 'ar' => 'ألمانيا', 'fr' => 'Allemagne'],
            ],
            [
                'iata' => 'ALG',
                'name' => ['en' => 'Houari Boumediene Airport', 'ar' => 'مطار هواري بومدين الدولي', 'fr' => 'Aéroport international Houari-Boumédiène'],
                'city' => ['en' => 'Algiers', 'ar' => 'الجزائر', 'fr' => 'Alger'],
                'country' => ['en' => 'Algeria', 'ar' => 'الجزائر', 'fr' => 'Algérie'],
            ],
            [
                'iata' => 'HBE',
                'name' => ['en' => 'Alexandria International Airport', 'ar' => 'مطار برج العرب الدولي', 'fr' => 'Aéroport international de Borg El Arab'],
                'city' => ['en' => 'Alexandria', 'ar' => 'الإسكندرية', 'fr' => 'Alexandrie'],
                'country' => ['en' => 'Egypt', 'ar' => 'مصر', 'fr' => 'Égypte'],
            ],
            [
                'iata' => 'CAI',
                'name' => ['en' => 'Cairo International Airport', 'ar' => 'مطار القاهرة الدولي', 'fr' => 'Aéroport international du Caire'],
                'city' => ['en' => 'Cairo', 'ar' => 'القاهرة', 'fr' => 'Le Caire'],
                'country' => ['en' => 'Egypt', 'ar' => 'مصر', 'fr' => 'Égypte'],
            ],
            [
                'iata' => 'HRG',
                'name' => ['en' => 'Hurghada International Airport', 'ar' => 'مطار الغردقة الدولي', 'fr' => "Aéroport international d'Hurghada"],
                'city' => ['en' => 'Hurghada', 'ar' => 'الغردقة', 'fr' => 'Hurghada'],
                'country' => ['en' => 'Egypt', 'ar' => 'مصر', 'fr' => 'Égypte'],
            ],
            [
                'iata' => 'LXR',
                'name' => ['en' => 'Luxor International Airport', 'ar' => 'مطار الأقصر الدولي', 'fr' => 'Aéroport international de Louxor'],
                'city' => ['en' => 'Luxor', 'ar' => 'الأقصر', 'fr' => 'Louxor'],
                'country' => ['en' => 'Egypt', 'ar' => 'مصر', 'fr' => 'Égypte'],
            ],
            [
                'iata' => 'SSH',
                'name' => ['en' => 'Sharm El Sheikh International Airport', 'ar' => 'مطار شرم الشيخ الدولي', 'fr' => 'Aéroport international de Charm el-Cheikh'],
                'city' => ['en' => 'Sharm El Sheikh', 'ar' => 'شرم الشيخ', 'fr' => 'Charm el-Cheikh'],
                'country' => ['en' => 'Egypt', 'ar' => 'مصر', 'fr' => 'Égypte'],
            ],
            [
                'iata' => 'BCN',
                'name' => ['en' => 'Josep Tarradellas Barcelona-El Prat Airport', 'ar' => 'مطار برشلونة إل برات', 'fr' => 'Aéroport Josep Tarradellas Barcelone-El Prat'],
                'city' => ['en' => 'Barcelona', 'ar' => 'برشلونة', 'fr' => 'Barcelone'],
                'country' => ['en' => 'Spain', 'ar' => 'إسبانيا', 'fr' => 'Espagne'],
            ],
            [
                'iata' => 'MAD',
                'name' => ['en' => 'Adolfo Suárez Madrid–Barajas Airport', 'ar' => 'مطار مدريد باراخاس', 'fr' => 'Aéroport Adolfo Suárez Madrid-Barajas'],
                'city' => ['en' => 'Madrid', 'ar' => 'مدريد', 'fr' => 'Madrid'],
                'country' => ['en' => 'Spain', 'ar' => 'إسبانيا', 'fr' => 'Espagne'],
            ],
            [
                'iata' => 'ORY',
                'name' => ['en' => 'Paris-Orly Airport', 'ar' => 'مطار باريس أورلي', 'fr' => 'Aéroport de Paris-Orly'],
                'city' => ['en' => 'Paris', 'ar' => 'باريس', 'fr' => 'Paris'],
                'country' => ['en' => 'France', 'ar' => 'فرنسا', 'fr' => 'France'],
            ],
            [
                'iata' => 'CDG',
                'name' => ['en' => 'Charles de Gaulle International Airport', 'ar' => 'مطار باريس شارل ديغول', 'fr' => 'Aéroport Paris-Charles-de-Gaulle'],
                'city' => ['en' => 'Paris', 'ar' => 'باريس', 'fr' => 'Paris'],
                'country' => ['en' => 'France', 'ar' => 'فرنسا', 'fr' => 'France'],
            ],
            [
                'iata' => 'LGW',
                'name' => ['en' => 'London Gatwick Airport', 'ar' => 'مطار لندن غاتويك', 'fr' => 'Aéroport de Londres-Gatwick'],
                'city' => ['en' => 'London', 'ar' => 'لندن', 'fr' => 'Londres'],
                'country' => ['en' => 'United Kingdom', 'ar' => 'المملكة المتحدة', 'fr' => 'Royaume-Uni'],
            ],
            [
                'iata' => 'LHR',
                'name' => ['en' => 'London Heathrow Airport', 'ar' => 'مطار لندن هيثرو', 'fr' => 'Aéroport de Londres-Heathrow'],
                'city' => ['en' => 'London', 'ar' => 'لندن', 'fr' => 'Londres'],
                'country' => ['en' => 'United Kingdom', 'ar' => 'المملكة المتحدة', 'fr' => 'Royaume-Uni'],
            ],
            [
                'iata' => 'MXP',
                'name' => ['en' => 'Milan Malpensa International Airport', 'ar' => 'مطار ميلانو مالبينسا', 'fr' => 'Aéroport de Milan-Malpensa'],
                'city' => ['en' => 'Milan', 'ar' => 'ميلانو', 'fr' => 'Milan'],
                'country' => ['en' => 'Italy', 'ar' => 'إيطاليا', 'fr' => 'Italie'],
            ],
            [
                'iata' => 'FCO',
                'name' => ['en' => 'Rome–Fiumicino Leonardo da Vinci International Airport', 'ar' => 'مطار روما فيوميتشينو', 'fr' => 'Aéroport Léonard-de-Vinci de Rome Fiumicino'],
                'city' => ['en' => 'Rome', 'ar' => 'روما', 'fr' => 'Rome'],
                'country' => ['en' => 'Italy', 'ar' => 'إيطاليا', 'fr' => 'Italie'],
            ],
            [
                'iata' => 'AMM',
                'name' => ['en' => 'Queen Alia International Airport', 'ar' => 'مطار الملكة علياء الدولي', 'fr' => 'Aéroport international Reine-Alia'],
                'city' => ['en' => 'Amman', 'ar' => 'عمّان', 'fr' => 'Amman'],
                'country' => ['en' => 'Jordan', 'ar' => 'الأردن', 'fr' => 'Jordanie'],
            ],
            [
                'iata' => 'CMN',
                'name' => ['en' => 'Mohammed V International Airport', 'ar' => 'مطار محمد الخامس الدولي', 'fr' => 'Aéroport international Mohammed-V'],
                'city' => ['en' => 'Casablanca', 'ar' => 'الدار البيضاء', 'fr' => 'Casablanca'],
                'country' => ['en' => 'Morocco', 'ar' => 'المغرب', 'fr' => 'Maroc'],
            ],
            [
                'iata' => 'AMS',
                'name' => ['en' => 'Amsterdam Airport Schiphol', 'ar' => 'مطار أمستردام سخيبول', 'fr' => "Aéroport d'Amsterdam-Schiphol"],
                'city' => ['en' => 'Amsterdam', 'ar' => 'أمستردام', 'fr' => 'Amsterdam'],
                'country' => ['en' => 'Netherlands', 'ar' => 'هولندا', 'fr' => 'Pays-Bas'],
            ],
            [
                'iata' => 'DOH',
                'name' => ['en' => 'Hamad International Airport', 'ar' => 'مطار حمد الدولي', 'fr' => 'Aéroport international Hamad'],
                'city' => ['en' => 'Doha', 'ar' => 'الدوحة', 'fr' => 'Doha'],
                'country' => ['en' => 'Qatar', 'ar' => 'قطر', 'fr' => 'Qatar'],
            ],
            [
                'iata' => 'DMM',
                'name' => ['en' => 'King Fahd International Airport', 'ar' => 'مطار الملك فهد الدولي', 'fr' => 'Aéroport international Roi-Fahd'],
                'city' => ['en' => 'Dammam', 'ar' => 'الدمام', 'fr' => 'Dammam'],
                'country' => ['en' => 'Saudi Arabia', 'ar' => 'المملكة العربية السعودية', 'fr' => 'Arabie saoudite'],
            ],
            [
                'iata' => 'JED',
                'name' => ['en' => 'King Abdulaziz International Airport', 'ar' => 'مطار الملك عبدالعزيز الدولي', 'fr' => 'Aéroport international Roi-Abdelaziz'],
                'city' => ['en' => 'Jeddah', 'ar' => 'جدة', 'fr' => 'Djeddah'],
                'country' => ['en' => 'Saudi Arabia', 'ar' => 'المملكة العربية السعودية', 'fr' => 'Arabie saoudite'],
            ],
            [
                'iata' => 'MED',
                'name' => ['en' => 'Prince Mohammad Bin Abdulaziz Airport', 'ar' => 'مطار الأمير محمد بن عبدالعزيز الدولي', 'fr' => 'Aéroport international Prince Mohammad bin Abdulaziz'],
                'city' => ['en' => 'Medina', 'ar' => 'المدينة المنورة', 'fr' => 'Médine'],
                'country' => ['en' => 'Saudi Arabia', 'ar' => 'المملكة العربية السعودية', 'fr' => 'Arabie saoudite'],
            ],
            [
                'iata' => 'RUH',
                'name' => ['en' => 'King Khalid International Airport', 'ar' => 'مطار الملك خالد الدولي', 'fr' => 'Aéroport international Roi-Khaled'],
                'city' => ['en' => 'Riyadh', 'ar' => 'الرياض', 'fr' => 'Riyad'],
                'country' => ['en' => 'Saudi Arabia', 'ar' => 'المملكة العربية السعودية', 'fr' => 'Arabie saoudite'],
            ],
            [
                'iata' => 'NBE',
                'name' => ['en' => 'Enfidha - Hammamet International Airport', 'ar' => 'مطار النفيضة الحمامات الدولي', 'fr' => "Aéroport international d'Enfidha-Hammamet"],
                'city' => ['en' => 'Enfidha', 'ar' => 'النفيضة', 'fr' => 'Enfidha'],
                'country' => ['en' => 'Tunisia', 'ar' => 'تونس', 'fr' => 'Tunisie'],
            ],
            [
                'iata' => 'DJE',
                'name' => ['en' => 'Djerba Zarzis International Airport', 'ar' => 'مطار جربة جرجيس الدولي', 'fr' => 'Aéroport international de Djerba-Zarzis'],
                'city' => ['en' => 'Djerba', 'ar' => 'جربة', 'fr' => 'Djerba'],
                'country' => ['en' => 'Tunisia', 'ar' => 'تونس', 'fr' => 'Tunisie'],
            ],
            [
                'iata' => 'SFA',
                'name' => ['en' => 'Sfax Thyna International Airport', 'ar' => 'مطار صفاقس طينة الدولي', 'fr' => 'Aéroport international de Sfax-Thyna'],
                'city' => ['en' => 'Sfax', 'ar' => 'صفاقس', 'fr' => 'Sfax'],
                'country' => ['en' => 'Tunisia', 'ar' => 'تونس', 'fr' => 'Tunisie'],
            ],
            [
                'iata' => 'TUN',
                'name' => ['en' => 'Tunis Carthage International Airport', 'ar' => 'مطار تونس قرطاج الدولي', 'fr' => 'Aéroport international de Tunis-Carthage'],
                'city' => ['en' => 'Tunis', 'ar' => 'تونس', 'fr' => 'Tunis'],
                'country' => ['en' => 'Tunisia', 'ar' => 'تونس', 'fr' => 'Tunisie'],
            ],
            [
                'iata' => 'ESB',
                'name' => ['en' => 'Esenboğa International Airport', 'ar' => 'مطار إيسنبوغا الدولي', 'fr' => "Aéroport international d'Esenboğa"],
                'city' => ['en' => 'Ankara', 'ar' => 'أنقرة', 'fr' => 'Ankara'],
                'country' => ['en' => 'Turkey', 'ar' => 'تركيا', 'fr' => 'Turquie'],
            ],
            [
                'iata' => 'AYT',
                'name' => ['en' => 'Antalya International Airport', 'ar' => 'مطار أنطاليا', 'fr' => "Aéroport d'Antalya"],
                'city' => ['en' => 'Antalya', 'ar' => 'أنطاليا', 'fr' => 'Antalya'],
                'country' => ['en' => 'Turkey', 'ar' => 'تركيا', 'fr' => 'Turquie'],
            ],
            [
                'iata' => 'ADB',
                'name' => ['en' => 'Adnan Menderes International Airport', 'ar' => 'مطار عدنان مندريس', 'fr' => 'Aéroport Adnan-Menderes'],
                'city' => ['en' => 'Izmir', 'ar' => 'إزمير', 'fr' => 'Izmir'],
                'country' => ['en' => 'Turkey', 'ar' => 'تركيا', 'fr' => 'Turquie'],
            ],
            [
                'iata' => 'IST',
                'name' => ['en' => 'İstanbul Airport', 'ar' => 'مطار إسطنبول', 'fr' => "Aéroport d'Istanbul"],
                'city' => ['en' => 'Istanbul', 'ar' => 'إسطنبول', 'fr' => 'Istanbul'],
                'country' => ['en' => 'Turkey', 'ar' => 'تركيا', 'fr' => 'Turquie'],
            ],
            [
                'iata' => 'SAW',
                'name' => ['en' => 'Istanbul Sabiha Gökçen International Airport', 'ar' => 'مطار صبيحة كوكجن الدولي', 'fr' => 'Aéroport international Sabiha Gökçen'],
                'city' => ['en' => 'Istanbul', 'ar' => 'إسطنبول', 'fr' => 'Istanbul'],
                'country' => ['en' => 'Turkey', 'ar' => 'تركيا', 'fr' => 'Turquie'],
            ],
            [
                'iata' => 'JFK',
                'name' => ['en' => 'John F. Kennedy International Airport', 'ar' => 'مطار جون إف كينيدي الدولي', 'fr' => 'Aéroport international John-F.-Kennedy'],
                'city' => ['en' => 'New York', 'ar' => 'نيويورك', 'fr' => 'New York'],
                'country' => ['en' => 'United States', 'ar' => 'الولايات المتحدة', 'fr' => 'États-Unis'],
            ],
        ];

        $updated = 0;
        $skipped = 0;

        foreach ($airports as $data) {
            $airport = Airport::query()->where('iata_code', $data['iata'])->first();

            if (! $airport) {
                $skipped++;

                continue;
            }

            $airport->update([
                'name' => $data['name'],
                'city' => $data['city'],
                'country' => $data['country'],
            ]);

            $updated++;
        }

        $this->command->info("Airport translations: {$updated} updated, {$skipped} skipped (IATA not found).");
    }
}
