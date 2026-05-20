import { Head, router } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const BATCH_STATUS_COLORS = {
    pending: 'bg-amber-100 text-amber-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
};

export default function SettlementIndex({ agencyType, outstanding, recentBatches, totalOutstanding, payableTo, totalPayable }) {
    const { t } = useTranslation();

    if (agencyType === 'direct') {
        return (
            <TenantLayout>
                <Head title={t('accounting.nav.settlement', 'Settlement')} />
                <AccountingLayout>
                    <div className="space-y-6">
                        <h1 className="text-2xl font-semibold">{t('accounting.nav.settlement', 'Settlement')}</h1>
                        <Alert>
                            <AlertDescription>
                                {t('accounting.settlement.not_applicable', 'Settlement is only applicable to network agencies and their merchants. This agency operates independently.')}
                            </AlertDescription>
                        </Alert>
                    </div>
                </AccountingLayout>
            </TenantLayout>
        );
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.settlement', 'Settlement')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold">{t('accounting.nav.settlement', 'Settlement')}</h1>
                            <p className="text-sm text-gray-500 mt-1">
                                {agencyType === 'network'
                                    ? t('accounting.settlement.network_subtitle', 'Outstanding merchant receivables and settlement batches')
                                    : t('accounting.settlement.merchant_subtitle', 'Amounts payable to your network agency')}
                            </p>
                        </div>
                        {agencyType === 'network' && (
                            <Button onClick={() => router.get(route('accounting.settlement.aging'))}>
                                {t('accounting.settlement.view_aging', 'View Aging Report')}
                            </Button>
                        )}
                    </div>

                    {/* Summary banner */}
                    <Card className="p-4 flex items-center justify-between bg-gray-50">
                        <div>
                            <p className="text-sm text-gray-500">
                                {agencyType === 'network'
                                    ? t('accounting.settlement.total_outstanding', 'Total Outstanding')
                                    : t('accounting.settlement.total_payable', 'Total Payable')}
                            </p>
                            <p className="text-2xl font-bold mt-1">
                                <AmountDisplay amount={agencyType === 'network' ? totalOutstanding : totalPayable} currency="LYD" />
                            </p>
                        </div>
                    </Card>

                    {/* Network: receivables table */}
                    {agencyType === 'network' && (
                        <Card>
                            <div className="px-4 py-3 border-b">
                                <h2 className="font-medium">{t('accounting.settlement.outstanding_receivables', 'Outstanding Receivables')}</h2>
                            </div>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('accounting.settlement.merchant', 'Merchant')}</TableHead>
                                            <TableHead className="text-right">{t('accounting.settlement.amount', 'Amount')}</TableHead>
                                            <TableHead>{t('accounting.settlement.oldest_unpaid', 'Oldest Unpaid')}</TableHead>
                                            <TableHead>{t('accounting.settlement.orders', 'Orders')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {outstanding.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-center py-8 text-gray-400">
                                                    {t('accounting.settlement.no_outstanding', 'No outstanding receivables')}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {outstanding.map(row => (
                                            <TableRow key={row.merchantId}>
                                                <TableCell className="font-medium">{row.merchantName}</TableCell>
                                                <TableCell className="text-right">
                                                    <AmountDisplay amount={row.amount} currency="LYD" />
                                                </TableCell>
                                                <TableCell className="text-sm text-gray-500">
                                                    {row.oldestUnpaidDate ? new Date(row.oldestUnpaidDate).toLocaleDateString() : '—'}
                                                </TableCell>
                                                <TableCell className="text-sm text-gray-500">{row.orderCount ?? '—'}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Card>
                    )}

                    {/* Merchant: payables table */}
                    {agencyType === 'merchant' && (
                        <Card>
                            <div className="px-4 py-3 border-b">
                                <h2 className="font-medium">{t('accounting.settlement.payables', 'Amounts Payable')}</h2>
                            </div>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('accounting.settlement.agency', 'Agency')}</TableHead>
                                            <TableHead className="text-right">{t('accounting.settlement.amount', 'Amount')}</TableHead>
                                            <TableHead>{t('accounting.settlement.oldest_unpaid', 'Oldest Unpaid')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {payableTo.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={3} className="text-center py-8 text-gray-400">
                                                    {t('accounting.settlement.no_payable', 'No outstanding payables')}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {payableTo.map((row, i) => (
                                            <TableRow key={i}>
                                                <TableCell className="font-medium">{row.agencyName}</TableCell>
                                                <TableCell className="text-right">
                                                    <AmountDisplay amount={row.amount} currency="LYD" />
                                                </TableCell>
                                                <TableCell className="text-sm text-gray-500">
                                                    {row.oldestUnpaidDate ? new Date(row.oldestUnpaidDate).toLocaleDateString() : '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Card>
                    )}

                    {/* Recent batches */}
                    {agencyType === 'network' && (
                        <Card>
                            <div className="px-4 py-3 border-b">
                                <h2 className="font-medium">{t('accounting.settlement.recent_batches', 'Recent Settlement Batches')}</h2>
                            </div>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('accounting.settlement.reference', 'Reference')}</TableHead>
                                            <TableHead>{t('accounting.settlement.merchant', 'Merchant')}</TableHead>
                                            <TableHead className="text-right">{t('accounting.settlement.amount', 'Amount')}</TableHead>
                                            <TableHead>{t('accounting.settlement.status', 'Status')}</TableHead>
                                            <TableHead>{t('accounting.settlement.date', 'Date')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentBatches.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center py-8 text-gray-400">
                                                    {t('accounting.settlement.no_batches', 'No settlement batches yet')}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {recentBatches.map(batch => (
                                            <TableRow key={batch.id}>
                                                <TableCell className="font-mono text-sm">{batch.reference}</TableCell>
                                                <TableCell>{batch.merchantName}</TableCell>
                                                <TableCell className="text-right">
                                                    <AmountDisplay amount={batch.amount} currency="LYD" />
                                                </TableCell>
                                                <TableCell>
                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${BATCH_STATUS_COLORS[batch.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                                        {batch.status}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-sm text-gray-500">
                                                    {new Date(batch.createdAt).toLocaleDateString()}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Card>
                    )}
                </div>
            </AccountingLayout>
        </TenantLayout>
    );
}
