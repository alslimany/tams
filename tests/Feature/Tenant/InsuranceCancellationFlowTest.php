<?php

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\Actions\Finance\ProcessInsuranceProviderWalletTransactions;
use App\Actions\Orders\SyncInsuranceCancellationStatus;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\Finance\LedgerDriver;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'insurance-cancel-'.Str::random(4),
        'company_name' => 'Insurance Cancel Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'credentials' => [
            'base_url' => 'https://tameen.webapi.ly',
            'token' => 'test-token',
        ],
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 10,
    ]);

    app()->bind(LedgerDriver::class, fn () => new class implements LedgerDriver
    {
        public function postOperationJournal(string $source, string $description, array $entries): int
        {
            return 777;
        }
    });

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

function createCompulsoryInsuranceOrder(User $admin): Order
{
    $wallet = $admin->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(2000, ['type' => 'test_fund']);

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();

    return app(CreateOrderFromInsuranceBooking::class)->createFromPolicyDetails(
        userId: $admin->id,
        productSubtype: 'compulsory',
        policyDetails: [
            'policy_id' => 58737,
            'policy_number' => '',
            'report_reference' => 'ENC-CANCEL-001',
            'total_amount' => 150,
            'net_amount' => 140,
            'tax_amount' => 10,
            'currency' => 'LYD',
            'raw' => ['Code' => 200],
        ],
        beneficiaryData: [
            'name' => 'Cancel Me',
            'phone' => '0911111111',
            'address' => 'Tripoli',
        ],
        requestPayload: [],
        insuranceProvider: $provider,
    );
}

function createTravelInsuranceProviderWalletOrder(User $admin): Order
{
    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $providerWallet = $provider->getOrCreateCurrencyWallet('LYD');
    $providerWallet->depositFloat(500, ['type' => 'test_provider_fund']);

    $order = app(CreateOrderFromInsuranceBooking::class)->createFromTravelPolicies(
        userId: $admin->id,
        clientProfileData: [
            'name' => 'Travel Cancel Client',
            'phone' => '0911111111',
            'address' => 'Tripoli',
            'email' => 'travel-cancel@example.com',
            'client_profile_id' => 66272,
        ],
        policyItems: [
            [
                'passenger' => [
                    'first_name' => 'Travel',
                    'last_name' => 'Cancel',
                    'birth_date' => '1993-05-07',
                    'gender_id' => 1,
                    'birth_place' => 'Tripoli',
                    'passport_number' => 'TRVCANCEL1',
                    'nationality_id' => 1,
                ],
                'policy_details' => [
                    'policy_id' => 91001,
                    'policy_number' => 'TRV-91001',
                    'report_reference' => 'ENC-TRV-CANCEL-001',
                    'zone_id' => 4,
                    'duration_id' => 12,
                    'policy_date_from' => '2026-04-09',
                    'policy_date_to' => '2026-04-13',
                    'raw' => ['data' => ['Id' => 91001, 'EncryptedId' => 'ENC-TRV-CANCEL-001']],
                ],
                'net_amount' => 90,
                'total_amount' => 100,
                'tax_amount' => 10,
                'currency' => 'LYD',
            ],
        ],
        insuranceProvider: $provider,
        processAgencyWallet: false,
    );

    app(ProcessInsuranceProviderWalletTransactions::class)->execute($order, $provider);

    return $order->fresh('items');
}

test('submitting insurance cancellation stores response and moves item into cancellation state', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/CancelRequests/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'Messages' => 'تم الحفظ',
            'data' => [
                'InsurancePolicyId' => 58737,
                'Remarks' => 'طلب جديد',
            ],
        ], 200),
    ]);

    $order = createCompulsoryInsuranceOrder($state['admin']);
    $item = $order->items->firstOrFail();

    $this->actingAs($state['admin'])
        ->post($state['baseUrl'].route('insurance.order-items.cancel', ['order' => $order->id, 'item' => $item->id], false), [
            'remarks' => 'Customer requested cancellation',
        ])
        ->assertRedirect();

    $item->refresh();
    $order->refresh();

    expect((string) $item->status)->toBe('cancellation')
        ->and((string) $order->status)->toBe('cancellation')
        ->and(data_get($item->item_details, 'insurance.cancellation.insurance_policy_id'))->toBe(58737)
        ->and(data_get($item->item_details, 'insurance.cancellation.latest_remark'))->toBe('طلب جديد')
        ->and(data_get($item->item_details, 'insurance.cancellation.note'))->toBe('Customer requested cancellation');
});

