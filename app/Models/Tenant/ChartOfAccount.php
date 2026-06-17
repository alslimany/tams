<?php

namespace App\Models\Tenant;

use Abivia\Ledger\Models\LedgerAccount;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends LedgerAccount
{
    use SoftDeletes;

    protected $table = 'ledger_accounts';
}
