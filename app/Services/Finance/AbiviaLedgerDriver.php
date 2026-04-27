<?php

namespace App\Services\Finance;

use Abivia\Ledger\Http\Controllers\JournalEntryController;
use Abivia\Ledger\Messages\Entry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;
use RuntimeException;

class AbiviaLedgerDriver implements LedgerDriver
{
    /**
     * @param  array<int, array{account:string, direction:string, amount:float}>  $entries
     */
    public function postOperationJournal(string $source, string $description, array $entries): int
    {
        if (! class_exists(Entry::class) || ! class_exists(JournalEntryController::class)) {
            throw new RuntimeException('abivia/ledger package is not installed or not autoloadable.');
        }

        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        try {
            $details = [];
            $debitCount = 0;
            $creditCount = 0;

            foreach ($entries as $entryData) {
                $amount = (string) abs((float) $entryData['amount']);
                if ($entryData['direction'] === 'debit') {
                    $debitCount++;
                    $details[] = [
                        'code' => $entryData['account'],
                        'debit' => $amount,
                    ];
                } elseif ($entryData['direction'] === 'credit') {
                    $creditCount++;
                    $details[] = [
                        'code' => $entryData['account'],
                        'credit' => $amount,
                    ];
                } else {
                    throw new RuntimeException('Unsupported journal direction: '.$entryData['direction']);
                }
            }

            if ($details === []) {
                throw new RuntimeException('Cannot post an empty journal to ledger.');
            }

            $message = Entry::fromArray([
                'description' => $description,
                'clearing' => $debitCount > 1 && $creditCount > 1,
                'details' => $details,
                'extra' => json_encode(['source' => $source], JSON_THROW_ON_ERROR),
                'transDate' => Carbon::now()->toDateTimeString(),
            ]);

            $result = (new JournalEntryController)->add($message);

            if (isset($result->journalEntryId) && is_numeric($result->journalEntryId)) {
                return (int) $result->journalEntryId;
            }

            if (is_object($result) && isset($result->id) && is_numeric($result->id)) {
                return (int) $result->id;
            }

            throw new RuntimeException('Unable to resolve posted journal ID from ledger response.');
        } finally {
            Date::use($originalDateClass);
        }
    }
}
