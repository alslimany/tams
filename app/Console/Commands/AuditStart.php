<?php

namespace App\Console\Commands;

use App\Audit\AuditSessionManager;
use Illuminate\Console\Command;

class AuditStart extends Command
{
    protected $signature = 'audit:start
                            {label? : A descriptive label for this audit session}';

    protected $description = 'Start a new accounting audit session';

    public function handle(): int
    {
        if (! config('audit.enabled')) {
            $this->error('Accounting audit is disabled. Set ACCOUNTING_AUDIT_ENABLED=true in your .env to enable it.');
            $this->line('  <comment>Never enable this in production.</comment>');

            return self::FAILURE;
        }

        if (AuditSessionManager::activeOnDisk()) {
            $this->warn('An audit session is already active on disk.');
            $this->line('  Run <comment>php artisan audit:list</comment> to see it.');
            $this->line('  Run <comment>php artisan audit:stop</comment> to end it first.');

            return self::FAILURE;
        }

        $label = $this->argument('label') ?? 'manual-'.now()->format('Y-m-d-His');

        $sessionId = AuditSessionManager::start($label);

        $this->info('Audit session started.');
        $this->table(
            ['Key', 'Value'],
            [
                ['Session ID', $sessionId],
                ['Label', $label],
                ['Log path', AuditSessionManager::sessionPath($sessionId)],
                ['Started at', now()->toIso8601String()],
            ]
        );

        $this->line('');
        $this->line('  The session is now <info>active</info>. Make your API calls or run booking flows.');
        $this->line('  The audit middleware will record wallet snapshots and HTTP events automatically.');
        $this->line('  When done, run: <comment>php artisan audit:stop</comment>');
        $this->line('  To view the report at any time: <comment>php artisan audit:report '.$sessionId.'</comment>');

        return self::SUCCESS;
    }
}
