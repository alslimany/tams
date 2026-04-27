import React from 'react';
import { Head, router, Link } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { Select } from '@/Components/ui/Select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Wallet, CalendarDays, ArrowDownCircle, ArrowUpCircle } from 'lucide-react';
import { formatMoney } from '@/lib/currency';

export default function WalletTransactions({ transactions, balanceSummary = [], filters = {} }) {
    const [startDate, setStartDate] = React.useState(filters.start_date || '');
    const [endDate, setEndDate] = React.useState(filters.end_date || '');
    const [type, setType] = React.useState(filters.type || '');

    const applyFilters = () => {
        router.get(route('wallet.transactions'), { start_date: startDate, end_date: endDate, type }, { preserveState: true });
    };

    const formatDateTime = (value) => {
        if (!value) return '-';
        return new Date(value).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const typeVariant = (t) => {
        if (t === 'deposit') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (t === 'withdraw') return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-slate-100 text-slate-700 border-slate-200';
    };

    const typeIcon = (t) => {
        if (t === 'deposit') return <ArrowDownCircle className="h-4 w-4 text-emerald-600" />;
        if (t === 'withdraw') return <ArrowUpCircle className="h-4 w-4 text-rose-600" />;
        return null;
    };

    const rows = transactions?.data ?? [];

    return (
        <TenantNavbarLayout>
            <Head title="Wallet Transactions" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <div className="flex items-center gap-3">
                    <Wallet className="h-7 w-7 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold">Wallet Transaction History</h1>
                        <p className="text-sm text-muted-foreground">All wallet deposits and withdrawals</p>
                    </div>
                </div>

                {balanceSummary.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {balanceSummary.map((wallet) => (
                            <Card key={wallet.slug}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium text-muted-foreground">
                                        {wallet.currency} Wallet
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-2xl font-bold">{formatMoney(wallet.balance, wallet.currency)}</p>
                                    <p className="text-xs text-muted-foreground">Current balance</p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                        <div className="space-y-1">
                            <label className="text-sm font-medium text-muted-foreground">Start Date</label>
                            <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
                        </div>
                        <div className="space-y-1">
                            <label className="text-sm font-medium text-muted-foreground">End Date</label>
                            <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                        </div>
                        <div className="space-y-1">
                            <label className="text-sm font-medium text-muted-foreground">Type</label>
                            <select
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="deposit">Deposits</option>
                                <option value="withdraw">Withdrawals</option>
                            </select>
                        </div>
                        <Button onClick={applyFilters}>
                            <CalendarDays className="mr-2 h-4 w-4" /> Apply
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Currency</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Order</TableHead>
                                    <TableHead>Date</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                            No wallet transactions found for the selected period.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((tx) => (
                                        <TableRow key={tx.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {typeIcon(tx.type)}
                                                    <Badge className={typeVariant(tx.type)}>
                                                        {tx.type === 'deposit' ? 'Deposit' : 'Withdrawal'}
                                                    </Badge>
                                                </div>
                                            </TableCell>
                                            <TableCell>{tx.currency}</TableCell>
                                            <TableCell className="text-right font-semibold">
                                                {tx.type === 'deposit' ? '+' : '-'}{formatMoney(tx.amount, tx.currency)}
                                            </TableCell>
                                            <TableCell className="max-w-50 truncate text-sm text-muted-foreground">
                                                {tx.description || '-'}
                                            </TableCell>
                                            <TableCell>
                                                {tx.order_id ? (
                                                    <Link href={route('orders.show', tx.order_id)} className="text-primary hover:underline text-sm">
                                                        View Order
                                                    </Link>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{formatDateTime(tx.created_at)}</TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </TenantNavbarLayout>
    );
}
