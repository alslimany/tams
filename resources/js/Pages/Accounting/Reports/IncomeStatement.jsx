import React from 'react';
import { Head, router } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

function Section({ title, accounts, total, totalLabel }) {
    return (
        <>
            <TableRow className="bg-muted/40">
                <TableCell colSpan={2} className="py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    {title}
                </TableCell>
            </TableRow>
            {accounts.length === 0 && (
                <TableRow>
                    <TableCell colSpan={2} className="py-3 text-center text-xs text-muted-foreground">
                        (none)
                    </TableCell>
                </TableRow>
            )}
            {accounts.map((account) => (
                <TableRow key={account.code}>
                    <TableCell className="text-sm">
                        <code className="mr-2 text-xs text-muted-foreground">{account.code}</code>
                        {account.name}
                    </TableCell>
                    <TableCell className="text-right text-sm">
                        <AmountDisplay amount={account.amount} colorize />
                    </TableCell>
                </TableRow>
            ))}
            <TableRow className="font-semibold">
                <TableCell className="text-sm">{totalLabel ?? `Total ${title}`}</TableCell>
                <TableCell className="text-right text-sm">
                    <AmountDisplay amount={total} colorize />
                </TableCell>
            </TableRow>
        </>
    );
}

export default function IncomeStatement({ period, revenue, cogs, grossProfit, grossMargin, purchases, opex, netProfit }) {
    const { t } = useTranslation();

    function applyPeriod(next) {
        router.get(
            route('accounting.reports.income-statement'),
            { from: next.from ?? period.from, to: next.to ?? period.to },
            { preserveState: true },
        );
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.income_statement')} />
            <AccountingLayout
                title={t('accounting.nav.income_statement')}
                subtitle={`Period: ${period.from} → ${period.to}`}
                actions={
                    <div className="flex items-center gap-2">
                        <input
                            type="date"
                            value={period.from}
                            onChange={(e) => applyPeriod({ from: e.target.value })}
                            className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        />
                        <input
                            type="date"
                            value={period.to}
                            onChange={(e) => applyPeriod({ to: e.target.value })}
                            className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        />
                        <Button variant="outline" size="sm" onClick={() => window.print()}>
                            <PrinterIcon className="mr-1.5 size-3.5" /> Print
                        </Button>
                    </div>
                }
            >
                <Card className="print:shadow-none">
                    <Table>
                        <TableBody>
                            <Section title="Revenue" accounts={revenue.accounts} total={revenue.total} />
                            <Section title="Cost of Sales" accounts={cogs.accounts} total={cogs.total} />

                            <TableRow className="border-t-2 font-bold">
                                <TableCell className="text-sm">
                                    GROSS PROFIT
                                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                                        ({grossMargin}%)
                                    </span>
                                </TableCell>
                                <TableCell className="text-right text-sm">
                                    <AmountDisplay amount={grossProfit} colorize />
                                </TableCell>
                            </TableRow>

                            <Section title="Purchases" accounts={purchases.accounts} total={purchases.total} />
                            <Section
                                title="Operating Expenses"
                                accounts={opex.accounts}
                                total={opex.total}
                            />

                            <TableRow className="border-t-2 font-bold">
                                <TableCell className="text-sm">NET PROFIT</TableCell>
                                <TableCell className="text-right text-sm">
                                    <AmountDisplay amount={netProfit} colorize />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
