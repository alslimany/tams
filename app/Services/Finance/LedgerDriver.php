<?php

namespace App\Services\Finance;

interface LedgerDriver
{
    /**
     * @param  array<int, array{account:string, direction:string, amount:float}>  $entries
     */
    public function postOperationJournal(string $source, string $description, array $entries): int;
}
