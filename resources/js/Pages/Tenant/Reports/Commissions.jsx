import React from 'react';
import { Head, router, Link } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { DollarSign, CalendarDays, Percent } from 'lucide-react';
import { formatMoney } from '@/lib/currency';

export default function Commissions({ items, summary = [], filters = {} }) {
    const [startDate, setStartDate] = React.useState(filters.start_date || '');
    const [endDate, setEndDate] = React.useState(filters.end_date || '');

    const applyFilters = () => {
        router.get(route('reports.commissions'), { start_date: startDate, end_date: endDate }, { preserveState: true });
    };

    const supplyTypeVariant = (type) => {
        if (type === 'own_credentials') return 'bg-blue-50 text-blue-700 border-blue-200';
        if (type === 'master_agency_supply') return 'bg-purple-50 text-purple-700 border-purple-200';
        return 'bg-slate-100 text-slate-700 border-slate-200';
    };

    const supplyTypeLabel = (type) => {
        if (type === 'own_credentials') return 'Own Credentials';
        if (type === 'master_agency_supply') return 'Master Supply';
        return type || 'Unknown';
    };

    const formatDateTime = (value) => {
        if (!value) return '-';
        return new Date(value).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
        });
    };

    const rows = items?.data ?? [];

    return (
        <TenantNavbarLayout>
            <Head title="Commission Report" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <div className="flex items-center gap-3">
                    <DollarSign className="h-7 w-7 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold">Commission Report</h1>
                        <p className="text-sm text-muted-foreground">Commission breakdown by supply type</p>
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

                {summary.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {summary.map((row, i) => (
                            <Card key={`${row.currency}-${row.supply_type}-${i}`}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                        <Percent className="h-4 w-4" />
                                        {supplyTypeLabel(row.supply_type)} ({row.currency})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-2xl font-bold">{formatMoney(row.total_commission, row.currency)}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {row.item_count} items · Net: {formatMoney(row.total_net, row.currency)}
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order</TableHead>
                                    <TableHead>PNR</TableHead>
                                    <TableHead>Product</TableHead>
                                    <TableHead>Supply Type</TableHead>
                                    <TableHead>Currency</TableHead>
                                    <TableHead className="text-right">Net Fare</TableHead>
                                    <TableHead className="text-right">Commission %</TableHead>
                                    <TableHead className="text-right">Commission</TableHead>
                                    <TableHead className="text-right">Net After</TableHead>
                                    <TableHead>Issued</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={10} className="py-8 text-center text-muted-foreground">
                                            No commission data found for the selected period.
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
                                            <TableCell>
                                                <Badge variant="outline">{item.product_type || 'ticket'}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={supplyTypeVariant(item.item_details?.financial_source)}>
                                                    {supplyTypeLabel(item.item_details?.financial_source)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{item.currency}</TableCell>
                                            <TableCell className="text-right">{formatMoney(item.net_fare, item.currency)}</TableCell>
                                            <TableCell className="text-right">{Number(item.commission_percent).toFixed(1)}%</TableCell>
                                            <TableCell className="text-right font-semibold">{formatMoney(item.commission_amount, item.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(item.net_after_commission, item.currency)}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{formatDateTime(item.issued_at)}</TableCell>
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
