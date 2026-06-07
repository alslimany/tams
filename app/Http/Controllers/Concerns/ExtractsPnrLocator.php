<?php

namespace App\Http\Controllers\Concerns;

use SimpleXMLElement;

trait ExtractsPnrLocator
{
    /**
     * Extract a PNR/RLOC locator from a Videcom booking response.
     *
     * Handles SimpleXMLElement (session mode), raw XML strings, and legacy
     * array responses that carry 'pnr', 'booking_reference', or 'rloc' keys.
     */
    protected function extractPnrLocator(mixed $bookingResponse): ?string
    {
        if ($bookingResponse instanceof SimpleXMLElement) {
            $attributeLocator = strtoupper(trim((string) ($bookingResponse['RLOC'] ?? '')));
            if ($attributeLocator !== '') {
                return $attributeLocator;
            }

            $directLocator = strtoupper(trim((string) ($bookingResponse->Locator ?? $bookingResponse->RecordLocator ?? $bookingResponse->PNR ?? '')));
            if ($directLocator !== '') {
                return $directLocator;
            }

            return $this->extractPnrLocatorFromString($bookingResponse->asXML() ?: '');
        }

        if (is_string($bookingResponse)) {
            return $this->extractPnrLocatorFromString($bookingResponse);
        }

        if (is_array($bookingResponse)) {
            $locator = $bookingResponse['pnr'] ?? $bookingResponse['booking_reference'] ?? $bookingResponse['rloc'] ?? null;

            return $locator ? strtoupper(trim((string) $locator)) ?: null : null;
        }

        return null;
    }

    protected function extractPnrLocatorFromString(string $xml): ?string
    {
        if (preg_match('/\bRLOC="([A-Z0-9]{5,8})"/i', $xml, $m) === 1) {
            return strtoupper($m[1]);
        }

        if (preg_match('/<Locator>([A-Z0-9]{5,8})<\/Locator>/i', $xml, $m) === 1) {
            return strtoupper($m[1]);
        }

        if (preg_match('/<RecordLocator>([A-Z0-9]{5,8})<\/RecordLocator>/i', $xml, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
