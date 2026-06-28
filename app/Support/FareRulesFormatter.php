<?php

namespace App\Support;

class FareRulesFormatter
{
    /**
     * Strip Videcom line numbering and return plain fare-rule text.
     */
    public static function toPlainText(string $rules): string
    {
        $lines = preg_split('/\R/u', $rules) ?: [];

        $cleaned = array_map(function (string $line): string {
            $line = preg_replace('/^\s*\d+[\.\):\-]+\s*/u', '', $line) ?? $line;
            $line = preg_replace('/^\s*\d+\s+(?=[A-Za-z*])/u', '', $line) ?? $line;

            return rtrim($line);
        }, $lines);

        return trim(implode("\n", $cleaned));
    }
}
