<?php

namespace App\Support;

class AirlineLogoUrl
{
    public static function forCode(string $airlineCode, string $variant = 'icon-transparent', int $radius = 8): string
    {
        $tenantId = tenant('id');

        if ($tenantId === null || $airlineCode === '') {
            return '';
        }

        $code = strtoupper($airlineCode);
        $query = http_build_query([
            'variant' => $variant,
            'radius' => $radius,
        ]);

        return url("/agency/{$tenantId}/api/v1/airlines/{$code}/logo?{$query}");
    }
}
