import React from 'react';
import { Head, router } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Scale, CalendarDays, CheckCircle2, AlertTriangle } from 'lucide-react';
import { formatMoney } from '@/lib/currency';

export default function Reconciliation({ reconciliationRows = [], filters = {} }) {
    const [startDate, setStartDate] = React.useState(filters.start_date || '');
    const [endDate, setEndDate] = React.useState(filters.end_date || '');

    const applyFilters = () => {
        router.get(route('reports.reconciliation'), { start_date: startDate, end_date: endDate }, { preserveState: true });
    };

    const balancedCount = reconciliationRows.filter((r) => r.is_balanced).length;
    const unbalancedCount = reconciliationRows.filter((r) => !r.is_balanced).length;

    return (
        <TenantSidebarLayout>
            <Head title="Reconciliation Report" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <div className="flex items-center gap-3">
                    <Scale className="h-7 w-7 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold">Reconciliation Report</h1>
                        <p className="text-sm text-muted-foreground">Compare order totals against wallet and ledger entries</p>
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

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <CheckCircle2 className="h-8 w-8 text-emerald-600" />
                            <div>
                                <p className="text-2xl font-bold text-emerald-700">{balancedCount}</p>
                                <p className="text-sm text-muted-foreground">Balanced Currencies</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <AlertTriangle className="h-8 w-8 text-amber-600" />
                            <div>
                                <p className="text-2xl font-bold text-amber-700">{unbalancedCount}</p>
                                <p className="text-sm text-muted-foreground">Discrepancies Found</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Currency</TableHead>
                                    <TableHead className="text-right">Order Total</TableHead>
                                    <TableHead className="text-right">Commission</TableHead>
                                    <TableHead className="text-right">Expected Outflow</TableHead>
                                    <TableHead className="text-right">Wallet Withdrawals</TableHead>
                                    <TableHead className="text-right">Wallet Deposits</TableHead>
                                    <TableHead className="text-right">Airline Debits</TableHead>
                                    <TableHead className="text-right">Actual Outflow</TableHead>
                                    <TableHead className="text-right">Difference</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reconciliationRows.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={10} className="py-8 text-center text-muted-foreground">
                                            No reconciliation data found for the selected period.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    reconciliationRows.map((row) => (
                                        <TableRow key={row.currency} className={!row.is_balanced ? 'bg-amber-50/50' : ''}>
                                            <TableCell className="font-semibold">{row.currency}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.order_amount, row.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.order_commission, row.currency)}</TableCell>
                                            <TableCell className="text-right font-medium">{formatMoney(row.expected_outflow, row.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.wallet_withdrawals, row.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.wallet_deposits, row.currency)}</TableCell>
                                            <TableCell className="text-right">{formatMoney(row.airline_debits, row.currency)}</TableCell>
                                            <TableCell className="text-right font-medium">{formatMoney(row.actual_outflow, row.currency)}</TableCell>
                                            <TableCell className={`text-right font-bold ${row.difference === 0 ? 'text-emerald-600' : 'text-amber-600'}`}>
                                                {row.difference === 0 ? '0.00' : (row.difference > 0 ? '+' : '') + row.difference.toFixed(2)}
                                            </TableCell>
                                            <TableCell>
                                                {row.is_balanced ? (
                                                    <Badge className="bg-emerald-50 text-emerald-700 border-emerald-200">
                                                        <CheckCircle2 className="mr-1 h-3 w-3" /> Balanced
                                                    </Badge>
                                                ) : (
                                                    <Badge className="bg-amber-50 text-amber-700 border-amber-200">
                                                        <AlertTriangle className="mr-1 h-3 w-3" /> Discrepancy
                                                    </Badge>
                                                )}
                                            </TableCell>
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
