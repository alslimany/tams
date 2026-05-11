<?php

use Illuminate\Support\Str;

test('compound migration indexes use MySQL safe explicit names', function () {
    $violations = collect(glob(database_path('migrations').'/**/*.php'))
        ->merge(glob(database_path('migrations').'/*.php'))
        ->filter()
        ->flatMap(function (string $migrationPath): array {
            $contents = file_get_contents($migrationPath);

            if ($contents === false) {
                return [];
            }

            preg_match_all("/Schema::(?:create|table)\('(?<table>[^']+)'[\s\S]*?\}\);/", $contents, $schemaBlocks, PREG_SET_ORDER);

            return collect($schemaBlocks)
                ->flatMap(function (array $schemaBlock) use ($migrationPath): array {
                    preg_match_all("/->(?<type>index|unique)\(\[(?<columns>[^\]]+)]\)(?!\s*,)/", $schemaBlock[0], $indexes, PREG_SET_ORDER);

                    return collect($indexes)
                        ->map(function (array $index) use ($migrationPath, $schemaBlock): ?string {
                            $columns = collect(explode(',', $index['columns']))
                                ->map(fn (string $column): string => trim($column, " \t\n\r\0\x0B'\""))
                                ->filter()
                                ->all();

                            $generatedName = $schemaBlock['table'].'_'.implode('_', $columns).'_'.$index['type'];

                            if (strlen($generatedName) <= 64) {
                                return null;
                            }

                            return Str::after($migrationPath, base_path().DIRECTORY_SEPARATOR).': '.$generatedName;
                        })
                        ->filter()
                        ->all();
                })
                ->all();
        })
        ->values()
        ->all();

    expect($violations)->toBeEmpty();
});

test('compound migration indexes stay within MySQL key length limits', function () {
    $violations = collect(glob(database_path('migrations').'/**/*.php'))
        ->merge(glob(database_path('migrations').'/*.php'))
        ->filter()
        ->flatMap(function (string $migrationPath): array {
            $contents = file_get_contents($migrationPath);

            if ($contents === false) {
                return [];
            }

            preg_match_all("/Schema::(?:create|table)\('(?<table>[^']+)'[\s\S]*?\}\);/", $contents, $schemaBlocks, PREG_SET_ORDER);

            return collect($schemaBlocks)
                ->flatMap(function (array $schemaBlock) use ($migrationPath): array {
                    $stringLengths = collect();

                    preg_match_all("/->string\('(?<column>[^']+)'(?:,\s*(?<length>\d+))?\)/", $schemaBlock[0], $strings, PREG_SET_ORDER);

                    foreach ($strings as $string) {
                        $stringLengths->put($string['column'], (int) ($string['length'] ?: 255));
                    }

                    preg_match_all("/->(?<type>index|unique)\(\[(?<columns>[^\]]+)](?:\s*,\s*'[^']+')?\)/", $schemaBlock[0], $indexes, PREG_SET_ORDER);

                    return collect($indexes)
                        ->map(function (array $index) use ($migrationPath, $schemaBlock, $stringLengths): ?string {
                            $columns = collect(explode(',', $index['columns']))
                                ->map(fn (string $column): string => trim($column, " \t\n\r\0\x0B'\""))
                                ->filter()
                                ->all();

                            $estimatedBytes = collect($columns)
                                ->sum(fn (string $column): int => ((int) $stringLengths->get($column, 0)) * 4);

                            if ($estimatedBytes <= 3072) {
                                return null;
                            }

                            return Str::after($migrationPath, base_path().DIRECTORY_SEPARATOR).': '.$schemaBlock['table'].' ['.implode(', ', $columns).'] uses '.$estimatedBytes.' estimated bytes';
                        })
                        ->filter()
                        ->all();
                })
                ->all();
        })
        ->values()
        ->all();

    expect($violations)->toBeEmpty();
});
