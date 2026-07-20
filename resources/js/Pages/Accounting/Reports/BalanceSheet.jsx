import React from 'react';
import { Head, router } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

function Section({ title, accounts, total, extraRows = [] }) {
    return (
        <>
            <TableRow className="bg-muted/40">
                <TableCell colSpan={2} className="py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    {title}
                </TableCell>
            </TableRow>
            {accounts.length === 0 && extraRows.length === 0 && (
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
                        <AmountDisplay amount={account.balance} colorize />
                    </TableCell>
                </TableRow>
            ))}
            {extraRows.map((row) => (
                <TableRow key={row.label}>
                    <TableCell className="text-sm italic text-muted-foreground">{row.label}</TableCell>
                    <TableCell className="text-right text-sm">
                        <AmountDisplay amount={row.amount} colorize />
                    </TableCell>
                </TableRow>
            ))}
            <TableRow className="font-semibold">
                <TableCell className="text-sm">Total {title}</TableCell>
                <TableCell className="text-right text-sm">
                    <AmountDisplay amount={total} colorize />
                </TableCell>
            </TableRow>
        </>
    );
}

export default function BalanceSheet({ asAtDate, assets, liabilities, equity, totals, isBalanced }) {
    const { t } = useTranslation();

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.balance_sheet')} />
            <AccountingLayout
                title={t('accounting.nav.balance_sheet')}
                subtitle={`As at ${asAtDate}`}
                actions={
                    <div className="flex items-center gap-2">
                        <input
                            type="date"
                            value={asAtDate}
                            onChange={(e) =>
                                router.get(
                                    route('accounting.reports.balance-sheet'),
                                    { asOf: e.target.value },
                                    { preserveState: true },
                                )
                            }
                            className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        />
                        <Button variant="outline" size="sm" onClick={() => window.print()}>
                            <PrinterIcon className="mr-1.5 size-3.5" /> Print
                        </Button>
                        {isBalanced ? (
                            <Badge variant="success">Balanced</Badge>
                        ) : (
                            <Badge variant="destructive">Imbalance</Badge>
                        )}
                    </div>
                }
            >
                {!isBalanced && (
                    <Alert variant="destructive" className="mb-4">
                        <AlertDescription>
                            Assets do not equal liabilities plus equity. Please investigate unbalanced
                            journal entries.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="print:shadow-none">
                        <Table>
                            <TableBody>
                                <Section title="Assets" accounts={assets.accounts} total={assets.total} />
                            </TableBody>
                        </Table>
                    </Card>

                    <Card className="print:shadow-none">
                        <Table>
                            <TableBody>
                                <Section
                                    title="Liabilities"
                                    accounts={liabilities.accounts}
                                    total={liabilities.total}
                                />
                                <Section
                                    title="Equity"
                                    accounts={equity.accounts}
                                    total={equity.total}
                                    extraRows={[
                                        {
                                            label: 'Current Period Profit/Loss (calculated)',
                                            amount: equity.calculatedProfit,
                                        },
                                    ]}
                                />
                                <TableRow className="border-t-2 font-bold">
                                    <TableCell className="text-sm">TOTAL LIABILITIES + EQUITY</TableCell>
                                    <TableCell className="text-right text-sm">
                                        <AmountDisplay amount={totals.liabilities_and_equity} colorize />
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </Card>
                </div>

                <Card className="mt-6 flex items-center justify-between px-4 py-3 print:shadow-none">
                    <span className="text-sm font-semibold">TOTAL ASSETS</span>
                    <span className="text-sm font-bold">
                        <AmountDisplay amount={totals.assets} colorize />
                    </span>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
