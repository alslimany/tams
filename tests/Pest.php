<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

// Accounting tests manage their own tenant lifecycle (SQLite per-tenant DBs).
// They must NOT use RefreshDatabase — it runs migrate:fresh on every test which
// causes memory exhaustion when combined with the full abivia CoA bootstrap.
pest()->extend(Tests\AccountingTestCase::class)
    ->in('Accounting');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Fake the public disk so it survives stancl/tenancy's FilesystemTenancyBootstrapper.
 *
 * The bootstrapper runs during the tenant creation pipeline (BootstrapTenantLedgerJob
 * calls $tenant->run()) and calls Storage::forgetDisk(['local', 'public']), which drops
 * the fake instance. The next Storage::disk('public') / UploadedFile::store(..., 'public')
 * then re-resolves from config, so uploads land in the real storage/app/public.
 * Pinning the config root keeps them inside storage/framework/testing/disks/public.
 */
function fakePublicDisk(): void
{
    config()->set('filesystems.disks.public.root', storage_path('framework/testing/disks/public'));

    Storage::fake('public');
}
