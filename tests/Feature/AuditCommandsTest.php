<?php

use App\Audit\AuditReportGenerator;
use App\Audit\AuditSessionManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    Config::set('audit.enabled', true);
    Config::set('audit.log_path', storage_path('logs/audit-test-'.Str::random(8)));

    AuditSessionManager::reset();
});

afterEach(function () {
    AuditSessionManager::reset();

    $path = config('audit.log_path');
    if (File::isDirectory($path)) {
        File::deleteDirectory($path);
    }
});

// ─── AuditSessionManager persistence ─────────────────────────────────────────

test('start() writes session file to disk immediately', function () {
    $sessionId = AuditSessionManager::start('persist-test');

    $path = AuditSessionManager::sessionPath($sessionId);
    expect(File::exists($path))->toBeTrue();

    $data = json_decode(File::get($path), true);
    expect($data['session_id'])->toBe($sessionId);
    expect($data['events'][0]['event_type'])->toBe('session_start');
    expect($data['events'][0]['data']['label'])->toBe('persist-test');
});

test('start() writes .active marker to disk', function () {
    $sessionId = AuditSessionManager::start('marker-test');

    expect(AuditSessionManager::activeOnDisk())->toBeTrue();
});

test('resume() picks up session from .active marker', function () {
    $sessionId = AuditSessionManager::start('resume-test');

    // Simulate a new process: reset in-memory state
    AuditSessionManager::reset();
    expect(AuditSessionManager::active())->toBeFalse();

    $resumed = AuditSessionManager::resume();

    expect($resumed)->toBeTrue();
    expect(AuditSessionManager::active())->toBeTrue();
    expect(AuditSessionManager::sessionId())->toBe($sessionId);
});

test('resume() returns false when no .active marker exists', function () {
    $resumed = AuditSessionManager::resume();

    expect($resumed)->toBeFalse();
    expect(AuditSessionManager::active())->toBeFalse();
});

test('resume() removes stale marker when session file is missing', function () {
    $sessionId = AuditSessionManager::start('stale-test');

    // Delete the session file to simulate a stale marker
    File::delete(AuditSessionManager::sessionPath($sessionId));
    AuditSessionManager::reset();

    $resumed = AuditSessionManager::resume();

    expect($resumed)->toBeFalse();
    expect(AuditSessionManager::activeOnDisk())->toBeFalse();
});

test('flush() appends events to existing file', function () {
    $sessionId = AuditSessionManager::start('flush-test');

    // Simulate a second process resuming and adding events
    AuditSessionManager::reset();
    AuditSessionManager::resume();

    AuditSessionManager::record('http_request', ['method' => 'POST', 'path' => 'api/v1/flights/search']);
    AuditSessionManager::flush();

    $data = json_decode(File::get(AuditSessionManager::sessionPath($sessionId)), true);
    $types = collect($data['events'])->pluck('event_type')->toArray();

    expect($types)->toContain('session_start');
    expect($types)->toContain('http_request');
    expect(count($data['events']))->toBe(2);
});

test('flush() assigns sequential seq numbers across processes', function () {
    $sessionId = AuditSessionManager::start('seq-test');

    AuditSessionManager::reset();
    AuditSessionManager::resume();
    AuditSessionManager::record('http_request', ['path' => 'api/v1/flights']);
    AuditSessionManager::record('http_response', ['status' => 200]);
    AuditSessionManager::flush();

    $data = json_decode(File::get(AuditSessionManager::sessionPath($sessionId)), true);
    $seqs = collect($data['events'])->pluck('seq')->toArray();

    expect($seqs)->toBe([1, 2, 3]); // session_start=1, http_request=2, http_response=3
});

test('end() writes session_end event and removes .active marker', function () {
    $sessionId = AuditSessionManager::start('end-test');

    AuditSessionManager::reset();
    AuditSessionManager::resume();
    AuditSessionManager::end();

    expect(AuditSessionManager::activeOnDisk())->toBeFalse();
    expect(AuditSessionManager::active())->toBeFalse();

    $data = json_decode(File::get(AuditSessionManager::sessionPath($sessionId)), true);
    $types = collect($data['events'])->pluck('event_type')->toArray();
    expect($types)->toContain('session_end');
});

