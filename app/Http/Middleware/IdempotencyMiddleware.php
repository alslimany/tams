<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Idempotency middleware for mutating API endpoints.
 *
 * Clients send an `Idempotency-Key` header (UUID or any unique string up to 128 chars).
 * The first response is cached for 24 hours keyed by (tenant + user + idempotency key).
 * Subsequent requests with the same key return the cached response immediately.
 *
 * If no header is present the request proceeds normally (idempotency is optional).
 */
class IdempotencyMiddleware
{
    public const HEADER = 'Idempotency-Key';

    public const TTL_SECONDS = 86400; // 24 hours

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $key = $request->header(self::HEADER);

        if (blank($key) || strlen($key) > 128) {
            return $next($request);
        }

        $cacheKey = $this->cacheKey($request, $key);

        if ($cached = Cache::get($cacheKey)) {
            return response()->json(
                $cached['body'],
                $cached['status'],
                array_merge($cached['headers'], ['X-Idempotency-Replayed' => 'true']),
            );
        }

        /** @var SymfonyResponse $response */
        $response = $next($request);

        // Only cache successful responses (2xx)
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true),
                'headers' => [],
            ], self::TTL_SECONDS);
        }

        return $response;
    }

    private function cacheKey(Request $request, string $idempotencyKey): string
    {
        $tenantId = tenant('id') ?? 'central';
        $userId = $request->user()?->id ?? 'guest';

        return "idempotency:{$tenantId}:{$userId}:{$idempotencyKey}";
    }
}
