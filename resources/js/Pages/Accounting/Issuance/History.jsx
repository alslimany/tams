import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AccountingKpiCard from '@/Components/Accounting/AccountingKpiCard';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import JournalEntrySheet from '@/Components/Accounting/JournalEntrySheet';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const PRODUCT_COLORS = {
    airline: 'bg-blue-100 text-blue-700',
    hotel: 'bg-green-100 text-green-700',
    insurance: 'bg-orange-100 text-orange-700',
    esim: 'bg-purple-100 text-purple-700',
};

const STATUS_COLORS = {
    issued: 'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-700',
    voided: 'bg-gray-100 text-gray-600',
};

function marginColor(pct) {
    if (pct >= 15) return 'text-green-600 font-medium';
    if (pct >= 5) return 'text-amber-600 font-medium';
    return 'text-red-600 font-medium';
}

export default function History({ issuances, summary, filters }) {
    const { t } = useTranslation();
    const [sheetRef, setSheetRef] = useState(null);

    const [form, setForm] = useState({
        productType: filters.productType ?? '',
        status: filters.status ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        issuedBy: filters.issuedBy ?? '',
    });

    function applyFilters() {
        router.get(route('accounting.issuances.index'), form, { preserveState: true, replace: true });
    }

    function clearFilters() {
        const empty = { productType: '', status: '', from: '', to: '', issuedBy: '' };
        setForm(empty);
        router.get(route('accounting.issuances.index'), {}, { preserveState: false });
    }

    const totalCount = Object.values(summary.countByProduct).reduce((a, b) => a + b, 0);

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.issuances', 'Issuance History')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div>
                        <h1 className="text-2xl font-semibold">{t('accounting.nav.issuances', 'Issuance History')}</h1>
                        <p className="text-sm text-gray-500 mt-1">{t('accounting.issuances.subtitle', 'Complete log of all product issuances and their financial impact')}</p>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <AccountingKpiCard title={t('accounting.issuances.total_revenue', 'Total Revenue')} value={summary.totalRevenue} currency="LYD" />
                        <AccountingKpiCard title={t('accounting.issuances.total_cost', 'Total Cost')} value={summary.totalCost} currency="LYD" />
                        <AccountingKpiCard title={t('accounting.issuances.total_margin', 'Gross Margin')} value={summary.totalMargin} currency="LYD" variant={summary.totalMargin < 0 ? 'danger' : 'default'} />
                        <AccountingKpiCard title={t('accounting.issuances.total_count', 'Total Issuances')} value={totalCount} />
                    </div>

                    {/* Filters */}
                    <Card className="p-4">
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                            <select
                                value={form.productType}
                                onChange={e => setForm(f => ({ ...f, productType: e.target.value }))}
                                className="border rounded-md px-3 py-2 text-sm"
                            >
                                <option value="">{t('accounting.issuances.all_products', 'All Products')}</option>
                                <option value="airline">Airline</option>
                                <option value="hotel">Hotel</option>
                                <option value="insurance">Insurance</option>
                                <option value="esim">eSIM</option>
                            </select>
                            <select
                                value={form.status}
                                onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                                className="border rounded-md px-3 py-2 text-sm"
                            >
                                <option value="">{t('accounting.issuances.all_statuses', 'All Statuses')}</option>
                                <option value="issued">Issued</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="voided">Voided</option>
                            </select>
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
                                        <TableHead>{t('accounting.issuances.order', 'Order')}</TableHead>
                                        <TableHead>{t('accounting.issuances.product', 'Product')}</TableHead>
                                        <TableHead>{t('accounting.issuances.provider_ref', 'Provider Ref')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.issuances.selling_price', 'Selling Price')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.issuances.vat', 'VAT')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.issuances.cost', 'Cost')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.issuances.margin', 'Margin')}</TableHead>
                                        <TableHead>{t('accounting.issuances.status', 'Status')}</TableHead>
                                        <TableHead>{t('accounting.issuances.issued_at', 'Issued At')}</TableHead>
                                        <TableHead>{t('accounting.issuances.issued_by', 'Issued By')}</TableHead>
                                        <TableHead></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {issuances.data.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={11} className="text-center py-10 text-gray-400">
                                                {t('accounting.issuances.empty', 'No issuances found')}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {issuances.data.map(item => (
                                        <TableRow key={item.id} className={item.status !== 'issued' ? 'opacity-60' : ''}>
                                            <TableCell>
                                                <Link href={route('orders.show', item.orderId)} className="text-blue-600 hover:underline text-sm font-mono">
                                                    {item.orderNumber}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${PRODUCT_COLORS[item.productType] ?? 'bg-gray-100 text-gray-700'}`}>
                                                    {item.productType}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-sm font-mono text-gray-600">{item.providerReference || '—'}</TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay amount={item.sellingPrice} currency="LYD" />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay amount={item.vatAmount} currency="LYD" />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay amount={item.providerCost} currency="LYD" />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <span className={marginColor(item.grossMarginPct)}>
                                                    <AmountDisplay amount={item.grossMargin} currency="LYD" colorize />
                                                    <span className="text-xs ml-1">({item.grossMarginPct.toFixed(1)}%)</span>
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${STATUS_COLORS[item.status] ?? 'bg-gray-100 text-gray-600'} ${item.status === 'cancelled' ? 'line-through' : ''}`}>
                                                    {item.status}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-sm text-gray-600">
                                                {item.issuedAt ? new Date(item.issuedAt).toLocaleDateString() : '—'}
                                            </TableCell>
                                            <TableCell className="text-sm text-gray-600">{item.issuedBy}</TableCell>
                                            <TableCell>
                                                {item.journalReference && (
                                                    <Button size="sm" variant="ghost" onClick={() => setSheetRef(item.journalReference)}>
                                                        {t('accounting.view_journal', 'Journal')}
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Pagination */}
                        {issuances.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t">
                                <p className="text-sm text-gray-500">
                                    {t('common.page_of', 'Page')} {issuances.current_page} / {issuances.last_page}
                                </p>
                                <div className="flex gap-2">
                                    {issuances.current_page > 1 && (
                                        <Button size="sm" variant="outline" onClick={() => router.get(route('accounting.issuances.index'), { ...form, page: issuances.current_page - 1 })}>
                                            {t('common.previous', 'Previous')}
                                        </Button>
                                    )}
                                    {issuances.current_page < issuances.last_page && (
                                        <Button size="sm" variant="outline" onClick={() => router.get(route('accounting.issuances.index'), { ...form, page: issuances.current_page + 1 })}>
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
