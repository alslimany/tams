import { Head, Link } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import { Card } from '@/Components/ui/Card';
import { useTranslation } from '@/hooks/useTranslation';
import {
    BarChart3Icon,
    TrendingUpIcon,
    ReceiptIcon,
    ScaleIcon,
    ClipboardListIcon,
    UsersIcon,
} from 'lucide-react';

export default function ReportsIndex() {
    const { t } = useTranslation();

    const reports = [
        {
            title: t('accounting.reports.revenue_title', 'Revenue by Product'),
            description: t('accounting.reports.revenue_desc', 'Gross sales breakdown by airline, hotel, insurance, and eSIM'),
            href: route('accounting.reports.revenue'),
            icon: <BarChart3Icon className="w-6 h-6" />,
            color: 'text-blue-600 bg-blue-50',
        },
        {
            title: t('accounting.reports.margin_title', 'Gross Margin'),
            description: t('accounting.reports.margin_desc', 'Revenue vs cost analysis with margin % per product type'),
            href: route('accounting.reports.gross-margin'),
            icon: <TrendingUpIcon className="w-6 h-6" />,
            color: 'text-green-600 bg-green-50',
        },
        {
            title: t('accounting.reports.vat_title', 'VAT Summary'),
            description: t('accounting.reports.vat_desc', 'Tax collected, period totals, filing-ready format'),
            href: route('accounting.reports.vat'),
            icon: <ReceiptIcon className="w-6 h-6" />,
            color: 'text-orange-600 bg-orange-50',
        },
        {
            title: t('accounting.reports.reconciliation_title', 'Wallet vs Ledger Reconciliation'),
            description: t('accounting.reports.reconciliation_desc', 'Detect mismatches between wallet balances and ledger accounts'),
            href: route('accounting.reports.reconciliation'),
            icon: <ScaleIcon className="w-6 h-6" />,
            color: 'text-purple-600 bg-purple-50',
        },
        {
            title: t('accounting.reports.trial_balance_title', 'Trial Balance'),
            description: t('accounting.reports.trial_balance_desc', 'All accounts debit/credit summary for monthly close'),
            href: route('accounting.ledger.trial-balance'),
            icon: <ClipboardListIcon className="w-6 h-6" />,
            color: 'text-slate-600 bg-slate-50',
        },
        {
            title: t('accounting.reports.aging_title', 'Merchant Settlement Aging'),
            description: t('accounting.reports.aging_desc', 'Aged receivables from network merchants by time bucket'),
            href: route('accounting.settlement.aging'),
            icon: <UsersIcon className="w-6 h-6" />,
            color: 'text-amber-600 bg-amber-50',
        },
    ];

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.reports', 'Reports')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div>
                        <h1 className="text-2xl font-semibold">{t('accounting.nav.reports', 'Reports')}</h1>
                        <p className="text-sm text-gray-500 mt-1">{t('accounting.reports.subtitle', 'Financial reports and analytics for your agency')}</p>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        {reports.map(report => (
                            <Link key={report.href} href={report.href}>
                                <Card className="p-5 hover:shadow-md transition-shadow cursor-pointer h-full">
                                    <div className="flex items-start gap-4">
                                        <div className={`p-2 rounded-lg ${report.color}`}>
                                            {report.icon}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <h3 className="font-semibold text-gray-900">{report.title}</h3>
                                            <p className="text-sm text-gray-500 mt-1">{report.description}</p>
                                        </div>
                                    </div>
                                </Card>
                            </Link>
                        ))}
                    </div>
                </div>
            </AccountingLayout>
        </TenantLayout>
    );
}
