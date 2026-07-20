import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import AccountCombobox from '@/Components/Accounting/AccountCombobox';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function GeneralLedger({ period, accounts, accountOptions, selectedAccount }) {
    const { t } = useTranslation();

    function applyFilters(next) {
        router.get(
            route('accounting.reports.general-ledger'),
            {
                from: next.from ?? period.from,
                to: next.to ?? period.to,
                account: next.account !== undefined ? next.account : (selectedAccount ?? ''),
            },
            { preserveState: true },
        );
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.general_ledger')} />
            <AccountingLayout
                title={t('accounting.nav.general_ledger')}
                subtitle={`Period: ${period.from} → ${period.to}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <AccountCombobox
                            value={selectedAccount ?? ''}
                            onChange={(code) => applyFilters({ account: code })}
                            accounts={accountOptions}
                            placeholder="All accounts"
                            className="w-56"
                        />
                        {selectedAccount && (
                            <Button variant="ghost" size="sm" onClick={() => applyFilters({ account: '' })}>
                                Clear
                            </Button>
                        )}
                        <input
                            type="date"
                            value={period.from}
                            onChange={(e) => applyFilters({ from: e.target.value })}
                            className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        />
                        <input
                            type="date"
                            value={period.to}
                            onChange={(e) => applyFilters({ to: e.target.value })}
                            className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        />
                        <Button variant="outline" size="sm" onClick={() => window.print()}>
                            <PrinterIcon className="mr-1.5 size-3.5" /> Print
                        </Button>
                    </div>
                }
            >
                {accounts.length === 0 ? (
                    <Card className="p-10 text-center text-sm text-muted-foreground">
                        No account activity in the selected period.
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {accounts.map((account) => (
                            <Card key={account.code} className="print:shadow-none">
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                                    <div>
                                        <span className="text-sm font-semibold">
                                            <code className="mr-2 text-xs text-muted-foreground">{account.code}</code>
                                            {account.name}
                                        </span>
                                        <span className="ml-2 text-xs capitalize text-muted-foreground">
                                            {account.type}
                                        </span>
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Opening Balance:{' '}
                                        <span className="font-medium text-foreground">
                                            <AmountDisplay amount={account.openingBalance} colorize />
                                        </span>
                                    </div>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[110px]">Date</TableHead>
                                            <TableHead>Description</TableHead>
                                            <TableHead className="w-[80px]">Journal</TableHead>
                                            <TableHead className="w-[70px]">Entry</TableHead>
                                            <TableHead className="w-[120px] text-right">Debit</TableHead>
                                            <TableHead className="w-[120px] text-right">Credit</TableHead>
                                            <TableHead className="w-[130px] text-right">Balance</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {account.lines.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={7}
                                                    className="py-6 text-center text-xs text-muted-foreground"
                                                >
                                                    No movements this period.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            account.lines.map((line, index) => (
                                                <TableRow key={`${account.code}-${index}`}>
                                                    <TableCell className="text-xs">{line.date}</TableCell>
                                                    <TableCell className="max-w-[280px] truncate text-xs">
                                                        {line.description}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {line.journal}
                                                    </TableCell>
                                                    <TableCell className="text-xs">
                                                        <Link
                                                            href={route('accounting.ledger.journal.show', line.entryId)}
                                                            className="text-primary hover:underline"
                                                        >
                                                            #{line.entryId}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs">
                                                        {line.debit != null ? <AmountDisplay amount={line.debit} /> : '—'}
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs">
                                                        {line.credit != null ? <AmountDisplay amount={line.credit} /> : '—'}
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs">
                                                        <AmountDisplay amount={line.runningBalance} colorize />
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                        <TableRow className="border-t-2 font-semibold">
                                            <TableCell colSpan={4} className="text-xs">
                                                Total / Closing Balance
                                            </TableCell>
                                            <TableCell className="text-right text-xs">
                                                <AmountDisplay amount={account.totalDebit} />
                                            </TableCell>
                                            <TableCell className="text-right text-xs">
                                                <AmountDisplay amount={account.totalCredit} />
                                            </TableCell>
                                            <TableCell className="text-right text-xs">
                                                <AmountDisplay amount={account.closingBalance} colorize />
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </Card>
                        ))}
                    </div>
                )}
            </AccountingLayout>
        </TenantLayout>
    );
}
