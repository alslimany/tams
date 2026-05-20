<?php

namespace Tests;

use Abivia\Ledger\Models\LedgerAccount;
use App\Listeners\PostLedgerEntryOnWalletTransaction;
use App\Models\Tenant;
use Bavix\Wallet\Internal\Events\TransactionCreatedEventInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Base test case for accounting tests that manage their own tenant lifecycle.
 *
 * Strategy:
 * - Point the central SQLite connection at a temp file on disk (not :memory:)
 *   so the schema persists across tests within the same process.
 * - Migrate central tables once per process (static flag).
 * - Create ONE shared agency tenant and ONE shared merchant tenant per process.
 *   This avoids running the heavy abivia CoA bootstrap multiple times (OOM risk).
 * - After each setUp(), de-duplicate the wallet transaction listener so that
 *   AppServiceProvider::boot() re-registering it on every test does not cause
 *   multiple journal entries per wallet event.
 * - Tear down the shared tenant in tearDownAfterClass().
 */
abstract class AccountingTestCase extends TestCase
{
    private static bool $booted = false;

    private static string $dbPath = '';

    private static string $tenantId = '';

    /** ID of the shared merchant tenant (type = merchant), created once per process. */
    private static string $merchantTenantId = '';

    protected function setUp(): void
    {
        parent::setUp();

        // De-duplicate the wallet transaction listener.
        // AppServiceProvider::boot() re-registers it on every test setUp() because
        // the app is refreshed per test. We forget all registrations and add exactly one.
        Event::forget(TransactionCreatedEventInterface::class);
        Event::listen(TransactionCreatedEventInterface::class, PostLedgerEntryOnWalletTransaction::class);

        if (! static::$booted) {
            // 1. Create a persistent temp SQLite file for the central DB.
            $tmp = tempnam(sys_get_temp_dir(), 'accounting_test_');
            rename($tmp, $tmp.'.sqlite');
            static::$dbPath = $tmp.'.sqlite';

            // 2. Point the sqlite connection at the temp file.
            config(['database.connections.sqlite.database' => static::$dbPath]);
            DB::purge('sqlite');
            DB::reconnect('sqlite');

            // 3. Run only central migrations (skip tenant/ subdirectory).
            Artisan::call('migrate:fresh', [
                '--seed' => false,
                '--path' => 'database/migrations',
                '--realpath' => false,
            ]);

            // 4. Create the shared agency tenant (bootstraps abivia CoA once).
            $tenant = Tenant::create([
                'id' => 'acct-test-'.Str::random(6),
                'company_name' => 'Accounting Test Agency',
                'status' => 'active',
                'subscription_status' => 'trial',
                'type' => 'direct',
            ]);
            $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
            static::$tenantId = $tenant->id;
            LedgerAccount::resetRules(); // free static root after agency bootstrap

            // 5. Create the shared merchant tenant (bootstraps abivia CoA once).
            $merchant = Tenant::create([
                'id' => 'acct-merchant-'.Str::random(6),
                'company_name' => 'Accounting Test Merchant',
                'status' => 'active',
                'subscription_status' => 'trial',
                'type' => 'merchant',
            ]);
            $merchant->domains()->create(['domain' => $merchant->id.'.localhost']);
            static::$merchantTenantId = $merchant->id;
            LedgerAccount::resetRules(); // free static root after merchant bootstrap

            static::$booted = true;
        } else {
            // Reconnect to the same file for subsequent tests.
            config(['database.connections.sqlite.database' => static::$dbPath]);
            DB::purge('sqlite');
            DB::reconnect('sqlite');
        }
    }

    /**
     * Returns the ID of the shared agency tenant created once for this test suite.
     */
    public static function sharedTenantId(): string
    {
        return static::$tenantId;
    }

    /**
     * Returns the ID of the shared merchant tenant created once for this test suite.
     */
    public static function sharedMerchantTenantId(): string
    {
        return static::$merchantTenantId;
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$tenantId !== '') {
            tenancy()->end();
            \Abivia\Ledger\Models\LedgerAccount::resetRules();
            Tenant::find(static::$tenantId)?->delete();
            static::$tenantId = '';
        }

        if (static::$merchantTenantId !== '') {
            Tenant::find(static::$merchantTenantId)?->delete();
            static::$merchantTenantId = '';
        }

        if (static::$dbPath !== '' && file_exists(static::$dbPath)) {
            @unlink(static::$dbPath);
            static::$dbPath = '';
        }

        static::$booted = false;

        parent::tearDownAfterClass();
    }
}