// ─── audit:start ──────────────────────────────────────────────────────────────

test('audit:start fails when audit is disabled', function () {
    Config::set('audit.enabled', false);

    $this->artisan('audit:start')
        ->assertFailed();
});

test('audit:start succeeds and writes session file', function () {
    $this->artisan('audit:start', ['label' => 'test-session'])
        ->assertSuccessful()
        ->expectsOutputToContain('Audit session started');

    expect(AuditSessionManager::activeOnDisk())->toBeTrue();
});

test('audit:start fails when a session is already active on disk', function () {
    AuditSessionManager::start('first');

    $this->artisan('audit:start', ['label' => 'second'])
        ->assertFailed()
        ->expectsOutputToContain('already active');
});

test('audit:start uses default label when none provided', function () {
    $this->artisan('audit:start')
        ->assertSuccessful()
        ->expectsOutputToContain('manual-');
});

// ─── audit:stop ───────────────────────────────────────────────────────────────

test('audit:stop fails when no active session', function () {
    $this->artisan('audit:stop')
        ->assertFailed()
        ->expectsOutputToContain('No active audit session');
});

test('audit:stop finalises the session and removes .active marker', function () {
    $sessionId = AuditSessionManager::start('stop-test');
    AuditSessionManager::reset(); // Simulate new process

    $this->artisan('audit:stop')
        ->assertSuccessful()
        ->expectsOutputToContain('Audit session stopped')
        ->expectsOutputToContain($sessionId);

    expect(AuditSessionManager::activeOnDisk())->toBeFalse();

    $data = json_decode(File::get(AuditSessionManager::sessionPath($sessionId)), true);
    $types = collect($data['events'])->pluck('event_type')->toArray();
    expect($types)->toContain('session_end');
});

