import { Head } from '@inertiajs/react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AccountingKpiCard from '@/Components/Accounting/AccountingKpiCard';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import ExportButton from '@/Components/Accounting/ExportButton';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

function marginPctColor(pct) {
    if (pct >= 15) return 'text-green-600 font-semibold';
    if (pct >= 5) return 'text-amber-600 font-semibold';
    return 'text-red-600 font-semibold';
}

export default function GrossMargin({ rows, totals, trend }) {
    const { t } = useTranslation();

    return (
        <TenantLayout>
            <Head title={t('accounting.reports.margin_title', 'Gross Margin')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold">{t('accounting.reports.margin_title', 'Gross Margin')}</h1>
                            <p className="text-sm text-gray-500 mt-1">{t('accounting.reports.margin_desc', 'Revenue vs cost analysis with margin % per product')}</p>
                        </div>
                        <ExportButton url={route('accounting.reports.gross-margin') + '?export=csv'} label={t('common.export_csv', 'Export CSV')} />
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <AccountingKpiCard title={t('accounting.reports.total_revenue', 'Total Revenue')} value={totals.revenue} currency="LYD" />
                        <AccountingKpiCard title={t('accounting.reports.total_cost', 'Total Cost')} value={totals.cost} currency="LYD" />
                        <AccountingKpiCard title={t('accounting.reports.gross_margin', 'Gross Margin')} value={totals.margin} currency="LYD" variant={totals.margin < 0 ? 'danger' : 'default'} />
                        <AccountingKpiCard title={t('accounting.reports.margin_pct', 'Margin %')} value={`${totals.marginPct.toFixed(1)}%`} />
                    </div>

                    {/* Trend chart */}
                    {trend.length > 0 && (
                        <Card className="p-4">
                            <h2 className="font-medium mb-4">{t('accounting.reports.margin_trend', 'Margin % Trend')}</h2>
                            <ResponsiveContainer width="100%" height={240}>
                                <LineChart data={trend}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" />
                                    <YAxis unit="%" />
                                    <Tooltip formatter={(v) => `${v.toFixed(1)}%`} />
                                    <Line type="monotone" dataKey="marginPct" stroke="#3b82f6" strokeWidth={2} dot={false} name="Margin %" />
                                </LineChart>
                            </ResponsiveContainer>
                        </Card>
                    )}

                    {/* Per-product table */}
                    <Card>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('accounting.reports.product', 'Product')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.revenue', 'Revenue')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.cost', 'Cost')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.margin', 'Margin')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.margin_pct', 'Margin %')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map(row => (
                                        <TableRow key={row.product}>
                                            <TableCell className="capitalize font-medium">{row.product}</TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.revenue} currency="LYD" /></TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.cost} currency="LYD" /></TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.margin} currency="LYD" colorize /></TableCell>
                                            <TableCell className={`text-right ${marginPctColor(row.marginPct)}`}>{row.marginPct.toFixed(1)}%</TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow className="bg-gray-50 font-bold border-t-2">
                                        <TableCell>{t('common.totals', 'TOTALS')}</TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totals.revenue} currency="LYD" /></TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totals.cost} currency="LYD" /></TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totals.margin} currency="LYD" colorize /></TableCell>
                                        <TableCell className={`text-right ${marginPctColor(totals.marginPct)}`}>{totals.marginPct.toFixed(1)}%</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </Card>
                </div>
            </AccountingLayout>
        </TenantLayout>
    );
}
