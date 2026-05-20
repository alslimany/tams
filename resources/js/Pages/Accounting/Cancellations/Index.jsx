import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import JournalEntrySheet from '@/Components/Accounting/JournalEntrySheet';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function CancellationsIndex({ cancellations, filters }) {
    const { t } = useTranslation();
    const [sheetRef, setSheetRef] = useState(null);
    const [form, setForm] = useState({
        from: filters.from ?? '',
        to: filters.to ?? '',
        productType: filters.productType ?? '',
    });

    function applyFilters() {
        router.get(route('accounting.cancellations.index'), form, { preserveState: true, replace: true });
    }

    function clearFilters() {
        setForm({ from: '', to: '', productType: '' });
        router.get(route('accounting.cancellations.index'), {}, { preserveState: false });
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.cancellations', 'Cancellations & Voids')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div>
                        <h1 className="text-2xl font-semibold">{t('accounting.nav.cancellations', 'Cancellations & Voids')}</h1>
                        <p className="text-sm text-gray-500 mt-1">{t('accounting.cancellations.subtitle', 'Audit trail of all cancellations and voids with reversal journal entries')}</p>
                    </div>

                    {/* Filters */}
                    <Card className="p-4">
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <input
                                type="date"
                                value={form.from}
                                onChange={e => setForm(f => ({ ...f, from: e.target.value }))}
                                className="border rounded-md px-3 py-2 text-sm"
                                placeholder="From"
                            />
                            <input
                                type="date"
                                value={form.to}
                                onChange={e => setForm(f => ({ ...f, to: e.target.value }))}
                                className="border rounded-md px-3 py-2 text-sm"
                                placeholder="To"
                            />
                            <select
                                value={form.productType}
                                onChange={e => setForm(f => ({ ...f, productType: e.target.value }))}
                                className="border rounded-md px-3 py-2 text-sm"
                            >
                                <option value="">{t('accounting.issuances.all_products', 'All Products')}</option>
                                <option value="airline">Airline</option>
                                <option value="hotel">Hotel</option>
                                <option value="insurance">Insurance</option>
                            </select>
                            <div className="flex gap-2">
                                <Button size="sm" onClick={applyFilters} className="flex-1">{t('common.filter', 'Filter')}</Button>
                                <Button size="sm" variant="outline" onClick={clearFilters}>{t('common.clear', 'Clear')}</Button>
                            </div>
                        </div>
                    </Card>

                    {/* Table */}
                    <Card>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('accounting.cancellations.order', 'Order')}</TableHead>
                                        <TableHead>{t('accounting.cancellations.product', 'Product')}</TableHead>
                                        <TableHead>{t('accounting.cancellations.provider_ref', 'Provider Ref')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.cancellations.original_price', 'Original Price')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.cancellations.fee', 'Fee')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.cancellations.net_refunded', 'Net Refunded')}</TableHead>
                                        <TableHead>{t('accounting.cancellations.provider_restored', 'Provider')}</TableHead>
                                        <TableHead>{t('accounting.cancellations.cancelled_at', 'Cancelled At')}</TableHead>
                                        <TableHead>{t('accounting.cancellations.cancelled_by', 'By')}</TableHead>
                                        <TableHead></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {cancellations.data.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={10} className="text-center py-10 text-gray-400">
                                                {t('accounting.cancellations.empty', 'No cancellations found')}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {cancellations.data.map((row, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="font-mono text-sm text-blue-600">{row.orderNumber}</TableCell>
                                            <TableCell>
                                                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                                    {row.productType}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-sm text-gray-500">{row.providerReference || '—'}</TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay amount={row.originalSalePrice} currency="LYD" />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.cancellationFee > 0
                                                    ? <span className="text-amber-600 font-medium"><AmountDisplay amount={row.cancellationFee} currency="LYD" /></span>
                                                    : <span className="text-gray-400">—</span>}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay amount={row.netRefunded} currency="LYD" />
                                            </TableCell>
                                            <TableCell>
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${row.providerBalanceRestored ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>
                                                    {row.providerBalanceRestored
                                                        ? t('accounting.cancellations.provider_restored', 'Provider Restored')
                                                        : t('accounting.cancellations.provider_not_restored', 'Not Restored')}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-sm text-gray-500">
                                                {row.cancelledAt ? new Date(row.cancelledAt).toLocaleDateString() : '—'}
                                            </TableCell>
                                            <TableCell className="text-sm text-gray-500">{row.cancelledBy}</TableCell>
                                            <TableCell>
                                                {row.reversalJournalReference && (
                                                    <Button size="sm" variant="ghost" onClick={() => setSheetRef(row.reversalJournalReference)}>
                                                        {t('accounting.view_journal', 'Journal')}
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        {cancellations.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t">
                                <p className="text-sm text-gray-500">
                                    {t('common.page_of', 'Page')} {cancellations.current_page} / {cancellations.last_page}
                                </p>
                                <div className="flex gap-2">
                                    {cancellations.current_page > 1 && (
                                        <Button size="sm" variant="outline" onClick={() => router.get(route('accounting.cancellations.index'), { ...form, page: cancellations.current_page - 1 })}>
                                            {t('common.previous', 'Previous')}
                                        </Button>
                                    )}
                                    {cancellations.current_page < cancellations.last_page && (
                                        <Button size="sm" variant="outline" onClick={() => router.get(route('accounting.cancellations.index'), { ...form, page: cancellations.current_page + 1 })}>
                                            {t('common.next', 'Next')}
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </Card>
                </div>

                {sheetRef && (
                    <JournalEntrySheet reference={sheetRef} open={!!sheetRef} onClose={() => setSheetRef(null)} />
                )}
            </AccountingLayout>
        </TenantLayout>
    );
}