test('audit:stop --force removes stale marker', function () {
    $sessionId = AuditSessionManager::start('force-test');
    File::delete(AuditSessionManager::sessionPath($sessionId));
    AuditSessionManager::reset();

    // Manually recreate the marker since the file was deleted
    File::put(config('audit.log_path').'/.active', $sessionId);

    $this->artisan('audit:stop', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Stale .active marker removed');

    expect(AuditSessionManager::activeOnDisk())->toBeFalse();
});

// ─── audit:list ───────────────────────────────────────────────────────────────

test('audit:list shows warning when log directory does not exist', function () {
    Config::set('audit.log_path', storage_path('logs/nonexistent-'.Str::random(8)));

    $this->artisan('audit:list')
        ->assertSuccessful()
        ->expectsOutputToContain('No audit sessions found');
});

test('audit:list shows warning when no session files exist', function () {
    File::ensureDirectoryExists(config('audit.log_path'));

    $this->artisan('audit:list')
        ->assertSuccessful()
        ->expectsOutputToContain('No audit session files found');
});

test('audit:list shows sessions from log directory', function () {
    $sessionId = AuditSessionManager::start('list-test');

    $this->artisan('audit:list')
        ->assertSuccessful()
        ->expectsOutputToContain($sessionId);
});

test('audit:list filters by label', function () {
    $id1 = AuditSessionManager::start('flight-scenario');
    AuditSessionManager::reset();

    // Remove .active so we can start a second session
    File::delete(config('audit.log_path').'/.active');

    $id2 = AuditSessionManager::start('hotel-scenario');
    AuditSessionManager::reset();

    $this->artisan('audit:list', ['--label' => 'flight'])
        ->assertSuccessful()
        ->expectsOutputToContain($id1);
});

// ─── audit:report ─────────────────────────────────────────────────────────────

test('audit:report fails when session file does not exist', function () {
    $this->artisan('audit:report', ['session' => 'nonexistent-uuid'])
        ->assertFailed()
        ->expectsOutputToContain('Session file not found');
});

test('audit:report outputs table format by default', function () {
    $sessionId = AuditSessionManager::start('report-test');
    AuditSessionManager::record('order_created', [
        'order_id' => 'ORD-001',
        'product_type' => 'flight',
        'selling_price' => 500.0,
        'vat_amount' => 50.0,
        'provider_cost' => 400.0,
        'gross_margin' => 50.0,
        'commission' => 10.0,
    ]);
    AuditSessionManager::flush();

    $this->artisan('audit:report', ['session' => $sessionId])
        ->assertSuccessful()
        ->expectsOutputToContain('AUDIT SESSION')
        ->expectsOutputToContain('ACCOUNTING CHECKS')
        ->expectsOutputToContain('FLOW SUMMARY');
});

test('audit:report outputs valid json', function () {
    $sessionId = AuditSessionManager::start('json-test');
    AuditSessionManager::flush();

    $path = AuditSessionManager::sessionPath($sessionId);
    $data = json_decode(File::get($path), true);

    expect($data)->toBeArray();
    expect($data['session_id'])->toBe($sessionId);
});

test('audit:report --checks-only shows only accounting checks', function () {
    $sessionId = AuditSessionManager::start('checks-only-test');

    $this->artisan('audit:report', ['session' => $sessionId, '--checks-only' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('ACCOUNTING CHECKS');
});

test('audit:report --anomalies-only shows only anomalies', function () {
    $sessionId = AuditSessionManager::start('anomalies-only-test');

    $this->artisan('audit:report', ['session' => $sessionId, '--anomalies-only' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('ANOMALIES');
});

// ─── AuditReportGenerator ─────────────────────────────────────────────────────

test('report generator returns all expected sections', function () {
    $sessionId = AuditSessionManager::start('generator-test');

    $report = app(AuditReportGenerator::class)->generate(AuditSessionManager::sessionPath($sessionId));

    expect($report)->toHaveKeys([
        'meta', 'flow_summary', 'financial_numbers', 'wallet_movements',
        'ledger_entries', 'balance_checks', 'accounting_checks', 'provider_api', 'anomalies',
    ]);
});

test('report generator meta contains session id and label', function () {
    $sessionId = AuditSessionManager::start('meta-check');

    $report = app(AuditReportGenerator::class)->generate(AuditSessionManager::sessionPath($sessionId));

    expect($report['meta']['session_id'])->toBe($sessionId);
    expect($report['meta']['label'])->toBe('meta-check');
});

test('report generator accounting checks pass when no events recorded', function () {
    $sessionId = AuditSessionManager::start('empty-checks');

    $report = app(AuditReportGenerator::class)->generate(AuditSessionManager::sessionPath($sessionId));

    foreach ($report['accounting_checks'] as $key => $check) {
        expect($check['passed'])->toBeTrue("Check '{$key}' should pass on empty session");
    }
});

test('report generator detects unbalanced journal entry', function () {
    $sessionId = AuditSessionManager::start('unbalanced-test');
    AuditSessionManager::record('journal_entry_posted', [
        'reference' => 'JE-001',
        'description' => 'Test entry',
        'journal' => 'SALES',
        'date' => now()->toDateString(),
        'total_debit' => 100.0,
        'total_credit' => 90.0,
        'is_balanced' => false,
        'lines' => [],
    ]);
    AuditSessionManager::flush();

    $report = app(AuditReportGenerator::class)->generate(AuditSessionManager::sessionPath($sessionId));

    expect($report['accounting_checks']['all_entries_balanced']['passed'])->toBeFalse();
    expect($report['accounting_checks']['all_entries_balanced']['failing'])->toContain('JE-001');
});

test('report generator detects anomaly for 5xx response', function () {
    $sessionId = AuditSessionManager::start('anomaly-test');
    AuditSessionManager::record('http_response', [
        'status' => 500,
        'is_inertia' => false,
        'page_props' => [],
    ]);
    AuditSessionManager::flush();

    $report = app(AuditReportGenerator::class)->generate(AuditSessionManager::sessionPath($sessionId));

    $types = collect($report['anomalies'])->pluck('type')->toArray();
    expect($types)->toContain('SERVER_ERROR');
});
