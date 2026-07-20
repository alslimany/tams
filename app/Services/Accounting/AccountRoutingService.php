<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountRoutingService
{
    /** @var array<string, array{debit: ?string, credit: ?string}> */
    private array $cache = [];

    /**
     * Get the debit and credit account codes for a given accounting event.
     *
     * @return array{debit: ?string, credit: ?string}
     *
     * @throws RuntimeException When no active routing exists for the event.
     */
    public function resolve(string $eventType, string $category): array
    {
        $key = "{$eventType}:{$category}";

        if (! isset($this->cache[$key])) {
            $routing = DB::table('account_routing')
                ->where('event_type', $eventType)
                ->where('event_category', $category)
                ->where('is_active', true)
                ->first();

            if (! $routing) {
                throw new RuntimeException(
                    "No account routing found for event [{$eventType}] category [{$category}]. "
                    .'Please configure it in Accounting → Settings → Account Routing.'
                );
            }

            $this->cache[$key] = [
                'debit' => $routing->debit_account,
                'credit' => $routing->credit_account,
            ];
        }

        return $this->cache[$key];
    }

    /**
     * Resolve the debit account code for an event, failing if the routing has none.
     */
    public function debitAccount(string $eventType, string $category): string
    {
        $account = $this->resolve($eventType, $category)['debit'];

        if ($account === null || $account === '') {
            throw new RuntimeException(
                "Account routing for event [{$eventType}] category [{$category}] has no debit account configured."
            );
        }

        return $account;
    }

    /**
     * Resolve the credit account code for an event, failing if the routing has none.
     */
    public function creditAccount(string $eventType, string $category): string
    {
        $account = $this->resolve($eventType, $category)['credit'];

        if ($account === null || $account === '') {
            throw new RuntimeException(
                "Account routing for event [{$eventType}] category [{$category}] has no credit account configured."
            );
        }

        return $account;
    }

    /**
     * Clear the per-request cache (used after routing rows change).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
