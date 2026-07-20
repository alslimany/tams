/**
 * Build an account detail URL, optionally preserving a reporting period.
 *
 * @param {string} accountCode
 * @param {{ from?: string, to?: string }} [period]
 */
export function accountDetailHref(accountCode, period = {}) {
    const params = { code: accountCode };

    if (period.from) {
        params.from = period.from;
    }

    if (period.to) {
        params.to = period.to;
    }

    return route('accounting.ledger.account', params);
}

/**
 * @param {{ from?: string|null, to?: string|null, asOf?: string|null }} period
 */
export function formatTrialBalanceSubtitle(period) {
    if (period.asOf) {
        return `Closing balances as of ${period.asOf}`;
    }

    if (period.to) {
        return `Closing balances as of ${period.to}`;
    }

    return null;
}

/**
 * @param {{ from?: string|null, to?: string|null }} period
 */
export function formatAccountingPeriod(period) {
    if (period.from && period.to) {
        return `${period.from} — ${period.to}`;
    }

    if (period.from) {
        return `From ${period.from}`;
    }

    if (period.to) {
        return `Through ${period.to}`;
    }

    return null;
}
