<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Airline\RoundTripOfferSearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRoundTripSearchChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public string $tenantId,
        public string $searchUuid,
        public int $outboundProviderId,
    ) {}

    public function handle(RoundTripOfferSearchService $roundTripOfferSearchService): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $tenant->run(function () use ($roundTripOfferSearchService): void {
            $roundTripOfferSearchService->processOutboundChunk(
                $this->searchUuid,
                $this->outboundProviderId,
            );
        });
    }
}
