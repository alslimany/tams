import React from 'react';
import { Head, router } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableFooter } from '@/Components/ui/Table';
import { BarChart3, CalendarDays, TrendingUp } from 'lucide-react';
import { formatMoney } from '@/lib/currency';

export default function DailySales({ rows = [], grandTotals = [], filters = {} }) {
    const [startDate, setStartDate] = React.useState(filters.start_date || '');
    const [endDate, setEndDate] = React.useState(filters.end_date || '');

    const applyFilters = () => {
        router.get(route('reports.sales'), { start_date: startDate, end_date: endDate }, { preserveState: true });
    };

    const formatDate = (value) => {
        if (!value) return '-';
        return new Date(value + 'T00:00:00').toLocaleDateString('en-US', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <TenantSidebarLayout>
            <Head title="Daily Sales Report" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <div className="flex items-center gap-3">
                    <BarChart3 className="h-7 w-7 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold">Daily Sales Summary</h1>
                        <p className="text-sm text-muted-foreground">Sales grouped by date and product type</p>
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

                {grandTotals.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {grandTotals.map((total) => (
                            <Card key={total.currency}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                        <TrendingUp className="h-4 w-4" /> Total Sales ({total.currency})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-2xl font-bold">{formatMoney(total.total_sales, total.currency)}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {total.order_count} orders · Commission: {formatMoney(total.total_commission, total.currency)}
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
                                    <TableHead>Date</TableHead>
                                    <TableHead>Product Type</TableHead>
                                    <TableHead>Currency</TableHead>
                                    <TableHead className="text-right">Orders</TableHead>
                                    <TableHead className="text-right">Total Sales</TableHead>
                                    <TableHead className="text-right">Fare</TableHead>
                                    <TableHead className="text-right">Tax</TableHead>
                                    <TableHead className="text-right">Commission</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                                            No sales data found for the selected period.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((row, i) => (
                                        <TableRow key={`${row.date}-${row.product_type}-${row.currency}-${i}`}>
                                            <TableCell className="font-medium">{formatDate(row.date)}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{row.product_type || 'ticket'}</Badge>
                                            </TableCell>
                                            <TableCell>{row.currency}</TableCell>
                                            <TableCell className="text-right">{row.order_count}</TableCell>
                                            <TableCell className="text-right font-semibold">{formatMoney(row.total_sales, row.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.total_fare, row.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.total_tax, row.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.total_commission, row.currency)}</TableCell>
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
