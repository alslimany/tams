import React from 'react';
import { Head, router, Link } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Receipt, CalendarDays, Hash } from 'lucide-react';
import { formatMoney } from '@/lib/currency';

export default function Taxes({ items, taxBreakdown = [], filters = {} }) {
    const [startDate, setStartDate] = React.useState(filters.start_date || '');
    const [endDate, setEndDate] = React.useState(filters.end_date || '');

    const applyFilters = () => {
        router.get(route('reports.taxes'), { start_date: startDate, end_date: endDate }, { preserveState: true });
    };

    const formatDateTime = (value) => {
        if (!value) return '-';
        return new Date(value).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
        });
    };

    const renderTaxes = (taxes) => {
        if (!taxes || !Array.isArray(taxes)) return '-';
        return taxes.map((tax, i) => (
            <Badge key={i} variant="outline" className="mr-1">
                {tax.code || tax.type || 'GEN'}: {formatMoney(tax.amount || 0)}
            </Badge>
        ));
    };

    const rows = items?.data ?? [];

    return (
        <TenantSidebarLayout>
            <Head title="Tax Breakdown Report" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <div className="flex items-center gap-3">
                    <Receipt className="h-7 w-7 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold">Tax Breakdown Report</h1>
                        <p className="text-sm text-muted-foreground">Tax aggregation by code across all orders</p>
                    </div>
                </div>

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
                        <Button onClick={applyFilters}>
                            <CalendarDays className="mr-2 h-4 w-4" /> Apply
                        </Button>
                    </CardContent>
                </Card>

                {taxBreakdown.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Hash className="h-5 w-5" /> Tax Code Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tax Code</TableHead>
                                        <TableHead>Currency</TableHead>
                                        <TableHead className="text-right">Total Amount</TableHead>
                                        <TableHead className="text-right">Line Items</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {taxBreakdown.map((row, i) => (
                                        <TableRow key={`${row.code}-${row.currency}-${i}`}>
                                            <TableCell className="font-mono font-semibold">{row.code}</TableCell>
                                            <TableCell>{row.currency}</TableCell>
                                            <TableCell className="text-right font-semibold">{formatMoney(row.total_amount, row.currency)}</TableCell>
                                            <TableCell className="text-right">{row.count}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Order Item Tax Details</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order</TableHead>
                                    <TableHead>PNR</TableHead>
                                    <TableHead>Currency</TableHead>
                                    <TableHead className="text-right">Total Amount</TableHead>
                                    <TableHead className="text-right">Total Tax</TableHead>
                                    <TableHead>Tax Breakdown</TableHead>
                                    <TableHead>Issued</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                                            No tax data found for the selected period.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell>
                                                <Link href={route('orders.show', item.order_id)} className="text-primary hover:underline">
                                                    {item.order_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="font-mono text-sm">{item.provider_reference}</TableCell>
                                            <TableCell>{item.currency}</TableCell>
                                            <TableCell className="text-right">{formatMoney(item.total_amount, item.currency)}</TableCell>
                                            <TableCell className="text-right font-semibold">{formatMoney(item.total_tax, item.currency)}</TableCell>
                                            <TableCell>{renderTaxes(item.taxes)}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{formatDateTime(item.issued_at)}</TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </TenantSidebarLayout>
    );
}
