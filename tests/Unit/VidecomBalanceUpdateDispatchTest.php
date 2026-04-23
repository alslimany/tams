<?php

use App\Jobs\UpdateAirlineBalanceJob;
use App\Services\Airline\Videcom\Airlines\GlobalAirline;
use App\Services\Airline\Videcom\VidecomClient;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

test('change operation dispatches delayed airline balance update job', function () {
    Queue::fake();

    $client = new class extends VidecomClient
    {
        public function __construct() {}

        public function runCommand(string $command): string
        {
            return '<PNR RLOC="ABC123"></PNR>';
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test', 'tenant_provider_id' => 99]) extends GlobalAirline {};

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $provider->change('ABC123', []);

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job): bool {
        if ($job->tenantProviderId !== 99) {
            return false;
        }

        if (! $job->delay instanceof \DateTimeInterface) {
            return false;
        }

        $seconds = $job->delay->getTimestamp() - now()->getTimestamp();

        return $seconds >= 590 && $seconds <= 610;
    });
});
