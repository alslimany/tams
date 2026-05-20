import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const TYPE_COLORS = {
    asset: 'bg-blue-100 text-blue-700 border-blue-200',
    liability: 'bg-red-100 text-red-700 border-red-200',
    equity: 'bg-purple-100 text-purple-700 border-purple-200',
    revenue: 'bg-green-100 text-green-700 border-green-200',
    expense: 'bg-orange-100 text-orange-700 border-orange-200',
};

const TYPE_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense'];
const TYPE_LABELS = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

export default function ChartOfAccounts({ accounts }) {
    const { t } = useTranslation();

    const grouped = TYPE_ORDER.reduce((acc, type) => {
        acc[type] = accounts.filter((a) => a.type === type);
        return acc;
    }, {});

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.coa')} />
            <AccountingLayout
                title={t('accounting.nav.coa')}
                subtitle="All ledger accounts and current balances"
            >
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Account Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">Balance</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {accounts.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-10 text-center text-sm text-muted-foreground"
                                    >
                                        No accounts found. Ledger may not be bootstrapped yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                TYPE_ORDER.map((type) => {
                                    const typeRows = grouped[type] ?? [];
                                    if (typeRows.length === 0) return null;
                                    return (
                                        <React.Fragment key={type}>
                                            <TableRow className="bg-muted/40">
                                                <TableCell
                                                    colSpan={5}
                                                    className="py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                                                >
                                                    {TYPE_LABELS[type]}
                                                </TableCell>
                                            </TableRow>
                                            {typeRows.map((account) => (
                                                <TableRow key={account.code}>
                                                    <TableCell>
                                                        <code className="text-xs text-muted-foreground">
                                                            {account.code}
                                                        </code>
                                                    </TableCell>
                                                    <TableCell className="text-sm font-medium">
                                                        {account.name}
                                                    </TableCell>
                                                    <TableCell>
                                                        <span
                                                            className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold capitalize ${TYPE_COLORS[account.type] ?? ''}`}
                                                        >
                                                            {account.type}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="text-right text-sm">
                                                        <AmountDisplay amount={account.balance} colorize />
                                                    </TableCell>
                                                    <TableCell>
                                                        <Link
                                                            href={route(
                                                                'accounting.ledger.account',
                                                                account.code,
                                                            )}
                                                            className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                                        >
                                                            View activity →
                                                        </Link>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </React.Fragment>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
