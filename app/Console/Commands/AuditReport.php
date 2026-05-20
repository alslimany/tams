<?php

namespace App\Console\Commands;

use App\Audit\AuditReportGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditReport extends Command
{
    protected $signature = 'audit:report
                            {session : The session ID (UUID) to generate a report for}
                            {--format=table : Output format: table, json}
                            {--checks-only : Only show the accounting checks section}
                            {--anomalies-only : Only show the anomalies section}';

    protected $description = 'Generate a human-readable report from an audit session file';

    public function handle(AuditReportGenerator $generator): int
    {
        $sessionId = $this->argument('session');
        $path = config('audit.log_path').'/session_'.$sessionId.'.json';

        if (! File::exists($path)) {
            $this->error("Session file not found: {$path}");
            $this->line('  Run <comment>php artisan audit:list</comment> to see available sessions.');

            return self::FAILURE;
        }

        $report = $generator->generate($path);

        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($this->option('checks-only')) {
            $this->renderAccountingChecks($report['accounting_checks']);

            return self::SUCCESS;
        }

        if ($this->option('anomalies-only')) {
            $this->renderAnomalies($report['anomalies']);

            return self::SUCCESS;
        }

        $this->renderMeta($report['meta']);
        $this->renderFlowSummary($report['flow_summary']);
        $this->renderFinancialNumbers($report['financial_numbers']);
        $this->renderWalletMovements($report['wallet_movements']);
        $this->renderBalanceChecks($report['balance_checks']);
        $this->renderAccountingChecks($report['accounting_checks']);
        $this->renderAnomalies($report['anomalies']);

        return self::SUCCESS;
    }

    private function renderMeta(array $meta): void
    {
        $this->line('');
        $this->info('═══ AUDIT SESSION ═══════════════════════════════════════');
        $this->table(
            ['Field', 'Value'],
            [
                ['Session ID', $meta['session_id']],
                ['Label', $meta['label']],
                ['Tenant', $meta['tenant_id'] ?? 'N/A'],
                ['Started at', $meta['started_at']],
                ['Environment', $meta['app_env']],
                ['PHP', $meta['php_version']],
                ['Total events', $meta['total_events']],
                ['Elapsed', $meta['total_elapsed_ms'].'ms'],
            ]
        );
    }

    private function renderFlowSummary(array $flow): void
    {
        $this->line('');
        $this->info('═══ FLOW SUMMARY ════════════════════════════════════════');
        $this->table(
            ['Event', 'Count'],
            collect($flow)->map(fn ($v, $k) => [str_replace('_', ' ', ucfirst($k)), $v])->values()->toArray()
        );
    }

    private function renderFinancialNumbers(array $numbers): void
    {
        $this->line('');
        $this->info('═══ FINANCIAL NUMBERS ═══════════════════════════════════');

        if (empty($numbers['by_product'])) {
            $this->line('  No orders recorded in this session.');

            return;
        }

        foreach ($numbers['by_product'] as $product => $stats) {
            $this->line("  <comment>{$product}</comment>");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Orders', $stats['count']],
                    ['Total Selling', number_format($stats['total_selling'], 3)],
                    ['Total VAT', number_format($stats['total_vat'], 3)],
                    ['Total Net', number_format($stats['total_net'], 3)],
                    ['Total Cost', number_format($stats['total_cost'], 3)],
                    ['Gross Margin', number_format($stats['total_margin'], 3)],
                    ['Avg Margin %', $stats['avg_margin_pct'].'%'],
                ]
            );
        }

        $t = $numbers['totals'];
        $this->line('  <comment>TOTALS</comment>');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Selling', number_format($t['selling'], 3)],
                ['VAT', number_format($t['vat'], 3)],
                ['Net', number_format($t['net'], 3)],
                ['Cost', number_format($t['cost'], 3)],
                ['Margin', number_format($t['margin'], 3)],
                ['Commission', number_format($t['commission'], 3)],
            ]
        );
    }

    private function renderWalletMovements(array $movements): void
    {
        $this->line('');
        $this->info('═══ WALLET MOVEMENTS ════════════════════════════════════');

        if (empty($movements)) {
            $this->line('  No wallet transactions recorded.');

            return;
        }

        $this->table(
            ['Wallet', 'Type', 'Amount', 'Balance After', 'TX Type', 'Has Ledger Meta'],
            collect($movements)->map(fn ($m) => [
                $m['wallet'],
                $m['type'],
                $m['amount'],
                $m['balance_after'],
                $m['tx_type'],
                $m['has_ledger_meta'] ? '<info>✓</info>' : '<error>✗</error>',
            ])->toArray()
        );
    }

    private function renderBalanceChecks(array $checks): void
    {
        $this->line('');
        $this->info('═══ WALLET ↔ LEDGER BALANCE CHECKS ═════════════════════');

        if (empty($checks)) {
            $this->line('  No balance check data available (no wallet snapshots recorded).');

            return;
        }

        $this->table(
            ['Wallet', 'Account', 'Wallet Balance', 'Ledger Balance', 'Diff', 'Status'],
            collect($checks)->map(fn ($c) => [
                $c['wallet_slug'],
                $c['account_code'],
                number_format($c['wallet_balance'], 3),
                number_format($c['ledger_balance'], 3),
                number_format($c['difference'], 3),
                $c['status'] === 'MATCHED' ? '<info>MATCHED</info>' : '<error>MISMATCH</error>',
            ])->toArray()
        );
    }

    private function renderAccountingChecks(array $checks): void
    {
        $this->line('');
        $this->info('═══ ACCOUNTING CHECKS ═══════════════════════════════════');

        $rows = [];
        $allPassed = true;

        foreach ($checks as $key => $check) {
            $passed = $check['passed'];
            if (! $passed) {
                $allPassed = false;
            }
            $rows[] = [
                str_replace('_', ' ', $key),
                $passed ? '<info>PASS</info>' : '<error>FAIL</error>',
                $check['message'],
            ];
        }

        $this->table(['Check', 'Result', 'Message'], $rows);

        if ($allPassed) {
            $this->info('  ✓ All accounting checks passed.');
        } else {
            $this->error('  ✗ Some accounting checks failed. Review the details above.');
        }
    }

    private function renderAnomalies(array $anomalies): void
    {
        $this->line('');
        $this->info('═══ ANOMALIES ════════════════════════════════════════════');

        if (empty($anomalies)) {
            $this->info('  ✓ No anomalies detected.');

            return;
        }

        foreach ($anomalies as $anomaly) {
            $this->error("  ⚠ {$anomaly['type']}");
            if (isset($anomaly['count'])) {
                $this->line("    Count: {$anomaly['count']}");
            }
            if (! empty($anomaly['detail'])) {
                $this->line('    Detail: '.implode(', ', (array) $anomaly['detail']));
            }
        }
    }
}
