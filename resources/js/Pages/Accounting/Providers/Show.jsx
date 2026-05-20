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

export default function ProviderShow({ provider, wallets, filters }) {
    const { t } = useTranslation();
    const [sheetEntryId, setSheetEntryId] = useState(null);

    function applyFilter(key, value) {
        router.get(
            route('accounting.providers.show', provider.id),
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    return (
        <TenantLayout>
            <Head title={provider.name} />
            <AccountingLayout
                title={provider.name}
                subtitle={provider.code ?? provider.type}
                actions={
                    <Link
                        href={route('accounting.providers.index')}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeftIcon className="size-3" /> All Providers
                    </Link>
                }
            >
                {/* Date filters */}
                <div className="mb-6 flex flex-wrap items-center gap-3">
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

                {wallets.map((wallet) => (
                    <div key={wallet.id} className="mb-10">
                        {/* Wallet header */}
                        <div className="mb-4 flex items-center justify-between">
                            <div>
                                <h2 className="text-base font-semibold">{wallet.name}</h2>
                                <p className="text-sm text-muted-foreground">{wallet.slug}</p>
                            </div>
                            <p className="text-2xl font-bold">
                                <AmountDisplay amount={wallet.balance} currency={wallet.currency} />
                            </p>
                        </div>

                        {/* Balance chart */}
                        {wallet.balanceHistory.length > 0 && (
                            <Card className="mb-4">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Balance — Last 30 Days</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={140}>
                                        <AreaChart
                                            data={wallet.balanceHistory}
                                            margin={{ top: 4, right: 8, left: 0, bottom: 0 }}
                                        >
                                            <defs>
                                                <linearGradient id={`grad-${wallet.id}`} x1="0" y1="0" x2="0" y2="1">
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
                                                formatter={(v) => [
                                                    v.toLocaleString('en-LY', { minimumFractionDigits: 3 }),
                                                    'Balance',
                                                ]}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="balance"
                                                stroke="hsl(var(--primary))"
                                                fill={`url(#grad-${wallet.id})`}
                                                strokeWidth={2}
                                                dot={false}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}

                        {/* Transactions */}
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
                                    {wallet.transactions.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="py-8 text-center text-sm text-muted-foreground"
                                            >
                                                No transactions found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        wallet.transactions.data.map((tx) => (
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

                            {wallet.transactions.last_page > 1 && (
                                <div className="flex items-center justify-between border-t px-4 py-3">
                                    <p className="text-xs text-muted-foreground">
                                        Page {wallet.transactions.current_page} of {wallet.transactions.last_page} ·{' '}
                                        {wallet.transactions.total} transactions
                                    </p>
                                    <div className="flex gap-1">
                                        {wallet.transactions.links.map((link, i) => (
                                            <Button
                                                key={i}
                                                variant={link.active ? 'default' : 'outline'}
                                                size="sm"
                                                className="h-7 min-w-7 px-2 text-xs"
                                                disabled={!link.url}
                                                onClick={() =>
                                                    link.url &&
                                                    router.get(link.url, {}, { preserveState: true })
                                                }
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}
                        </Card>
                    </div>
                ))}
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
