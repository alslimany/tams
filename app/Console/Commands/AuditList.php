<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditList extends Command
{
    protected $signature = 'audit:list
                            {--limit=20 : Maximum number of sessions to show}
                            {--label= : Filter sessions by label substring}';

    protected $description = 'List all recorded audit sessions';

    public function handle(): int
    {
        $logPath = config('audit.log_path');

        if (! File::isDirectory($logPath)) {
            $this->warn('No audit sessions found. The log directory does not exist yet.');
            $this->line("  Expected path: {$logPath}");

            return self::SUCCESS;
        }

        $files = collect(File::files($logPath))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'session_') && $f->getExtension() === 'json')
            ->sortByDesc(fn ($f) => $f->getMTime());

        if ($files->isEmpty()) {
            $this->warn('No audit session files found in '.$logPath);

            return self::SUCCESS;
        }

        $label = $this->option('label');
        $limit = (int) $this->option('limit');

        $rows = $files
            ->map(function ($file) {
                try {
                    $data = json_decode(File::get($file->getPathname()), true);
                    $events = collect($data['events'] ?? []);
                    $start = $events->firstWhere('event_type', 'session_start');
                    $end = $events->firstWhere('event_type', 'session_end');

                    return [
                        'session_id' => $data['session_id'] ?? 'unknown',
                        'label' => $start['data']['label'] ?? 'unnamed',
                        'tenant_id' => $start['data']['tenant_id'] ?? 'N/A',
                        'started_at' => $start['data']['started_at'] ?? 'N/A',
                        'events' => $end['data']['total_events'] ?? count($data['events'] ?? []),
                        'elapsed_ms' => $end['data']['total_elapsed_ms'] ?? 'N/A',
                        'file_size' => $this->humanFileSize($file->getSize()),
                        'path' => $file->getPathname(),
                    ];
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->when($label, fn ($c) => $c->filter(fn ($r) => str_contains($r['label'], $label)))
            ->take($limit);

        if ($rows->isEmpty()) {
            $this->warn('No sessions match the given filters.');

            return self::SUCCESS;
        }

        $this->info("Found {$rows->count()} audit session(s):");
        $this->line('');

        $this->table(
            ['Session ID', 'Label', 'Tenant', 'Started At', 'Events', 'Elapsed', 'Size'],
            $rows->map(fn ($r) => [
                $r['session_id'],
                $r['label'],
                $r['tenant_id'],
                $r['started_at'],
                $r['events'],
                $r['elapsed_ms'] !== 'N/A' ? $r['elapsed_ms'].'ms' : 'N/A',
                $r['file_size'],
            ])->values()->toArray()
        );

        $this->line('');
        $this->line('  To view a report: <comment>php artisan audit:report {session_id}</comment>');
        $this->line('  To export JSON:   <comment>php artisan audit:report {session_id} --format=json</comment>');

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
