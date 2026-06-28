import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDownIcon, ChevronRightIcon, Plus, Pencil, Eye, Trash2, FileText } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import JournalEntryFormDialog from '@/Components/Accounting/JournalEntryFormDialog';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/Dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/Table';
import { Skeleton } from '@/Components/ui/skeleton';
import { useTranslation } from '@/hooks/useTranslation';

const JOURNAL_COLORS = {
    AIR: 'bg-blue-100 text-blue-700 border-blue-200',
    HTL: 'bg-green-100 text-green-700 border-green-200',
    INS: 'bg-orange-100 text-orange-700 border-orange-200',
    ESM: 'bg-purple-100 text-purple-700 border-purple-200',
    STL: 'bg-gray-100 text-gray-700 border-gray-200',
    GEN: 'bg-slate-100 text-slate-700 border-slate-200',
};

const JOURNAL_COLORS_DARK = {
    AIR: 'bg-blue-100 text-blue-800',
    HTL: 'bg-green-100 text-green-800',
    INS: 'bg-orange-100 text-orange-800',
    ESM: 'bg-purple-100 text-purple-800',
    STL: 'bg-gray-100 text-gray-800',
    GEN: 'bg-slate-100 text-slate-800',
};

// ─── Journal Entry View Dialog ─────────────────────────────────────────────

