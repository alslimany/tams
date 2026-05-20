import React from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangleIcon,
    ArrowRightIcon,
    CheckCircle2Icon,
    TrendingUpIcon,
    WalletIcon,
    XCircleIcon,
} from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AccountingKpiCard from '@/Components/Accounting/AccountingKpiCard';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import PeriodSelector from '@/Components/Accounting/PeriodSelector';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Badge } from '@/Components/ui/Badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { useTranslation } from '@/hooks/useTranslation';

export default function Dashboard({
    walletSummary,
    period,
    revenue,
    costOfSales,
    grossMargin,
    grossMarginPct,
    vatPayable,
    outstandingReceivables,
    outstandingPayables,
    reconciliationStatus,
    alerts,
}) {
    const { t } = useTranslation();

    const productLabels = {
        airline: 'Airline',
        hotel: 'Hotel',
        insurance: 'Insurance',
        esim: 'eSIM',
        other: 'Other',
    };

    return (
        <TenantLayout>
            <Head title="Accounting Dashboard" />
            <AccountingLayout
                title={t('accounting.nav.dashboard')}
                subtitle="Financial overview for the current period"
                actions={
                    <PeriodSelector
                        period={period}
                        routeName="accounting.dashboard"
                    />
                }
            >
                {/* Alert bar */}
                {alerts.length > 0 && (
                    <div className="mb-6 space-y-2">
                        {alerts.map((alert, i) => (
                            <Alert
                                key={i}
                                variant={alert.severity === 'danger' ? 'destructive' : 'default'}
                                className={alert.severity === 'warning' ? 'border-amber-300 bg-amber-50 text-amber-800' : ''}
                            >
                                <AlertTriangleIcon className="size-4" />
                                <AlertDescription>{alert.message}</AlertDescription>
                            </Alert>
                        ))}
                    </div>
                )}

                {/* KPI cards */}
                <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <AccountingKpiCard
                        title="Operating Wallet"
                        value={walletSummary.operatingBalance}
                        icon={<WalletIcon className="size-4" />}
                        linkTo={route('accounting.wallets.index')}
                    />
                    <AccountingKpiCard
                        title="Total Revenue"
                        value={revenue.total}
                        icon={<TrendingUpIcon className="size-4" />}
                        linkTo={route('accounting.reports.revenue')}
                    />
                    <AccountingKpiCard
                        title="Gross Margin"
                        value={`${grossMarginPct}%`}
                        isAmount={false}
                        variant={grossMarginPct < 5 ? 'danger' : grossMarginPct < 15 ? 'warning' : 'default'}
                        linkTo={route('accounting.reports.gross-margin')}
                    />
                    <AccountingKpiCard
                        title="VAT Payable"
                        value={vatPayable}
                        linkTo={route('accounting.reports.vat')}
                    />
                </div>

                {/* Provider wallet cards */}
                {walletSummary.providerWallets.length > 0 && (
                    <div className="mb-6">
                        <h2 className="mb-3 text-sm font-medium text-muted-foreground">Provider Wallets</h2>
                        <div className="flex gap-3 overflow-x-auto pb-2">
                            {walletSummary.providerWallets.map((pw) => (
                                <Card
                                    key={pw.key}
                                    className={`min-w-48 shrink-0 ${pw.balance < 500 ? 'border-red-300 bg-red-50' : ''}`}
                                >
                                    <CardHeader className="pb-1 pt-3 px-4">
                                        <CardTitle className="text-xs font-medium text-muted-foreground truncate">
                                            {pw.name}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="px-4 pb-3">
                                        <p className="text-lg font-bold">
                                            <AmountDisplay amount={pw.balance} currency={pw.currency} />
                                        </p>
                                        {pw.balance < 500 && (
                                            <Badge variant="destructive" className="mt-1 text-xs">
                                                Low Balance
                                            </Badge>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </div>
                )}

                {/* Revenue by product + Receivables/Payables */}
                <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {/* Revenue by product */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Revenue by Product</CardTitle>
                            <Link
                                href={route('accounting.reports.revenue')}
                                className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
                            >
                                View report <ArrowRightIcon className="size-3" />
                            </Link>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {Object.entries(revenue.byProduct).map(([product, amount]) => (
                                    <div key={product} className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">{productLabels[product] ?? product}</span>
                                        <AmountDisplay amount={amount} />
                                    </div>
                                ))}
                                <div className="flex items-center justify-between border-t pt-2 text-sm font-semibold">
                                    <span>Total</span>
                                    <AmountDisplay amount={revenue.total} />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Receivables / Payables + Reconciliation */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium">Outstanding Balances</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">Receivables</span>
                                    <Link
                                        href={route('accounting.settlement.aging')}
                                        className="flex items-center gap-1 hover:underline"
                                    >
                                        <AmountDisplay amount={outstandingReceivables} colorize />
                                        <ArrowRightIcon className="size-3 text-muted-foreground" />
                                    </Link>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">Payables</span>
                                    <AmountDisplay amount={outstandingPayables} colorize />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium">Reconciliation Status</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center justify-between">
                                    {reconciliationStatus === 'all_matched' ? (
                                        <div className="flex items-center gap-2 text-green-600 text-sm">
                                            <CheckCircle2Icon className="size-4" />
                                            <span>All wallets matched</span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center gap-2 text-red-600 text-sm">
                                            <XCircleIcon className="size-4" />
                                            <span>Mismatches found</span>
                                        </div>
                                    )}
                                    <Link
                                        href={route('accounting.reports.reconciliation')}
                                        className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
                                    >
                                        View <ArrowRightIcon className="size-3" />
                                    </Link>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </AccountingLayout>
        </TenantLayout>
    );
}
