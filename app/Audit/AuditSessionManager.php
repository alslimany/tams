<?php

namespace App\Audit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Manages audit sessions with cross-process persistence.
 *
 * Sessions are stored as JSON files on disk so they survive across
 * separate Artisan/HTTP processes. An `.active` marker file holds the
 * current session ID so the middleware can resume it automatically.
 *
 * Lifecycle:
 *   audit:start  → start() → writes stub file + .active marker
 *   HTTP request → resume() → appends events via flush()
 *   audit:stop   → end()   → writes session_end event, removes .active marker
 */
class AuditSessionManager
{
    private static ?string $sessionId = null;

    private static array $pendingEvents = [];

    private static float $startTime = 0.0;

    // ─── Start / Resume / End ─────────────────────────────────────────────────

    /**
     * Start a brand-new session. Writes a stub file and .active marker immediately.
     */
    public static function start(string $label = 'unnamed'): string
    {
        self::$sessionId = (string) Str::uuid();
        self::$startTime = microtime(true);
        self::$pendingEvents = [];

        $startEvent = [
            'seq' => 1,
            'event_type' => 'session_start',
            'timestamp' => now()->toIso8601String(),
            'elapsed_ms' => 0.0,
            'data' => [
                'label' => $label,
                'started_at' => now()->toIso8601String(),
                'tenant_id' => function_exists('tenant') ? tenant('id') : null,
                'php_version' => PHP_VERSION,
                'app_env' => app()->environment(),
            ],
        ];

        self::$pendingEvents[] = $startEvent;

        // Write stub file immediately so the session ID is persisted
        self::writeFile([self::$sessionId, [self::$sessionId => $startEvent]]);

        // Write .active marker so middleware can resume this session
        self::writeActiveMarker(self::$sessionId);

        return self::$sessionId;
    }

    /**
     * Resume an existing session from disk (used by middleware in HTTP processes).
     * Returns true if a session was successfully resumed.
     */
    public static function resume(): bool
    {
        if (self::$sessionId) {
            return true; // Already active in this process
        }

        $sessionId = self::readActiveMarker();

        if (! $sessionId) {
            return false;
        }

        $path = self::sessionPath($sessionId);

        if (! File::exists($path)) {
            // Stale marker — clean it up
            self::removeActiveMarker();

            return false;
        }

        self::$sessionId = $sessionId;
        self::$startTime = microtime(true);
        self::$pendingEvents = [];

        return true;
    }

    /**
     * Finalise the session: write session_end event, remove .active marker.
     */
    public static function end(): void
    {
        if (! self::$sessionId) {
            return;
        }

        self::record('session_end', [
            'ended_at' => now()->toIso8601String(),
        ]);

        self::flush();
        self::removeActiveMarker();
        self::$sessionId = null;
        self::$pendingEvents = [];
        self::$startTime = 0.0;
    }

    // ─── Event Recording ──────────────────────────────────────────────────────

    public static function record(string $eventType, array $data): void
    {
        if (! self::$sessionId) {
            return;
        }

        self::$pendingEvents[] = [
            'event_type' => $eventType,
            'timestamp' => now()->toIso8601String(),
            'elapsed_ms' => round((microtime(true) - self::$startTime) * 1000, 2),
            'data' => $data,
        ];
    }

    /**
     * Flush pending in-memory events to the session file on disk.
     * Reads the existing file, appends new events with correct seq numbers, writes back.
     */
    public static function flush(): void
    {
        if (! self::$sessionId || empty(self::$pendingEvents)) {
            return;
        }

        $path = self::sessionPath(self::$sessionId);

        File::ensureDirectoryExists(config('audit.log_path'));

        // Load existing events from disk
        $existing = [];
        if (File::exists($path)) {
            try {
                $decoded = json_decode(File::get($path), true);
                $existing = $decoded['events'] ?? [];
            } catch (\Throwable) {
                $existing = [];
            }
        }

        // Assign sequential numbers continuing from existing
        $nextSeq = count($existing) + 1;
        $newEvents = array_map(function (array $event) use (&$nextSeq) {
            $event['seq'] = $nextSeq++;

            return $event;
        }, self::$pendingEvents);

        $allEvents = array_merge($existing, $newEvents);

        File::put($path, json_encode([
            'session_id' => self::$sessionId,
            'events' => $allEvents,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        self::$pendingEvents = [];
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    public static function sessionId(): ?string
    {
        return self::$sessionId;
    }

    public static function active(): bool
    {
        return self::$sessionId !== null;
    }

    public static function activeOnDisk(): bool
    {
        return self::readActiveMarker() !== null;
    }

    public static function pendingEvents(): array
    {
        return self::$pendingEvents;
    }

    /**
     * Reset in-memory state only (used in tests).
     */
    public static function reset(): void
    {
        self::$sessionId = null;
        self::$pendingEvents = [];
        self::$startTime = 0.0;
    }

    // ─── Internal Helpers ─────────────────────────────────────────────────────

    public static function sessionPath(string $sessionId): string
    {
        return config('audit.log_path').'/session_'.$sessionId.'.json';
    }

    private static function activeMarkerPath(): string
    {
        return config('audit.log_path').'/.active';
    }

    private static function writeActiveMarker(string $sessionId): void
    {
        File::ensureDirectoryExists(config('audit.log_path'));
        File::put(self::activeMarkerPath(), $sessionId);
    }

    private static function readActiveMarker(): ?string
    {
        $path = self::activeMarkerPath();

        if (! File::exists($path)) {
            return null;
        }

        $id = trim(File::get($path));

        return $id ?: null;
    }

    private static function removeActiveMarker(): void
    {
        $path = self::activeMarkerPath();

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Write the initial stub file. $payload is [sessionId, events].
     * Only used internally by start().
     */
    private static function writeFile(array $payload): void
    {
        [$sessionId, $eventsMap] = $payload;

        File::ensureDirectoryExists(config('audit.log_path'));
        File::put(
            self::sessionPath($sessionId),
            json_encode([
                'session_id' => $sessionId,
                'events' => array_values($eventsMap),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