function JournalEntryViewDialog({ open, onClose, entryId }) {
    const [entry, setEntry] = React.useState(null);
    const [loading, setLoading] = React.useState(false);
    const [error, setError] = React.useState('');

    React.useEffect(() => {
        if (open && entryId) {
            setLoading(true);
            setError('');
            setEntry(null);

            fetch(route('accounting.ledger.journal.show', entryId), {
                headers: { Accept: 'application/json' },
            })
                .then((res) => {
                    if (!res.ok) {
                        throw new Error('Failed to load journal entry.');
                    }
                    return res.json();
                })
                .then((data) => {
                    setEntry(data);
                    setLoading(false);
                })
                .catch((err) => {
                    setError(err.message);
                    setLoading(false);
                });
        }
    }, [open, entryId]);

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        Journal Entry{entryId ? ` #${entryId}` : ''}
                    </DialogTitle>
                    <DialogDescription>
                        {entry ? entry.description : 'Loading entry details…'}
                    </DialogDescription>
                </DialogHeader>

                {loading && (
                    <div className="mt-4 space-y-3">
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-32 w-full" />
                    </div>
                )}

                {!loading && error && (
                    <Alert variant="destructive" className="mt-4">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {!loading && !error && !entry && (
                    <p className="mt-4 text-sm text-muted-foreground">
                        No entry found.
                    </p>
                )}

                {!loading && !error && entry && (
                    <div className="mt-4 space-y-4">
                        <div className="flex items-center gap-2 flex-wrap">
                            <Badge
                                className={
                                    JOURNAL_COLORS_DARK[entry.journal] ??
                                    'bg-gray-100 text-gray-800'
                                }
                            >
                                {entry.journal}
                            </Badge>
                            {!entry.isBalanced && (
                                <Badge variant="destructive">Unbalanced</Badge>
                            )}
                        </div>

                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Date</dt>
                            <dd>{entry.date}</dd>
                            <dt className="text-muted-foreground">
                                Reference
                            </dt>
                            <dd className="font-mono text-xs">
                                {entry.reference || '—'}
                            </dd>
                            <dt className="text-muted-foreground col-span-2 mt-1">
                                Description
                            </dt>
                            <dd className="col-span-2">
                                {entry.description || '—'}
                            </dd>
                            {entry.attachment && (
                                <>
                                    <dt className="text-muted-foreground col-span-2 mt-1">
                                        Attachment
                                    </dt>
                                    <dd className="col-span-2">
                                        <a
                                            href={entry.attachment.download_url}
                                            className="inline-flex items-center gap-2 text-sm text-primary hover:underline"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <FileText className="size-4 shrink-0" />
                                            {entry.attachment.original_name}
                                        </a>
                                    </dd>
                                </>
                            )}
                        </dl>

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Account</TableHead>
                                    <TableHead className="text-right">
                                        Debit
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Credit
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entry.lines.map((line, i) => (
                                    <TableRow key={i}>
                                        <TableCell>
                                            <span className="font-mono text-xs text-muted-foreground mr-2">
                                                {line.accountCode}
                                            </span>
                                            {line.accountName}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {line.debit != null ? (
                                                <AmountDisplay
                                                    amount={line.debit}
                                                    currency=""
                                                    decimals={3}
                                                />
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {line.credit != null ? (
                                                <AmountDisplay
                                                    amount={line.credit}
                                                    currency=""
                                                    decimals={3}
                                                />
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow className="font-semibold border-t-2">
                                    <TableCell>Totals</TableCell>
                                    <TableCell className="text-right">
                                        <AmountDisplay
                                            amount={entry.totalDebit}
                                            currency=""
                                            decimals={3}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <AmountDisplay
                                            amount={entry.totalCredit}
                                            currency=""
                                            decimals={3}
                                        />
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

// ─── Main Page Component ───────────────────────────────────────────────────

export default function JournalEntries({
    entries,
    filters,
    journalOptions,
    accounts,
}) {
    const { t } = useTranslation();
    const [expandedId, setExpandedId] = React.useState(null);

    // Sheet (create/edit form) state
    const [sheetOpen, setSheetOpen] = React.useState(false);
    const [sheetMode, setSheetMode] = React.useState('create');
    const [sheetEntry, setSheetEntry] = React.useState(null);
    const [sheetEntryId, setSheetEntryId] = React.useState(null);

    // Dialog (view) state
    const [dialogOpen, setDialogOpen] = React.useState(false);
    const [viewEntryId, setViewEntryId] = React.useState(null);

    const hasUnbalanced = entries.data.some((e) => !e.isBalanced);

    function applyFilter(key, value) {
        router.get(
            route('accounting.ledger.journal'),
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    function openCreateSheet() {
        setSheetMode('create');
        setSheetEntry(null);
        setSheetEntryId(null);
        setSheetOpen(true);
    }

    function openEditSheet(entry) {
        setSheetMode('edit');
        setSheetEntry(entry);
        setSheetEntryId(entry.id);
        setSheetOpen(true);
    }

    function closeSheet() {
        setSheetOpen(false);
    }

    function openViewDialog(id) {
        setViewEntryId(id);
        setDialogOpen(true);
    }

    function closeDialog() {
        setDialogOpen(false);
        setViewEntryId(null);
    }

    function handleDelete(entry) {
        if (
            !window.confirm(
                `Delete journal entry #${entry.id}? This reverses its balance impact and cannot be undone.`,
            )
        ) {
            return;
        }
        router.delete(route('accounting.ledger.journal.destroy', entry.id), {
            preserveScroll: true,
        });
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.journal')} />
            <AccountingLayout
                title={t('accounting.nav.journal')}
                subtitle="Complete double-entry journal log"
                actions={
                    <Button onClick={openCreateSheet}>
                        <Plus className="size-4 mr-1" />
                        New Journal Entry
                    </Button>
                }
            >
                {hasUnbalanced && (
                    <Alert variant="destructive" className="mb-4">
                        <AlertDescription>
                            One or more journal entries are unbalanced. Please
                            investigate immediately.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Filters */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <input
                        type="date"
                        value={filters.dateFrom ?? ''}
                        onChange={(e) =>
                            applyFilter('dateFrom', e.target.value)
                        }
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        placeholder="From"
                    />
                    <input
                        type="date"
                        value={filters.dateTo ?? ''}
                        onChange={(e) =>
                            applyFilter('dateTo', e.target.value)
                        }
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        placeholder="To"
                    />
                    <input
                        type="search"
                        value={filters.search ?? ''}
                        onChange={(e) =>
                            applyFilter('search', e.target.value)
                        }
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
                                <TableHead className="text-right">
                                    Debit
                                </TableHead>
                                <TableHead className="text-right">
                                    Credit
                                </TableHead>
                                <TableHead className="w-[100px]">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entries.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-10 text-center text-sm text-muted-foreground"
                                    >
                                        No journal entries found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                entries.data.map((entry) => (
                                    <React.Fragment key={entry.id}>
                                        <TableRow
                                            className={`cursor-pointer hover:bg-muted/50 ${
                                                !entry.isBalanced
                                                    ? 'bg-red-50 dark:bg-red-950/20'
                                                    : ''
                                            }`}
                                        >
                                            <TableCell
                                                className="text-muted-foreground"
                                                onClick={() =>
                                                    setExpandedId(
                                                        expandedId === entry.id
                                                            ? null
                                                            : entry.id,
                                                    )
                                                }
                                            >
                                                {expandedId === entry.id ? (
                                                    <ChevronDownIcon className="size-4" />
                                                ) : (
                                                    <ChevronRightIcon className="size-4" />
                                                )}
                                            </TableCell>
                                            <TableCell
                                                className="text-sm text-muted-foreground"
                                                onClick={() =>
                                                    setExpandedId(
                                                        expandedId === entry.id
                                                            ? null
                                                            : entry.id,
                                                    )
                                                }
                                            >
                                                {entry.date}
                                            </TableCell>
                                            <TableCell
                                                className="max-w-xs truncate text-sm"
                                                onClick={() =>
                                                    setExpandedId(
                                                        expandedId === entry.id
                                                            ? null
                                                            : entry.id,
                                                    )
                                                }
                                            >
                                                {entry.description || '—'}
                                            </TableCell>
                                            <TableCell
                                                onClick={() =>
                                                    setExpandedId(
                                                        expandedId === entry.id
                                                            ? null
                                                            : entry.id,
                                                    )
                                                }
                                            >
                                                <span
                                                    className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold ${
                                                        JOURNAL_COLORS[
                                                            entry.journal
                                                        ] ?? JOURNAL_COLORS.GEN
                                                    }`}
                                                >
                                                    {entry.journal}
                                                </span>
                                            </TableCell>
                                            <TableCell
                                                className="text-right text-sm"
                                                onClick={() =>
                                                    setExpandedId(
                                                        expandedId === entry.id
                                                            ? null
                                                            : entry.id,
                                                    )
                                                }
                                            >
                                                <AmountDisplay
                                                    amount={entry.totalDebit}
                                                />
                                            </TableCell>
                                            <TableCell
                                                className="text-right text-sm"
                                                onClick={() =>
                                                    setExpandedId(
                                                        expandedId === entry.id
                                                            ? null
                                                            : entry.id,
                                                    )
                                                }
                                            >
                                                <AmountDisplay
                                                    amount={entry.totalCredit}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div
                                                    className="flex items-center gap-1"
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-xs"
                                                        onClick={() =>
                                                            openViewDialog(
                                                                entry.id,
                                                            )
                                                        }
                                                        title="View entry"
                                                    >
                                                        <Eye className="size-3.5" />
                                                    </Button>
                                                    {entry.isManual &&
                                                        !entry.isLocked && (
                                                            <>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon-xs"
                                                                    onClick={() =>
                                                                        openEditSheet(
                                                                            entry,
                                                                        )
                                                                    }
                                                                    title="Edit entry"
                                                                >
                                                                    <Pencil className="size-3.5" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon-xs"
                                                                    className="text-muted-foreground hover:text-destructive"
                                                                    onClick={() =>
                                                                        handleDelete(
                                                                            entry,
                                                                        )
                                                                    }
                                                                    title="Delete entry"
                                                                >
                                                                    <Trash2 className="size-3.5" />
                                                                </Button>
                                                            </>
                                                        )}
                                                    {entry.orderReference && (
                                                        <Link
                                                            href={route(
                                                                'orders.show',
                                                                entry
                                                                    .orderReference,
                                                            )}
                                                            className="text-xs text-muted-foreground hover:text-foreground hover:underline ml-1"
                                                        >
                                                            #
                                                            {
                                                                entry.orderReference
                                                            }
                                                        </Link>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>

                                        {/* Expanded detail lines */}
                                        {expandedId === entry.id && (
                                            <TableRow className="bg-muted/30">
                                                <TableCell
                                                    colSpan={8}
                                                    className="p-0"
                                                >
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
                                                            {entry.lines.map(
                                                                (line, i) => (
                                                                    <tr
                                                                        key={i}
                                                                        className={
                                                                            i %
                                                                                2 ===
                                                                            0
                                                                                ? ''
                                                                                : 'bg-muted/20'
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
                                                                                    {
                                                                                        line.accountCode
                                                                                    }
                                                                                </code>
                                                                                {
                                                                                    line.accountName
                                                                                }
                                                                            </Link>
                                                                        </td>
                                                                        <td className="py-1 text-right">
                                                                                {line.debit !=
                                                                                null ? (
                                                                                    <AmountDisplay amount={
                                                                                        line.debit
                                                                                    } />
                                                                                ) : (
                                                                                    '—'
                                                                                )}
                                                                        </td>
                                                                        <td className="py-1 pr-4 text-right">
                                                                                {line.credit !=
                                                                                null ? (
                                                                                    <AmountDisplay amount={
                                                                                        line.credit
                                                                                    } />
                                                                                ) : (
                                                                                    '—'
                                                                                )}
                                                                        </td>
                                                                    </tr>
                                                                ),
                                                            )}
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
                                Page {entries.current_page} of{' '}
                                {entries.last_page} · {entries.total} entries
                            </p>
                            <div className="flex gap-1">
                                {entries.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        className="h-7 min-w-7 px-2 text-xs"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(link.url, {}, {
                                                preserveState: true,
                                            })
                                        }
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </Card>
            </AccountingLayout>

            {/* Create / Edit Dialog */}
            <JournalEntryFormDialog
                open={sheetOpen}
                onClose={closeSheet}
                mode={sheetMode}
                entry={sheetEntry}
                formEntryId={sheetEntryId}
                accounts={accounts || []}
                journalOptions={journalOptions}
            />

            {/* View Dialog */}
            <JournalEntryViewDialog
                open={dialogOpen}
                onClose={closeDialog}
                entryId={viewEntryId}
            />
        </TenantLayout>
    );
}
