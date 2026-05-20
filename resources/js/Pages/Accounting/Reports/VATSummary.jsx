import { Head } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AccountingKpiCard from '@/Components/Accounting/AccountingKpiCard';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import ExportButton from '@/Components/Accounting/ExportButton';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function VATSummary({ rows, totalVatCollected, totalVatReversed, netPayable, totalGross }) {
    const { t } = useTranslation();

    return (
        <TenantLayout>
            <Head title={t('accounting.reports.vat_title', 'VAT Summary')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold">{t('accounting.reports.vat_title', 'VAT Summary')}</h1>
                            <p className="text-sm text-gray-500 mt-1">{t('accounting.reports.vat_desc', 'Tax collected, period totals, filing-ready format')}</p>
                        </div>
                        <ExportButton url={route('accounting.reports.vat') + '?export=csv'} label={t('accounting.reports.filing_export', 'Filing Export')} />
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-3 gap-4">
                        <AccountingKpiCard title={t('accounting.reports.vat_collected', 'VAT Collected')} value={totalVatCollected} currency="LYD" />
                        <AccountingKpiCard title={t('accounting.reports.vat_reversed', 'VAT Reversed')} value={totalVatReversed} currency="LYD" />
                        <AccountingKpiCard
                            title={t('accounting.reports.net_vat_payable', 'Net VAT Payable')}
                            value={netPayable}
                            currency="LYD"
                            variant={netPayable > 0 ? 'warning' : 'default'}
                        />
                    </div>

                    {/* Transaction table */}
                    <Card>
                        <div className="px-4 py-3 border-b flex items-center justify-between">
                            <h2 className="font-medium">{t('accounting.reports.vat_transactions', 'VAT Transactions')}</h2>
                            <span className="text-sm text-gray-500">
                                {t('accounting.reports.account_2400', 'Account 2400 — VAT Payable')}
                            </span>
                        </div>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('accounting.reports.date', 'Date')}</TableHead>
                                        <TableHead>{t('accounting.reports.order', 'Order')}</TableHead>
                                        <TableHead>{t('accounting.reports.product', 'Product')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.gross_amount', 'Gross Amount')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.vat_rate', 'VAT Rate')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.vat_amount', 'VAT Amount')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-center py-10 text-gray-400">
                                                {t('accounting.reports.no_vat_transactions', 'No VAT transactions found for this period')}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {rows.map((row, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="text-sm">{new Date(row.date).toLocaleDateString()}</TableCell>
                                            <TableCell className="font-mono text-sm text-blue-600">{row.orderId}</TableCell>
                                            <TableCell className="capitalize text-sm">{row.productType}</TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.grossAmount} currency="LYD" /></TableCell>
                                            <TableCell className="text-right text-sm text-gray-600">{row.vatRate}%</TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={row.vatAmount} currency="LYD" /></TableCell>
                                        </TableRow>
                                    ))}
                                    {rows.length > 0 && (
                                        <TableRow className="bg-gray-50 font-bold border-t-2">
                                            <TableCell colSpan={5}>{t('common.totals', 'TOTALS')}</TableCell>
                                            <TableCell className="text-right"><AmountDisplay amount={totalVatCollected} currency="LYD" /></TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </Card>
                </div>
            </AccountingLayout>
        </TenantLayout>
    );
}
