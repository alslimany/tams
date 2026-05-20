<?php

namespace App\Console\Commands;

use App\Audit\AuditSessionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditStop extends Command
{
    protected $signature = 'audit:stop
                            {--force : Remove the .active marker even if the session file is missing}';

    protected $description = 'Stop the active audit session and finalise the session file';

    public function handle(): int
    {
        if (! config('audit.enabled')) {
            $this->error('Accounting audit is disabled.');

            return self::FAILURE;
        }

        if (! AuditSessionManager::activeOnDisk()) {
            $this->warn('No active audit session found.');
            $this->line('  Run <comment>php artisan audit:start</comment> to begin one.');

            return self::FAILURE;
        }

        // Resume the session so we can write the session_end event
        $resumed = AuditSessionManager::resume();

        if (! $resumed && ! $this->option('force')) {
            $this->error('Could not resume the session from disk. Use --force to remove the stale marker.');

            return self::FAILURE;
        }

        if ($resumed) {
            $sessionId = AuditSessionManager::sessionId();

            AuditSessionManager::end();

            $path = AuditSessionManager::sessionPath($sessionId);
            $size = File::exists($path) ? $this->humanFileSize(File::size($path)) : 'unknown';

            $this->info('Audit session stopped.');
            $this->table(
                ['Key', 'Value'],
                [
                    ['Session ID', $sessionId],
                    ['File', $path],
                    ['File size', $size],
                    ['Ended at', now()->toIso8601String()],
                ]
            );

            $this->line('');
            $this->line('  To view the report: <comment>php artisan audit:report '.$sessionId.'</comment>');
            $this->line('  To export JSON:     <comment>php artisan audit:report '.$sessionId.' --format=json</comment>');
        } else {
            // --force: just remove the stale marker
            $markerPath = config('audit.log_path').'/.active';
            if (File::exists($markerPath)) {
                File::delete($markerPath);
            }
            $this->warn('Stale .active marker removed.');
        }

        return self::SUCCESS;
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.'B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).'KB';
        }

        return round($bytes / 1048576, 1).'MB';
    }
}