test('syncing insurance cancellation status updates approved remark from provider list', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/CancelRequests/Get*' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'Messages' => 'تم ارسال البيانات',
            'data' => [
                [
                    'InsurancePolicyId' => 58737,
                    'Remarks' => 'تم الالغاء',
                ],
            ],
        ], 200),
    ]);

    $order = createCompulsoryInsuranceOrder($state['admin']);
    $item = $order->items->firstOrFail();

    $details = (array) $item->item_details;
    data_set($details, 'insurance.cancellation.insurance_policy_id', 58737);
    data_set($details, 'insurance.cancellation.latest_remark', 'طلب جديد');
    data_set($details, 'insurance.cancellation.requested_at', now()->subDay()->toIso8601String());

    $item->update([
        'status' => 'cancellation',
        'item_details' => $details,
    ]);

    $synced = app(SyncInsuranceCancellationStatus::class)->execute($item->fresh());

    $item->refresh();

    expect($synced)->not->toBeNull()
        ->and(data_get($item->item_details, 'insurance.cancellation.latest_remark'))->toBe('تم الالغاء')
        ->and(data_get($item->item_details, 'insurance.cancellation.approved_at'))->not->toBeEmpty();
});

test('syncing insurance cancellation status matches by insurance policy number and picks latest request when multiple rows exist', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/CancelRequests/Get*' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'Messages' => 'تم ارسال البيانات',
            'policyNumber' => null,
            'data' => [
                [
                    'CancelRequestNo' => 6000,
                    'Name' => 'طلب جديد',
                    'CreatedDate' => '2026-04-28T10:00:00',
                    'Remarks' => 'طلب جديد',
                    'TypeOfRisk' => 'الاجباري',
                    'InsurancePoliciesNo' => 58737,
                ],
                [
                    'CancelRequestNo' => 6001,
                    'Name' => 'طلب جديد',
                    'CreatedDate' => '2026-04-29T14:00:00',
                    'Remarks' => 'تم الالغاء',
                    'TypeOfRisk' => 'الاجباري',
                    'InsurancePoliciesNo' => 11111,
                ],
                [
                    'CancelRequestNo' => 6002,
                    'Name' => 'طلب جديد',
                    'CreatedDate' => '2026-04-29T15:26:07.5954488',
                    'Remarks' => 'تم الالغاء',
                    'TypeOfRisk' => 'الاجباري',
                    'InsurancePoliciesNo' => 58737,
                ],
            ],
        ], 200),
    ]);

    $order = createCompulsoryInsuranceOrder($state['admin']);
    $item = $order->items->firstOrFail();

    $details = (array) $item->item_details;
    data_set($details, 'insurance.cancellation.insurance_policy_id', 58737);
    data_set($details, 'insurance.cancellation.latest_remark', 'طلب جديد');
    data_set($details, 'insurance.cancellation.requested_at', now()->subDay()->toIso8601String());

    $item->update([
        'status' => 'cancellation',
        'item_details' => $details,
    ]);

    $synced = app(SyncInsuranceCancellationStatus::class)->execute($item->fresh());

    $item->refresh();

    expect($synced)->not->toBeNull()
        ->and(data_get($item->item_details, 'insurance.cancellation.insurance_policy_id'))->toBe(58737)
        ->and(data_get($item->item_details, 'insurance.cancellation.latest_remark'))->toBe('تم الالغاء')
        ->and(data_get($item->item_details, 'insurance.cancellation.latest_response.CancelRequestNo'))->toBe(6002)
        ->and(data_get($item->item_details, 'insurance.cancellation.latest_response.InsurancePoliciesNo'))->toBe(58737)
        ->and(data_get($item->item_details, 'insurance.cancellation.approved_at'))->not->toBeEmpty();
});

