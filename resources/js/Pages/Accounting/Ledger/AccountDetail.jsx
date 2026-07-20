import React from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeftIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import JournalEntrySheet from '@/Components/Accounting/JournalEntrySheet';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';
import { formatAccountingPeriod } from '@/lib/accounting';

const JOURNAL_COLORS = {
    AIR: 'bg-blue-100 text-blue-700 border-blue-200',
    HTL: 'bg-green-100 text-green-700 border-green-200',
    INS: 'bg-orange-100 text-orange-700 border-orange-200',
    ESM: 'bg-purple-100 text-purple-700 border-purple-200',
    STL: 'bg-gray-100 text-gray-700 border-gray-200',
    GEN: 'bg-slate-100 text-slate-700 border-slate-200',
};

export default function AccountDetail({
    account,
    period,
    openingBalance,
    lines,
    totalDebit,
    totalCredit,
    closingBalance,
}) {
    const { t } = useTranslation();
    const [sheetEntryId, setSheetEntryId] = React.useState(null);
    const periodLabel = formatAccountingPeriod(period);

    function applyFilter(key, value) {
        router.get(
            route('accounting.ledger.account', account.code),
            { ...period, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    return (
        <TenantLayout>
            <Head title={`${account.code} — ${account.name}`} />
            <AccountingLayout
                title={`${account.code} — ${account.name}`}
                subtitle={
                    <span className="text-muted-foreground">
                        <span className="capitalize">{account.type}</span>
                        {periodLabel && (
                            <>
                                {' · '}
                                {periodLabel}
                            </>
                        )}
                    </span>
                }
                actions={
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit(route('accounting.ledger.coa'))}
                    >
                        <ArrowLeftIcon className="mr-1 size-3" /> Chart of Accounts
                    </Button>
                }
            >
                {/* Period filter */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <input
                        type="date"
                        value={period.from}
                        onChange={(e) => applyFilter('from', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                    />
                    <input
                        type="date"
                        value={period.to}
                        onChange={(e) => applyFilter('to', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                    />
                </div>

                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead>Journal</TableHead>
                                <TableHead className="text-right">Debit</TableHead>
                                <TableHead className="text-right">Credit</TableHead>
                                <TableHead className="text-right">Balance</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {/* Opening balance */}
                            <TableRow className="bg-muted/30 font-medium">
                                <TableCell className="text-sm text-muted-foreground">{period.from}</TableCell>
                                <TableCell className="text-sm italic text-muted-foreground" colSpan={4}>
                                    Opening Balance
                                </TableCell>
                                <TableCell className="text-right text-sm">
                                    <AmountDisplay amount={openingBalance} colorize />
                                </TableCell>
                            </TableRow>

                            {lines.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-8 text-center text-sm text-muted-foreground"
                                    >
                                        No activity in this period.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                lines.map((line, i) => (
                                    <TableRow key={i}>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {line.date}
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate text-sm">
                                            <button
                                                className="hover:underline"
                                                onClick={() => setSheetEntryId(line.entryReference)}
                                            >
                                                {line.entryDescription || `Entry #${line.entryReference}`}
                                            </button>
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold ${JOURNAL_COLORS[line.journal] ?? JOURNAL_COLORS.GEN}`}
                                            >
                                                {line.journal}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            {line.debit != null ? (
                                                <AmountDisplay amount={line.debit} />
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            {line.credit != null ? (
                                                <AmountDisplay amount={line.credit} />
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right text-sm font-medium">
                                            <AmountDisplay amount={line.runningBalance} colorize />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}

                            {lines.length > 0 && (
                                <TableRow className="border-t font-semibold">
                                    <TableCell colSpan={3} className="text-sm">
                                        Totals
                                    </TableCell>
                                    <TableCell className="text-right text-sm">
                                        <AmountDisplay amount={totalDebit} />
                                    </TableCell>
                                    <TableCell className="text-right text-sm">
                                        <AmountDisplay amount={totalCredit} />
                                    </TableCell>
                                    <TableCell />
                                </TableRow>
                            )}

                            {/* Closing balance */}
                            <TableRow className="border-t-2 bg-muted/30 font-semibold">
                                <TableCell className="text-sm text-muted-foreground">{period.to}</TableCell>
                                <TableCell className="text-sm italic text-muted-foreground" colSpan={4}>
                                    Closing Balance
                                </TableCell>
                                <TableCell className="text-right text-sm">
                                    <AmountDisplay amount={closingBalance} colorize />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </Card>
            </AccountingLayout>

            {sheetEntryId && (
                <JournalEntrySheet
                    entryId={sheetEntryId}
                    open={!!sheetEntryId}
                    onClose={() => setSheetEntryId(null)}
                />
            )}
        </TenantLayout>
    );
}
