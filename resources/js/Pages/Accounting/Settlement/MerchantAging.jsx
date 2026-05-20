import { Head } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import ExportButton from '@/Components/Accounting/ExportButton';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

function agingCell(value, highlight) {
    if (value === 0) return <span className="text-gray-400">—</span>;
    return (
        <span className={highlight ? 'font-medium text-amber-700' : ''}>
            <AmountDisplay amount={value} currency="LYD" />
        </span>
    );
}

export default function MerchantAging({ rows, totals, summary }) {
    const { t } = useTranslation();

    return (
        <TenantLayout>
            <Head title={t('accounting.settlement.aging_title', 'Merchant Settlement Aging')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold">{t('accounting.settlement.aging_title', 'Merchant Settlement Aging')}</h1>
                            <p className="text-sm text-gray-500 mt-1">{t('accounting.settlement.aging_subtitle', 'Outstanding receivables bucketed by age')}</p>
                        </div>
                        <ExportButton url={route('accounting.settlement.aging') + '?export=csv'} label={t('common.export_csv', 'Export CSV')} />
                    </div>

                    {/* Summary */}
                    <div className="grid grid-cols-3 gap-4">
                        <Card className="p-4">
                            <p className="text-sm text-gray-500">{t('accounting.settlement.total_receivable', 'Total Receivable')}</p>
                            <p className="text-xl font-bold mt-1"><AmountDisplay amount={summary.total_receivable} currency="LYD" /></p>
                        </Card>
                        <Card className="p-4">
                            <p className="text-sm text-gray-500">{t('accounting.settlement.total_settled', 'Total Settled')}</p>
                            <p className="text-xl font-bold mt-1 text-green-600"><AmountDisplay amount={summary.total_settled} currency="LYD" /></p>
                        </Card>
                        <Card className="p-4">
                            <p className="text-sm text-gray-500">{t('accounting.settlement.outstanding', 'Outstanding')}</p>
                            <p className={`text-xl font-bold mt-1 ${summary.outstanding > 0 ? 'text-amber-600' : 'text-green-600'}`}>
                                <AmountDisplay amount={summary.outstanding} currency="LYD" />
                            </p>
                        </Card>
                    </div>

                    {/* Aging table */}
                    <Card>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('accounting.settlement.merchant', 'Merchant')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.settlement.current_0_30', 'Current (0–30 days)')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.settlement.days_31_60', '31–60 days')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.settlement.days_61_90', '61–90 days')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.settlement.days_90_plus', '90+ days')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.settlement.total', 'Total')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map(row => (
                                        <TableRow key={row.merchantId}>
                                            <TableCell className="font-medium">{row.merchantName}</TableCell>
                                            <TableCell className="text-right">{agingCell(row.current, false)}</TableCell>
                                            <TableCell className="text-right">{agingCell(row.days31to60, row.days31to60 > 0)}</TableCell>
                                            <TableCell className={`text-right ${row.days61to90 > 0 ? 'bg-amber-50' : ''}`}>{agingCell(row.days61to90, row.days61to90 > 0)}</TableCell>
                                            <TableCell className={`text-right ${row.days90plus > 0 ? 'bg-red-50' : ''}`}>
                                                {row.days90plus > 0
                                                    ? <span className="font-medium text-red-700"><AmountDisplay amount={row.days90plus} currency="LYD" /></span>
                                                    : <span className="text-gray-400">—</span>}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <AmountDisplay amount={row.total} currency="LYD" />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {/* Totals row */}
                                    <TableRow className="bg-gray-50 font-bold border-t-2">
                                        <TableCell>{t('common.totals', 'TOTALS')}</TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totals.current} currency="LYD" /></TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totals.days31to60} currency="LYD" /></TableCell>
                                        <TableCell className={`text-right ${totals.days61to90 > 0 ? 'bg-amber-50' : ''}`}><AmountDisplay amount={totals.days61to90} currency="LYD" /></TableCell>
                                        <TableCell className={`text-right ${totals.days90plus > 0 ? 'bg-red-50' : ''}`}><AmountDisplay amount={totals.days90plus} currency="LYD" /></TableCell>
                                        <TableCell className="text-right"><AmountDisplay amount={totals.total} currency="LYD" /></TableCell>
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
