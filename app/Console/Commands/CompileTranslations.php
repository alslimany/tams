<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CompileTranslations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'translations:compile {--locale= : Compile only specific locale}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compile PHP language files into static JSON files for frontend consumption';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $specificLocale = $this->option('locale');

        $langPath = base_path('lang');
        if (! File::exists($langPath)) {
            $this->error('Language directory not found: '.$langPath);

            return 1;
        }

        $locales = File::directories($langPath);

        if (empty($locales)) {
            $this->error('No language directories found');

            return 1;
        }

        $this->info('Compiling translations...');

        foreach ($locales as $localePath) {
            $locale = basename($localePath);

            // Skip if specific locale is requested and doesn't match
            if ($specificLocale && $locale !== $specificLocale) {
                continue;
            }

            $this->info("Processing locale: {$locale}");

            $translations = $this->compileLocaleTranslations($localePath, $locale);

            if (! empty($translations)) {
                $this->saveLocaleJson($locale, $translations);
                $this->info("✓ Compiled {$locale} translations");
            } else {
                $this->warn("⚠ No translations found for {$locale}");
            }
        }

        $this->info('Translation compilation completed!');

        return 0;
    }

    /**
     * Compile all translation files for a specific locale
     */
    private function compileLocaleTranslations(string $localePath, string $locale): array
    {
        $translations = [];

        // Get all PHP files in the locale directory
        $files = File::allFiles($localePath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $namespace = $file->getFilenameWithoutExtension();
                $fileTranslations = require $file->getPathname();

                // Flatten nested arrays with dot notation
                $flattened = $this->flattenArray($fileTranslations, $namespace.'.');

                $translations = array_merge($translations, $flattened);
            }
        }

        return $translations;
    }

    /**
     * Flatten nested array with dot notation
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix.$key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey.'.'));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Save compiled translations as JSON file
     */
    private function saveLocaleJson(string $locale, array $translations): void
    {
        $outputPath = public_path("lang/{$locale}.json");

        // Ensure the directory exists
        File::ensureDirectoryExists(dirname($outputPath));

        // Save as formatted JSON
        File::put($outputPath, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Saved to: {$outputPath}");
    }
}
