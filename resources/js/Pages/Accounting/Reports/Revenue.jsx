import { Head } from '@inertiajs/react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AccountingKpiCard from '@/Components/Accounting/AccountingKpiCard';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import ExportButton from '@/Components/Accounting/ExportButton';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const PRODUCT_COLORS = {
    airline: '#3b82f6',
    hotel: '#22c55e',
    insurance: '#f97316',
    esim: '#a855f7',
    other: '#94a3b8',
};

export default function Revenue({ byProduct, totalRevenue, totalVat, totalNet, trend }) {
    const { t } = useTranslation();

    return (
        <TenantLayout>
            <Head title={t('accounting.reports.revenue_title', 'Revenue by Product')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold">{t('accounting.reports.revenue_title', 'Revenue by Product')}</h1>
                            <p className="text-sm text-gray-500 mt-1">{t('accounting.reports.revenue_desc', 'Gross sales breakdown by product type')}</p>
                        </div>
                        <ExportButton url={route('accounting.reports.revenue') + '?export=csv'} label={t('common.export_csv', 'Export CSV')} />
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-3 gap-4">
                        <AccountingKpiCard title={t('accounting.reports.total_gross', 'Total Gross Revenue')} value={totalRevenue} currency="LYD" />
                        <AccountingKpiCard title={t('accounting.reports.total_vat', 'Total VAT')} value={totalVat} currency="LYD" />
                        <AccountingKpiCard title={t('accounting.reports.total_net', 'Total Net Revenue')} value={totalNet} currency="LYD" />
                    </div>

                    {/* Trend chart */}
                    {trend.length > 0 && (
                        <Card className="p-4">
                            <h2 className="font-medium mb-4">{t('accounting.reports.monthly_trend', 'Monthly Revenue Trend')}</h2>
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={trend}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" />
                                    <YAxis />
                                    <Tooltip formatter={(v) => `LYD ${v.toFixed(3)}`} />
                                    <Legend />
                                    <Bar dataKey="airline" stackId="a" fill={PRODUCT_COLORS.airline} name="Airline" />
                                    <Bar dataKey="hotel" stackId="a" fill={PRODUCT_COLORS.hotel} name="Hotel" />
                                    <Bar dataKey="insurance" stackId="a" fill={PRODUCT_COLORS.insurance} name="Insurance" />
                                    <Bar dataKey="esim" stackId="a" fill={PRODUCT_COLORS.esim} name="eSIM" />
                                </BarChart>
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
                                        <TableHead className="text-right">{t('accounting.reports.gross_revenue', 'Gross Revenue')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.vat_collected', 'VAT Collected')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.net_revenue', 'Net Revenue')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.order_count', 'Orders')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {byProduct.map(row => (
                                        <TableRow key={row.product}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <span className="w-3 h-3 rounded-full inline-block" style={{ backgroundColor: PRODUCT_COLORS[row.product] ?? '#94a3b8' }} />
                                                    <span className="capitalize font-medium">{row.product}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.revenue} currency="LYD" /></TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.vatCollected} currency="LYD" /></TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.revenueNet} currency="LYD" /></TableCell>
                                            <TableCell className="text-right text-gray-600">{row.orderCount}</TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow className="bg-gray-50 font-bold border-t-2">
                                        <TableCell>{t('common.totals', 'TOTALS')}</TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totalRevenue} currency="LYD" /></TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totalVat} currency="LYD" /></TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totalNet} currency="LYD" /></TableCell>
                                        <TableCell className="text-right">{byProduct.reduce((s, r) => s + r.orderCount, 0)}</TableCell>
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
