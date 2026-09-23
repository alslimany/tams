<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Airline\RoundTripOfferSearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRoundTripSearchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 200;

    public function __construct(
        public string $tenantId,
        public string $searchUuid,
    ) {}

    public function uniqueId(): string
    {
        return $this->searchUuid;
    }

    public function handle(RoundTripOfferSearchService $roundTripOfferSearchService): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $tenant->run(function () use ($roundTripOfferSearchService): void {
            $roundTripOfferSearchService->processSession($this->searchUuid);
        });
    }
}
