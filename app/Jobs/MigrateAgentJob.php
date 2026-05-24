<?php

namespace App\Jobs;

use App\Models\MigrationRecord;
use App\Services\Migration\AgentMigrationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly int $legacyAgentId,
        public readonly string $adminUserId,
        public readonly array $options,
        public readonly int $recordId,
    ) {
        $this->onQueue('migrations');
    }

    public function handle(AgentMigrationPipeline $pipeline): void
    {
        $pipeline->run($this->legacyAgentId, $this->adminUserId, $this->options, $this->recordId);
    }

    public function failed(\Throwable $e): void
    {
        MigrationRecord::where('id', $this->recordId)
            ->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
    }
}
