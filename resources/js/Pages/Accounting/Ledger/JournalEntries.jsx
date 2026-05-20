import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDownIcon, ChevronRightIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import JournalEntrySheet from '@/Components/Accounting/JournalEntrySheet';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const JOURNAL_COLORS = {
    AIR: 'bg-blue-100 text-blue-700 border-blue-200',
    HTL: 'bg-green-100 text-green-700 border-green-200',
    INS: 'bg-orange-100 text-orange-700 border-orange-200',
    ESM: 'bg-purple-100 text-purple-700 border-purple-200',
    STL: 'bg-gray-100 text-gray-700 border-gray-200',
    GEN: 'bg-slate-100 text-slate-700 border-slate-200',
};

export default function JournalEntries({ entries, filters, journalOptions }) {
    const { t } = useTranslation();
    const [expandedId, setExpandedId] = React.useState(null);
    const [sheetEntryId, setSheetEntryId] = React.useState(null);

    const hasUnbalanced = entries.data.some((e) => !e.isBalanced);

    function applyFilter(key, value) {
        router.get(
            route('accounting.ledger.journal'),
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.journal')} />
            <AccountingLayout
                title={t('accounting.nav.journal')}
                subtitle="Complete double-entry journal log"
            >
                {hasUnbalanced && (
                    <Alert variant="destructive" className="mb-4">
                        <AlertDescription>
                            One or more journal entries are unbalanced. Please investigate immediately.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Filters */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <input
                        type="date"
                        value={filters.dateFrom ?? ''}
                        onChange={(e) => applyFilter('dateFrom', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        placeholder="From"
                    />
                    <input
                        type="date"
                        value={filters.dateTo ?? ''}
                        onChange={(e) => applyFilter('dateTo', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        placeholder="To"
                    />
                    <input
                        type="search"
                        value={filters.search ?? ''}
                        onChange={(e) => applyFilter('search', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        placeholder="Search description or reference…"
                    />
                </div>

                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8" />
                                <TableHead>Date</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead>Journal</TableHead>
                                <TableHead className="text-right">Debit</TableHead>
                                <TableHead className="text-right">Credit</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entries.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-10 text-center text-sm text-muted-foreground">
                                        No journal entries found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                entries.data.map((entry) => (
                                    <React.Fragment key={entry.id}>
                                        <TableRow
                                            className={`cursor-pointer hover:bg-muted/50 ${!entry.isBalanced ? 'bg-red-50 dark:bg-red-950/20' : ''}`}
                                            onClick={() =>
                                                setExpandedId(expandedId === entry.id ? null : entry.id)
                                            }
                                        >
                                            <TableCell className="text-muted-foreground">
                                                {expandedId === entry.id ? (
                                                    <ChevronDownIcon className="size-4" />
                                                ) : (
                                                    <ChevronRightIcon className="size-4" />
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {entry.date}
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate text-sm">
                                                {entry.description || '—'}
                                            </TableCell>
                                            <TableCell>
                                                <span
                                                    className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold ${JOURNAL_COLORS[entry.journal] ?? JOURNAL_COLORS.GEN}`}
                                                >
                                                    {entry.journal}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right text-sm">
                                                <AmountDisplay amount={entry.totalDebit} />
                                            </TableCell>
                                            <TableCell className="text-right text-sm">
                                                <AmountDisplay amount={entry.totalCredit} />
                                            </TableCell>
                                            <TableCell>
                                                {entry.orderReference && (
                                                    <Link
                                                        href={route('orders.show', entry.orderReference)}
                                                        onClick={(e) => e.stopPropagation()}
                                                        className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                                    >
                                                        #{entry.orderReference}
                                                    </Link>
                                                )}
                                            </TableCell>
                                        </TableRow>

                                        {/* Expanded lines */}
                                        {expandedId === entry.id && (
                                            <TableRow className="bg-muted/30">
                                                <TableCell colSpan={7} className="p-0">
                                                    <table className="w-full text-xs">
                                                        <thead>
                                                            <tr className="border-b text-muted-foreground">
                                                                <th className="py-1.5 pl-10 text-left font-medium">
                                                                    Account
                                                                </th>
                                                                <th className="py-1.5 text-right font-medium">
                                                                    Debit
                                                                </th>
                                                                <th className="py-1.5 pr-4 text-right font-medium">
                                                                    Credit
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {entry.lines.map((line, i) => (
                                                                <tr
                                                                    key={i}
                                                                    className={
                                                                        i % 2 === 0 ? '' : 'bg-muted/20'
                                                                    }
                                                                >
                                                                    <td className="py-1 pl-10">
                                                                        <Link
                                                                            href={route(
                                                                                'accounting.ledger.account',
                                                                                line.accountCode,
                                                                            )}
                                                                            className="hover:underline"
                                                                        >
                                                                            <code className="mr-2 text-muted-foreground">
                                                                                {line.accountCode}
                                                                            </code>
                                                                            {line.accountName}
                                                                        </Link>
                                                                    </td>
                                                                    <td className="py-1 text-right">
                                                                        {line.debit != null ? (
                                                                            <AmountDisplay amount={line.debit} />
                                                                        ) : (
                                                                            '—'
                                                                        )}
                                                                    </td>
                                                                    <td className="py-1 pr-4 text-right">
                                                                        {line.credit != null ? (
                                                                            <AmountDisplay amount={line.credit} />
                                                                        ) : (
                                                                            '—'
                                                                        )}
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </React.Fragment>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    {entries.last_page > 1 && (
                        <div className="flex items-center justify-between border-t px-4 py-3">
                            <p className="text-xs text-muted-foreground">
                                Page {entries.current_page} of {entries.last_page} · {entries.total} entries
                            </p>
                            <div className="flex gap-1">
                                {entries.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        className="h-7 min-w-7 px-2 text-xs"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url && router.get(link.url, {}, { preserveState: true })
                                        }
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
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
