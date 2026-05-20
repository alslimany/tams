import { Head, Link } from '@inertiajs/react';
import { ArrowRightIcon, ServerIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Badge } from '@/Components/ui/Badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { useTranslation } from '@/hooks/useTranslation';

const LOW_BALANCE_THRESHOLD = 500;

export default function ProvidersIndex({ providers }) {
    const { t } = useTranslation();

    const allWallets = providers.flatMap((p) =>
        p.wallets.map((w) => ({ ...w, providerName: p.name, providerId: p.id })),
    );

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.providers')} />
            <AccountingLayout
                title={t('accounting.nav.providers')}
                subtitle="Provider wallet balances and activity"
            >
                {allWallets.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16 text-muted-foreground">
                            <ServerIcon className="mb-3 size-10 opacity-30" />
                            <p className="text-sm">No providers configured for this agency.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {providers.map((provider) =>
                            provider.wallets.map((wallet) => {
                                const isLow = wallet.balance < LOW_BALANCE_THRESHOLD;
                                return (
                                    <Card
                                        key={wallet.id}
                                        className={isLow ? 'border-red-300 bg-red-50 dark:bg-red-950/20' : ''}
                                    >
                                        <CardHeader className="pb-2">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <CardTitle className="truncate text-sm font-semibold">
                                                        {provider.name}
                                                    </CardTitle>
                                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                        {wallet.name}
                                                    </p>
                                                </div>
                                                <div className="flex shrink-0 flex-col items-end gap-1">
                                                    {provider.isActive ? (
                                                        <Badge variant="success" className="text-xs">Active</Badge>
                                                    ) : (
                                                        <Badge variant="secondary" className="text-xs">Inactive</Badge>
                                                    )}
                                                    {isLow && (
                                                        <Badge variant="destructive" className="text-xs">Low Balance</Badge>
                                                    )}
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardContent>
                                            <p className="text-2xl font-bold">
                                                <AmountDisplay amount={wallet.balance} currency={wallet.currency} />
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {wallet.todayTransactionCount} transaction
                                                {wallet.todayTransactionCount !== 1 ? 's' : ''} today
                                            </p>
                                            <div className="mt-3 flex items-center gap-2">
                                                <Link
                                                    href={route('accounting.providers.show', provider.id)}
                                                    className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    View detail <ArrowRightIcon className="size-3" />
                                                </Link>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            }),
                        )}
                    </div>
                )}
            </AccountingLayout>
        </TenantLayout>
    );
}
