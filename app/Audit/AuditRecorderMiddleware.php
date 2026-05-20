<?php

namespace App\Audit;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditRecorderMiddleware
{
    public function __construct(
        private readonly AccountingSnapshotService $snapshots,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('audit.enabled')) {
            return $next($request);
        }

        // Resume session from disk if not already active in this process
        if (! AuditSessionManager::active()) {
            AuditSessionManager::resume();
        }

        if (! AuditSessionManager::active()) {
            return $next($request);
        }

        if (! $this->shouldWatch($request)) {
            return $next($request);
        }

        // Snapshot before
        $snapshotBefore = $this->snapshots->capture('before');

        // Record incoming request
        AuditSessionManager::record('http_request', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'route_name' => $request->route()?->getName(),
            'is_inertia' => (bool) $request->header('X-Inertia'),
            'user_id' => auth()->id(),
            'tenant_id' => function_exists('tenant') ? tenant('id') : null,
            'payload' => $this->sanitisePayload($request->except(['_token', '_method'])),
        ]);

        AuditSessionManager::record('wallet_snapshot_before', $snapshotBefore);

        // Execute
        $response = $next($request);

        // Snapshot after
        $snapshotAfter = $this->snapshots->capture('after');

        // Record response
        AuditSessionManager::record('http_response', [
            'status' => $response->getStatusCode(),
            'is_inertia' => $response->headers->has('X-Inertia'),
            'page_props' => $this->extractInertiaProps($response),
        ]);

        AuditSessionManager::record('wallet_snapshot_after', array_merge(
            $snapshotAfter,
            ['diff' => $this->diffSnapshots($snapshotBefore, $snapshotAfter)],
        ));

        // Flush pending events to disk for this request
        AuditSessionManager::flush();

        return $response;
    }

    private function shouldWatch(Request $request): bool
    {
        foreach (config('audit.watch_routes', []) as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function sanitisePayload(array $payload): array
    {
        foreach (config('audit.redact_fields', []) as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = '[REDACTED]';
            }
        }

        return $payload;
    }

    private function extractInertiaProps(Response $response): array
    {
        try {
            $content = json_decode($response->getContent(), true);

            return $content['props'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function diffSnapshots(array $before, array $after): array
    {
        $diff = [];

        foreach ($after['wallets'] ?? [] as $key => $walletAfter) {
            $balanceBefore = $before['wallets'][$key]['balance'] ?? 0.0;
            $balanceAfter = $walletAfter['balance'] ?? 0.0;

            $diff[$key] = [
                'before' => $balanceBefore,
                'after' => $balanceAfter,
                'change' => round($balanceAfter - $balanceBefore, 3),
            ];
        }

        return $diff;
    }
}
