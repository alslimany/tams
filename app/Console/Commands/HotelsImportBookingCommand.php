<?php

namespace App\Console\Commands;

use App\Actions\Finance\CreateOrderFromHotelBooking;
use App\Actions\Finance\ProcessHotelProviderWalletTransactions;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use App\Services\Hotels\HotelProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class HotelsImportBookingCommand extends Command
{
    protected $signature = 'hotels:import-booking
        {tenant : Tenant ID (e.g. median)}
        {--booking-id= : Provider booking id, e.g. 37672}
        {--user-id= : Order owner user id}
        {--payload= : Path to JSON file with the 3T book envelope / response (+ optional customer)}
        {--customer-first-name= : Guest first name when payload has no customer}
        {--customer-last-name= : Guest last name when payload has no customer}
        {--customer-email= : Guest email (defaults to owner user email)}
        {--customer-phone= : Guest phone / mobile}
        {--customer-country= : Guest country (defaults to hotel countryName)}
        {--customer-city= : Guest city (defaults to hotel cityName)}
        {--debit-wallet : Debit hotel provider wallet after create (same as a normal book)}
        {--dry-run : Show what would be created without writing}';

    protected $description = <<<'DESC'
Import a hotel provider booking that succeeded externally but was never stored as a TAMS order.

Payload JSON may be a raw 3T book body (Telescope) with only:
  response.bookingId, response.totalPurchase, response.currency, response.booking.*

Customer is optional in the JSON. Prefer --user-id= (name/email used as contact fallback),
or pass --customer-first-name / --customer-last-name (and optional email/phone/country/city).

Example — recover median booking 37672 from a raw 3T response file:
  php artisan hotels:import-booking median \
    --booking-id=37672 \
    --user-id=3 \
    --payload=/path/to/37672.json \
    --debit-wallet

Ensure the hotel provider LYD wallet has enough balance before --debit-wallet.
DESC;

    public function handle(
        CreateOrderFromHotelBooking $createOrderFromHotelBooking,
        ProcessHotelProviderWalletTransactions $hotelProviderWalletTransactions,
        HotelProviderManager $hotelProviderManager,
    ): int {
        $tenantId = (string) $this->argument('tenant');
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$tenantId}] was not found.");

            return self::FAILURE;
        }

        return (int) $tenant->run(function () use (
            $createOrderFromHotelBooking,
            $hotelProviderWalletTransactions,
            $hotelProviderManager,
        ): int {
            return $this->handleForCurrentTenant(
                $createOrderFromHotelBooking,
                $hotelProviderWalletTransactions,
                $hotelProviderManager,
            );
        });
    }

    protected function handleForCurrentTenant(
        CreateOrderFromHotelBooking $createOrderFromHotelBooking,
        ProcessHotelProviderWalletTransactions $hotelProviderWalletTransactions,
        HotelProviderManager $hotelProviderManager,
    ): int {
        try {
            $payload = $this->loadPayload();
            $booking = $this->normalizeBookingEnvelope($payload);
            $bookingId = $this->resolveBookingId($booking);
            $user = $this->resolveUserFromOption();
            $customer = $this->normalizeCustomer($payload, $booking, $user);
            $user ??= $this->resolveUserFromCustomer($customer);
            $providerWithSource = $hotelProviderManager->activeProviderWithSource();
            $provider = $providerWithSource['provider'] ?? null;
            $providerSource = is_array($providerWithSource['source'] ?? null) ? $providerWithSource['source'] : [];

            if (! $provider instanceof TenantHotelProvider) {
                $this->error('No active hotel provider is configured for this tenant.');

                return self::FAILURE;
            }

            if ($this->bookingAlreadyImported($bookingId)) {
                $this->warn("Booking [{$bookingId}] already exists as a hotel order item. Nothing to do.");

                return self::SUCCESS;
            }

            $selectedOffer = $this->buildSelectedOffer($booking, $provider);
            $rooms = $this->buildRoomsPayload($booking, $customer);
            $search = $this->buildSearchPayload($booking, $customer);
            $providerCost = round((float) ($selectedOffer['provider_price'] ?? 0), 2);
            $currency = strtoupper((string) ($selectedOffer['currency'] ?? 'LYD'));
            $debitWallet = (bool) $this->option('debit-wallet');
            $dryRun = (bool) $this->option('dry-run');

            $this->table(
                ['Field', 'Value'],
                [
                    ['booking_id', $bookingId],
                    ['user_id', (string) $user->id],
                    ['provider', $provider->name.' ('.$provider->provider_type.')'],
                    ['provider_cost', number_format($providerCost, 2).' '.$currency],
                    ['selling_price', number_format((float) $selectedOffer['price'], 2).' '.$currency],
                    ['markup_percent', (string) $selectedOffer['markup_percent']],
                    ['debit_wallet', $debitWallet ? 'yes' : 'no'],
                    ['dry_run', $dryRun ? 'yes' : 'no'],
                ],
            );

            if ($dryRun) {
                $this->info('Dry run complete. No order was created.');

                return self::SUCCESS;
            }

            if ($debitWallet) {
                $hotelProviderWalletTransactions->assertCanWithdrawForSource(
                    $providerSource,
                    $provider,
                    $currency,
                    $providerCost,
                );
            }

            $order = $createOrderFromHotelBooking->create(
                userId: $user->id,
                booking: $booking,
                selectedOffer: $selectedOffer,
                customer: $customer,
                rooms: $rooms,
                search: $search,
                provider: $provider,
                providerSource: $providerSource,
            );

            if ($debitWallet) {
                $hotelProviderWalletTransactions->execute($order, $provider);
            }

            $order->loadMissing('items');
            $item = $order->items->first();

            $this->info("Imported hotel booking [{$bookingId}] as order #{$order->number} (id={$order->id}).");
            if ($item) {
                $this->line("Order item id={$item->id}, provider_reference={$item->provider_reference}, status={$item->status}");
            }
            if ($debitWallet) {
                $this->line('Hotel provider wallet was debited.');
            }

            return self::SUCCESS;
        } catch (InsufficientWalletBalanceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadPayload(): array
    {
        $path = (string) $this->option('payload');

        if ($path === '') {
            throw new \InvalidArgumentException('The --payload= option is required (path to Telescope/book JSON).');
        }

        if (! File::isFile($path)) {
            throw new \InvalidArgumentException("Payload file not found: {$path}");
        }

        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Payload file must contain a JSON object.');
        }

        return $decoded;
    }

    /**
     * Accept raw 3T book body, Telescope-normalized envelope, or { data: { booking: ... } }.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeBookingEnvelope(array $payload): array
    {
        if (is_array($payload['booking'] ?? null) && is_array(data_get($payload, 'booking.response'))) {
            $payload = $payload['booking'];
        }

        if (is_array(data_get($payload, 'data.booking.response'))) {
            $payload = $payload['data']['booking'];
        }

        $response = $payload['response'] ?? null;

        // Raw 3T book body where bookingId is at the top level under "response" already unwrapped.
        if (! is_array($response) && isset($payload['bookingId'])) {
            $response = $payload;
        }

        if (! is_array($response)) {
            throw new \InvalidArgumentException('Payload must include a book response (response.bookingId / totalPurchase / booking).');
        }

        return [
            'method' => (string) ($payload['method'] ?? 'book'),
            'response' => $response,
            'token_for_book' => (string) ($payload['token_for_book'] ?? ''),
            'search_code' => (string) ($payload['search_code'] ?? ''),
            'error' => false,
            'error_code' => $payload['error_code'] ?? 200,
            'message' => (string) ($payload['message'] ?? 'Ok'),
            'raw' => is_array($payload['raw'] ?? null) ? $payload['raw'] : ['response' => $response],
        ];
    }

    /**
     * @param  array<string, mixed>  $booking
     */
    protected function resolveBookingId(array $booking): string
    {
        $fromOption = trim((string) $this->option('booking-id'));
        $fromPayload = (string) data_get($booking, 'response.bookingId', '');

        $bookingId = $fromOption !== '' ? $fromOption : $fromPayload;

        if ($bookingId === '') {
            throw new \InvalidArgumentException('Booking id is required (--booking-id or response.bookingId).');
        }

        if ($fromOption !== '' && $fromPayload !== '' && $fromOption !== $fromPayload) {
            throw new \InvalidArgumentException("Booking id mismatch: --booking-id={$fromOption} vs payload={$fromPayload}.");
        }

        return $bookingId;
    }

    protected function bookingAlreadyImported(string $bookingId): bool
    {
        $itemExists = OrderItem::query()
            ->where(function ($query): void {
                $query->where('type', 'hotel')
                    ->orWhere('product_type', 'hotel');
            })
            ->where(function ($query) use ($bookingId): void {
                $query->where('provider_reference', $bookingId)
                    ->orWhere('item_details->booking_id', $bookingId);
            })
            ->exists();

        if ($itemExists) {
            return true;
        }

        return Order::query()->where('payment_reference', $bookingId)->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $booking
     * @return array<string, string>
     */
    protected function normalizeCustomer(array $payload, array $booking, ?User $user = null): array
    {
        $customer = [];

        if (is_array($payload['customer'] ?? null)) {
            $customer = $payload['customer'];
        } elseif (is_array(data_get($payload, 'request.customer'))) {
            $customer = data_get($payload, 'request.customer');
        }

        [$userFirstName, $userLastName] = $user instanceof User
            ? $this->splitPersonName((string) $user->name)
            : ['', ''];

        $hotel = is_array(data_get($booking, 'response.booking.hotel'))
            ? data_get($booking, 'response.booking.hotel')
            : [];

        $firstName = $this->firstFilledString([
            $this->option('customer-first-name'),
            $customer['first_name'] ?? null,
            $customer['firstName'] ?? null,
            $userFirstName,
        ]);
        $lastName = $this->firstFilledString([
            $this->option('customer-last-name'),
            $customer['last_name'] ?? null,
            $customer['lastName'] ?? null,
            $userLastName !== '' ? $userLastName : $userFirstName,
        ]);
        $email = $this->firstFilledString([
            $this->option('customer-email'),
            $customer['email'] ?? null,
            $user?->email,
        ]);
        $mobile = $this->firstFilledString([
            $this->option('customer-phone'),
            $customer['mobile'] ?? null,
            $customer['phone'] ?? null,
        ]);
        $country = $this->firstFilledString([
            $this->option('customer-country'),
            $customer['country'] ?? null,
            $hotel['countryName'] ?? null,
        ]);
        $city = $this->firstFilledString([
            $this->option('customer-city'),
            $customer['city'] ?? null,
            $hotel['cityName'] ?? null,
        ]);

        if ($firstName === '' || $lastName === '') {
            throw new \InvalidArgumentException(
                'Customer first/last name required. Pass --customer-first-name / --customer-last-name, '
                .'include customer in the JSON, or use --user-id= so the owner name can be used.'
            );
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $mobile,
            'mobile' => $mobile,
            'country' => $country,
            'city' => $city,
            'firstName' => $firstName,
            'lastName' => $lastName,
        ];
    }

    protected function resolveUserFromOption(): ?User
    {
        $userId = $this->option('user-id');

        if (! is_numeric($userId) || (int) $userId <= 0) {
            return null;
        }

        $user = User::query()->find((int) $userId);

        if (! $user instanceof User) {
            throw new \InvalidArgumentException("User id [{$userId}] was not found in this tenant.");
        }

        return $user;
    }

    /**
     * @param  array<string, string>  $customer
     */
    protected function resolveUserFromCustomer(array $customer): User
    {
        $email = (string) ($customer['email'] ?? '');

        if ($email !== '') {
            $user = User::query()->where('email', $email)->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        throw new \InvalidArgumentException('Provide --user-id= or include a customer.email that matches a tenant user.');
    }

    /**
     * @param  list<mixed>  $candidates
     */
    protected function firstFilledString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitPersonName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return ['', ''];
        }

        if (count($parts) === 1) {
            return [$parts[0], $parts[0]];
        }

        return [$parts[0], implode(' ', array_slice($parts, 1))];
    }

    /**
     * @param  array<string, mixed>  $booking
     * @return array<string, mixed>
     */
    protected function buildSelectedOffer(array $booking, TenantHotelProvider $provider): array
    {
        $response = is_array($booking['response'] ?? null) ? $booking['response'] : [];
        $providerBooking = is_array($response['booking'] ?? null) ? $response['booking'] : [];
        $hotel = is_array($providerBooking['hotel'] ?? null) ? $providerBooking['hotel'] : [];
        $rooms = array_values(array_filter(
            is_array($providerBooking['rooms'] ?? null) ? $providerBooking['rooms'] : [],
            fn (mixed $room): bool => is_array($room),
        ));
        $firstRoom = $rooms[0] ?? [];

        $providerPrice = round((float) ($response['totalPurchase'] ?? $firstRoom['price'] ?? 0), 2);
        $markupPercent = max(0.0, $provider->markupForProductType('hotel'));
        $sellingPrice = round($providerPrice + (($providerPrice * $markupPercent) / 100), 2);
        $currency = strtoupper((string) ($response['currency'] ?? $firstRoom['currency'] ?? $provider->currency ?? 'LYD'));

        return [
            'hotel_id' => (string) ($hotel['hotelId'] ?? ''),
            'hotel_uid' => (string) ($hotel['hotelUid'] ?? ''),
            'hotel_name' => (string) ($hotel['hotelName'] ?? 'Hotel'),
            'source' => $hotel['supplierSourceId'] ?? ($response['bookingSource'] ?? null),
            'rate_key' => (string) ($firstRoom['rateKey'] ?? ''),
            'rate_keys' => array_values(array_filter(array_map(
                fn (array $room): string => (string) ($room['rateKey'] ?? ''),
                $rooms,
            ))),
            'room_name' => (string) ($firstRoom['name'] ?? 'Room'),
            'board_name' => (string) ($firstRoom['boardName'] ?? ''),
            'price' => $sellingPrice,
            'provider_price' => $providerPrice,
            'markup_percent' => $markupPercent,
            'markup_amount' => round($sellingPrice - $providerPrice, 2),
            'currency' => $currency,
            'available' => true,
            'cancellation_policies' => $firstRoom['cancellationPolicies'] ?? [],
            'search_code' => '',
            'imported' => true,
            'hotel' => [
                'hotel_id' => (string) ($hotel['hotelId'] ?? ''),
                'hotel_uid' => (string) ($hotel['hotelUid'] ?? ''),
                'name' => (string) ($hotel['hotelName'] ?? 'Hotel'),
            ],
            'room' => $firstRoom,
        ];
    }

    /**
     * @param  array<string, mixed>  $booking
     * @param  array<string, string>  $customer
     * @return array<int, array<string, mixed>>
     */
    protected function buildRoomsPayload(array $booking, array $customer): array
    {
        $providerRooms = array_values(array_filter(
            is_array(data_get($booking, 'response.booking.rooms')) ? data_get($booking, 'response.booking.rooms') : [],
            fn (mixed $room): bool => is_array($room),
        ));

        if ($providerRooms === []) {
            return [[
                'ratekey' => '',
                'evening' => '',
                'supplements' => [],
                'paxes' => [[
                    'civility' => 'Mr',
                    'firstName' => $customer['firstName'],
                    'lastName' => $customer['lastName'],
                ]],
            ]];
        }

        return array_values(array_map(function (array $room) use ($customer): array {
            $paxes = [];

            if (is_array($room['paxes'] ?? null) && array_is_list($room['paxes'])) {
                foreach ($room['paxes'] as $pax) {
                    if (! is_array($pax)) {
                        continue;
                    }

                    $paxes[] = [
                        'civility' => (string) ($pax['civility'] ?? 'Mr'),
                        'firstName' => (string) ($pax['firstName'] ?? $pax['first_name'] ?? $customer['firstName']),
                        'lastName' => (string) ($pax['lastName'] ?? $pax['last_name'] ?? $customer['lastName']),
                    ];
                }
            }

            if ($paxes === []) {
                $adults = max(1, (int) data_get($room, 'paxes.adult', 1));

                for ($i = 0; $i < $adults; $i++) {
                    $paxes[] = [
                        'civility' => 'Mr',
                        'firstName' => $customer['firstName'],
                        'lastName' => $customer['lastName'],
                    ];
                }
            }

            return [
                'ratekey' => (string) ($room['rateKey'] ?? ''),
                'evening' => '',
                'supplements' => [],
                'paxes' => $paxes,
            ];
        }, $providerRooms));
    }

    /**
     * @param  array<string, mixed>  $booking
     * @param  array<string, string>  $customer
     * @return array<string, mixed>
     */
    protected function buildSearchPayload(array $booking, array $customer): array
    {
        $providerBooking = is_array(data_get($booking, 'response.booking')) ? data_get($booking, 'response.booking') : [];
        $hotel = is_array($providerBooking['hotel'] ?? null) ? $providerBooking['hotel'] : [];

        return [
            'city' => (string) ($hotel['cityName'] ?? $customer['city'] ?? ''),
            'city_id' => (int) ($hotel['cityId'] ?? 0),
            'check_in' => (string) ($providerBooking['from'] ?? ''),
            'check_out' => (string) ($providerBooking['to'] ?? ''),
            'language' => 'fr-FR',
            'imported' => true,
        ];
    }
}