test('finalizing approved insurance cancellation refunds wallet and reverses commission', function () {
    global $state;

    $order = createCompulsoryInsuranceOrder($state['admin']);
    $item = $order->items->firstOrFail();

    $wallet = $state['admin']->getOrCreateCurrencyWallet('LYD');
    expect(round((float) $wallet->balanceFloat, 2))->toBe(1850.0);

    $details = (array) $item->item_details;
    data_set($details, 'insurance.cancellation.insurance_policy_id', 58737);
    data_set($details, 'insurance.cancellation.latest_remark', 'تم الالغاء');
    data_set($details, 'insurance.cancellation.approved_at', now()->toIso8601String());

    $item->update([
        'status' => 'cancellation',
        'item_details' => $details,
    ]);

    $order->update(['status' => 'cancellation']);

    $this->actingAs($state['admin'])
        ->post($state['baseUrl'].route('insurance.order-items.finalize-cancellation', ['order' => $order->id, 'item' => $item->id], false))
        ->assertRedirect();

    $item->refresh();
    $order->refresh();
    $wallet->refresh();

    expect((string) $item->status)->toBe('cancelled')
        ->and((string) $order->status)->toBe('cancelled')
        ->and(round((float) $order->amount_refunded, 2))->toBe(143.0)
        ->and((float) data_get($item->item_details, 'insurance.cancellation.financials.refund_amount'))->toBe(150.0)
        ->and((float) data_get($item->item_details, 'insurance.cancellation.financials.commission_reversal_amount'))->toBe(7.0)
        ->and(round((float) $wallet->balanceFloat, 2))->toBe(1993.0);
});

test('printing compulsory policy falls back to policy id when encrypted id fails', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=ENC-CANCEL-001' => Http::response('Server error', 500),
        'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=58737' => Http::response('%PDF-1.4 fake-pdf', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $order = createCompulsoryInsuranceOrder($state['admin']);
    $item = $order->items->firstOrFail();

    $this->actingAs($state['admin'])
        ->get($state['baseUrl'].route('insurance.order-items.report', ['order' => $order->id, 'item' => $item->id], false))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=ENC-CANCEL-001';
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=58737';
    });
});

test('submitting travel insurance cancellation uses travel policy id and stores provider response', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/CancelRequests/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'Messages' => 'تم الحفظ',
            'data' => [
                'InsurancePolicyId' => 91001,
                'Remarks' => 'طلب جديد',
            ],
        ], 200),
    ]);

    $order = createTravelInsuranceProviderWalletOrder($state['admin']);
    $item = $order->items->firstOrFail();

    $this->actingAs($state['admin'])
        ->post($state['baseUrl'].route('insurance.order-items.cancel', ['order' => $order->id, 'item' => $item->id], false), [
            'remarks' => 'Customer requested travel cancellation',
        ])
        ->assertRedirect();

    $item->refresh();
    $order->refresh();

    expect((string) $item->status)->toBe('cancellation')
        ->and((string) $order->status)->toBe('cancellation')
        ->and(data_get($item->item_details, 'insurance.cancellation.insurance_policy_id'))->toBe(91001)
        ->and(data_get($item->item_details, 'insurance.cancellation.latest_remark'))->toBe('طلب جديد')
        ->and(data_get($item->item_details, 'insurance.cancellation.note'))->toBe('Customer requested travel cancellation');
});

test('finalizing provider-wallet travel cancellation restores insurance provider wallet', function () {
    global $state;

    $order = createTravelInsuranceProviderWalletOrder($state['admin']);
    $item = $order->items->firstOrFail();
    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $providerWallet = $provider->getOrCreateCurrencyWallet('LYD');
    $adminWallet = $state['admin']->getOrCreateCurrencyWallet('LYD');

    expect(round((float) $providerWallet->balanceFloat, 2))->toBe(400.0)
        ->and(round((float) $adminWallet->balanceFloat, 2))->toBe(0.0);

    $details = (array) $item->item_details;
    data_set($details, 'insurance.cancellation.insurance_policy_id', 91001);
    data_set($details, 'insurance.cancellation.latest_remark', 'تم الالغاء');
    data_set($details, 'insurance.cancellation.approved_at', now()->toIso8601String());

    $item->update([
        'status' => 'cancellation',
        'item_details' => $details,
    ]);

    $order->update(['status' => 'cancellation']);

    $this->actingAs($state['admin'])
        ->post($state['baseUrl'].route('insurance.order-items.finalize-cancellation', ['order' => $order->id, 'item' => $item->id], false))
        ->assertRedirect();

    $item->refresh();
    $order->refresh();
    $providerWallet->refresh();
    $adminWallet->refresh();

    $refundTransactionId = (string) data_get($item->item_details, 'insurance.cancellation.financials.refund_transaction_id');
    $refundTransaction = WalletTransaction::query()->where('uuid', $refundTransactionId)->first();

    expect((string) $item->status)->toBe('cancelled')
        ->and((string) $order->status)->toBe('cancelled')
        ->and(round((float) $providerWallet->balanceFloat, 2))->toBe(500.0)
        ->and(round((float) $adminWallet->balanceFloat, 2))->toBe(0.0)
        ->and($refundTransaction)->not->toBeNull()
        ->and((int) $refundTransaction->wallet_id)->toBe((int) $providerWallet->id);
});
