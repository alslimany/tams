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
