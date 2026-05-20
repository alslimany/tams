import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ArrowLeftIcon, ExternalLinkIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import JournalEntrySheet from '@/Components/Accounting/JournalEntrySheet';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function WalletShow({ wallet, transactions, balanceHistory, filters }) {
    const { t } = useTranslation();
    const [sheetEntryId, setSheetEntryId] = useState(null);

    function applyFilter(key, value) {
        router.get(
            route('accounting.wallets.show', wallet.id),
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    return (
        <TenantLayout>
            <Head title={wallet.name} />
            <AccountingLayout
                title={wallet.name}
                subtitle={
                    <span className="flex items-center gap-2">
                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{wallet.ledgerAccount}</code>
                        <span className="text-muted-foreground">·</span>
                        <span className="text-muted-foreground">{wallet.slug}</span>
                    </span>
                }
                actions={
                    <Link
                        href={route('accounting.wallets.index')}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeftIcon className="size-3" /> All Wallets
                    </Link>
                }
            >
                {/* Balance hero */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <p className="text-sm text-muted-foreground">Current Balance</p>
                        <p className="mt-1 text-4xl font-bold">
                            <AmountDisplay amount={wallet.balance} currency={wallet.currency} />
                        </p>
                    </CardContent>
                </Card>

                {/* Balance chart */}
                {balanceHistory.length > 0 && (
                    <Card className="mb-6">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Balance — Last 30 Days</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={160}>
                                <AreaChart data={balanceHistory} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="balGrad" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="hsl(var(--primary))" stopOpacity={0.2} />
                                            <stop offset="95%" stopColor="hsl(var(--primary))" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                    <XAxis
                                        dataKey="date"
                                        tick={{ fontSize: 10 }}
                                        tickFormatter={(v) => v.slice(5)}
                                        interval="preserveStartEnd"
                                    />
                                    <YAxis tick={{ fontSize: 10 }} width={60} />
                                    <Tooltip
                                        formatter={(v) => [v.toLocaleString('en-LY', { minimumFractionDigits: 3 }), 'Balance']}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="balance"
                                        stroke="hsl(var(--primary))"
                                        fill="url(#balGrad)"
                                        strokeWidth={2}
                                        dot={false}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <select
                        value={filters.type ?? ''}
                        onChange={(e) => applyFilter('type', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                    >
                        <option value="">All Types</option>
                        <option value="deposit">Deposit</option>
                        <option value="withdraw">Withdraw</option>
                    </select>
                    <input
                        type="date"
                        value={filters.from ?? ''}
                        onChange={(e) => applyFilter('from', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                    />
                    <input
                        type="date"
                        value={filters.to ?? ''}
                        onChange={(e) => applyFilter('to', e.target.value)}
                        className="rounded-md border bg-background px-3 py-1.5 text-sm"
                    />
                </div>

                {/* Transactions table */}
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                                <TableHead>Order</TableHead>
                                <TableHead>Ledger</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {transactions.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={5} className="py-10 text-center text-sm text-muted-foreground">
                                        No transactions found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                transactions.data.map((tx) => (
                                    <TableRow key={tx.id}>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {tx.confirmedAt
                                                ? new Date(tx.confirmedAt).toLocaleDateString()
                                                : new Date(tx.createdAt).toLocaleDateString()}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={tx.type === 'deposit' ? 'success' : 'destructive'}
                                                className="capitalize"
                                            >
                                                {tx.type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <AmountDisplay
                                                amount={tx.amount}
                                                currency={wallet.currency}
                                                colorize
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {tx.orderReference ? (
                                                <Link
                                                    href={route('orders.show', tx.orderReference)}
                                                    className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                                >
                                                    #{tx.orderReference}
                                                </Link>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {tx.journalEntryReference ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-6 px-2 text-xs"
                                                    onClick={() => setSheetEntryId(tx.journalEntryReference)}
                                                >
                                                    <ExternalLinkIcon className="size-3" />
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">—</span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    {/* Pagination */}
                    {transactions.last_page > 1 && (
                        <div className="flex items-center justify-between border-t px-4 py-3">
                            <p className="text-xs text-muted-foreground">
                                Page {transactions.current_page} of {transactions.last_page} · {transactions.total} transactions
                            </p>
                            <div className="flex gap-1">
                                {transactions.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        className="h-7 min-w-7 px-2 text-xs"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </Card>
            </AccountingLayout>

            {/* Journal entry sheet */}
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
