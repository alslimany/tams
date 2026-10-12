<?php

use Abivia\Ledger\Models\LedgerAccount;
use App\Models\Airport;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgencyCreatedConfirmation;
use App\Services\OfficeIdGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Exceptions\DomainOccupiedByOtherTenantException;

afterEach(function () {
    tenancy()->end();

    Tenant::query()->get()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Insert a minimal MJI airport row so city_iata validation passes in tests.
 * The airports table is populated from a CSV import (not a seeder), so it is
 * empty after RefreshDatabase runs.
 */
function seedTenantCreationAirport(): void
{
    Airport::firstOrCreate(
        ['iata_code' => 'MJI'],
        [
            'name' => json_encode(['en' => 'Mitiga International Airport']),
            'city' => json_encode(['en' => 'Tripoli']),
            'country' => json_encode(['en' => 'Libya']),
            'type' => 'large_airport',
            'show_in_registration' => true,
            'latitude' => 32.894,
            'longitude' => 13.276,
        ],
    );
}

test('tenant creation provisions database, tenant tables and ledger', function () {
    $tenant = Tenant::create([
        'id' => 'tc-pipeline',
        'company_name' => 'Pipeline Test Agency',
    ]);

    $expectedDatabaseName = config('tenancy.database.prefix').$tenant->id.config('tenancy.database.suffix');

    expect($tenant->database()->getName())->toBe($expectedDatabaseName);
    expect(file_exists(database_path($expectedDatabaseName)))->toBeTrue();

    $tenantState = $tenant->run(fn (): array => [
        'orders_table' => Schema::hasTable('orders'),
        'orders_count' => DB::table('orders')->count(),
        'users_table' => Schema::hasTable('users'),
        'ledger_bootstrapped' => LedgerAccount::hasRoot(),
    ]);

    expect($tenantState['orders_table'])->toBeTrue();
    expect($tenantState['orders_count'])->toBe(0);
    expect($tenantState['users_table'])->toBeTrue();
    expect($tenantState['ledger_bootstrapped'])->toBeTrue();
});

test('tenant creation auto-assigns strictly incrementing agency numbers', function () {
    $tenants = collect(['tc-agency-1', 'tc-agency-2', 'tc-agency-3'])
        ->map(fn (string $id) => Tenant::create([
            'id' => $id,
            'company_name' => 'Agency Number '.$id,
        ]));

    $numbers = $tenants->pluck('agency_number');

    expect($numbers->all())->each->toMatch('/^AG-\d{6}$/');

    $sequences = $numbers->map(fn (string $number) => (int) substr($number, 3))->all();

    expect($sequences[1])->toBe($sequences[0] + 1);
    expect($sequences[2])->toBe($sequences[1] + 1);
    expect($numbers->unique())->toHaveCount(3);
});

test('tenant creation applies model and database defaults', function () {
    $tenant = Tenant::create([
        'id' => 'tc-defaults',
        'company_name' => 'Defaults Test Agency',
    ])->fresh();

    expect($tenant->type)->toBe('direct');
    expect($tenant->status)->toBe('active');
    expect($tenant->subscription_status)->toBe('trial');
    expect($tenant->subscription_plan)->toBeNull();
    expect($tenant->settings)->toBeNull();
    expect($tenant->is_default_agency)->toBeFalse();

    $tenant->update(['settings' => ['search_display_mode' => 'per_offer']]);

    expect($tenant->fresh()->settings)->toBe(['search_display_mode' => 'per_offer']);
});

test('tenant domain is created once and duplicate domains are rejected', function () {
    $tenant = Tenant::create([
        'id' => 'tc-domain-1',
        'company_name' => 'Domain Test Agency',
    ]);

    $tenant->domains()->create(['domain' => 'tc-domain-1.localhost']);

    expect($tenant->domains()->count())->toBe(1);
    expect($tenant->fresh()->domains()->pluck('domain')->all())->toBe(['tc-domain-1.localhost']);

    expect(fn () => $tenant->domains()->create(['domain' => 'tc-domain-1.localhost']))
        ->toThrow(DomainOccupiedByOtherTenantException::class);

    expect(Domain::query()->where('domain', 'tc-domain-1.localhost')->count())->toBe(1);
});

test('register-agency creates tenant domain and hashed admin credentials', function () {
    Notification::fake();
    fakePublicDisk();

    seedTenantCreationAirport();

    $response = $this->post('/register-agency', [
        'company_name' => 'Hashed Admin Agency',
        'email' => 'qa-hash@tc.test',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => UploadedFile::fake()->create('commercial-register.pdf', 128, 'application/pdf'),
        'passport' => UploadedFile::fake()->create('passport.pdf', 64, 'application/pdf'),
    ]);

    $response->assertRedirectToRoute('agency.registration.success');

    $tenant = Tenant::query()
        ->where('company_name', 'Hashed Admin Agency')
        ->where('owner_email', 'qa-hash@tc.test')
        ->firstOrFail();

    $expectedDomain = strtolower($tenant->office_id).'.'.config('tenancy.tenant_base_domain');

    expect($tenant->domains()->pluck('domain')->all())->toBe([$expectedDomain]);

    $owner = $tenant->run(fn () => User::query()->where('email', 'qa-hash@tc.test')->first());

    expect($owner)->not->toBeNull();
    expect($owner->role)->toBe('admin');
    expect($owner->password)->not->toBe('password');
    expect(Hash::check('password', $owner->password))->toBeTrue();

    Notification::assertSentOnDemand(AgencyCreatedConfirmation::class);
});

test('register-agency validation failure creates no tenant', function () {
    Notification::fake();
    fakePublicDisk();

    $response = $this->post('/register-agency', [
        'company_name' => 'Rejected Agency',
        'email' => 'qa-reject@tc.test',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['commercial_register', 'passport']);

    expect(Tenant::query()->count())->toBe(0);
    expect(Tenant::query()->where('company_name', 'Rejected Agency')->exists())->toBeFalse();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('office id exhaustion fails registration without partial state', function () {
    Notification::fake();
    fakePublicDisk();

    seedTenantCreationAirport();

    // Occupy every sequence OfficeIdGenerator would try for MJI + "AT"
    // (Atlas Travel) with raw rows so no tenant pipeline runs for them.
    $sequenceRows = collect(range(1, 99))
        ->map(fn (int $sequence): array => [
            'id' => sprintf('tc-exhaust-%02d', $sequence),
            'office_id' => sprintf('MJIAT%02dAA', $sequence),
            'created_at' => now(),
            'updated_at' => now(),
        ])
        ->all();

    DB::table('tenants')->insert($sequenceRows);

    expect(fn () => app(OfficeIdGenerator::class)->generate('MJI', 'Atlas Travel'))
        ->toThrow(RuntimeException::class);

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register-agency', [
        'company_name' => 'Atlas Travel',
        'email' => 'qa-exhaust@tc.test',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => UploadedFile::fake()->create('commercial-register.pdf', 128, 'application/pdf'),
        'passport' => UploadedFile::fake()->create('passport.pdf', 64, 'application/pdf'),
    ]))->toThrow(RuntimeException::class);

    expect(Tenant::query()->where('company_name', 'Atlas Travel')->exists())->toBeFalse();
    expect(Domain::query()->where('tenant_id', 'like', 'MJIAT%')->exists())->toBeFalse();
    expect(Storage::disk('public')->allFiles())->toBe([]);

    DB::table('tenants')->where('id', 'like', 'tc-exhaust-%')->delete();
});

test('registration failure after tenant creation discards tenant, files and database', function () {
    Notification::fake();
    fakePublicDisk();

    seedTenantCreationAirport();

    // The controller does `new OfficeIdGenerator`
    // (app/Http/Controllers/AgencyRegistrationController.php:63), so a container-bound
    // stub instance would be ignored. Predict the id the real generator returns —
    // deterministic on a fresh tenants table.
    $officeId = (new OfficeIdGenerator)->generate('MJI', 'Cleanup Probe Agency');
    $targetDomain = strtolower($officeId).'.'.config('tenancy.tenant_base_domain');

    // Own the target domain so domains()->create() throws inside store(), after
    // Tenant::create, the document store and the tenant update have all run.
    $blocker = Tenant::create([
        'id' => 'tc-domain-blocker',
        'company_name' => 'Domain Blocker Agency',
    ]);
    $blocker->domains()->create(['domain' => $targetDomain]);

    // Capture the document paths written by store() between file storage and domain creation.
    $capturedPaths = [];
    $captureDocuments = function (Tenant $tenant) use (&$capturedPaths): void {
        $capturedPaths[] = $tenant->commercial_register_path;
        $capturedPaths[] = $tenant->passport_path;
    };
    Tenant::updated($captureDocuments);

    $tenantDatabasePath = database_path(
        config('tenancy.database.prefix').$officeId.config('tenancy.database.suffix')
    );
    $tenantsBefore = Tenant::query()->count();

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register-agency', [
        'company_name' => 'Cleanup Probe Agency',
        'email' => 'qa-cleanup@tc.test',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => UploadedFile::fake()->create('commercial-register.pdf', 128, 'application/pdf'),
        'passport' => UploadedFile::fake()->create('passport.pdf', 64, 'application/pdf'),
    ]))->toThrow(DomainOccupiedByOtherTenantException::class);

    // The failure happened after Tenant::create and after both documents were stored.
    // (A first tenant update with no document paths comes from the creation pipeline.)
    $documentPaths = collect($capturedPaths)
        ->filter(fn (?string $path) => $path !== null)
        ->values();

    expect($documentPaths)->toHaveCount(2);
    expect($documentPaths[0])->toStartWith("registrations/{$officeId}/");
    expect($documentPaths[1])->toStartWith("registrations/{$officeId}/");
    expect($tenantsBefore)->toBe(1);

    // discardPartialRegistration() removed the documents, the tenant row and its database.
    expect(Storage::disk('public')->exists($documentPaths[0]))->toBeFalse();
    expect(Storage::disk('public')->exists($documentPaths[1]))->toBeFalse();
    expect(is_file(storage_path('framework/testing/disks/public/'.$documentPaths[0])))->toBeFalse();
    expect(is_file(storage_path('framework/testing/disks/public/'.$documentPaths[1])))->toBeFalse();
    expect(Tenant::query()->count())->toBe(1);
    expect(Tenant::query()->where('company_name', 'Cleanup Probe Agency')->exists())->toBeFalse();
    expect(file_exists($tenantDatabasePath))->toBeFalse();

    // Nothing new was attached to the failed tenant — only the blocker's domain remains.
    expect(Domain::query()->where('domain', $targetDomain)->count())->toBe(1);
    expect(Domain::query()->where('domain', $targetDomain)->value('tenant_id'))->toBe('tc-domain-blocker');

    Event::forget('eloquent.updated: '.Tenant::class);
    $blocker->delete();
});
