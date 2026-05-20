import { Head, Link } from '@inertiajs/react';
import { ArrowRightIcon, WalletIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Card, CardContent } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const typeBadge = {
    operating: { label: 'Operating', className: 'bg-blue-100 text-blue-700 border-blue-200' },
    provider:  { label: 'Provider',  className: 'bg-purple-100 text-purple-700 border-purple-200' },
    merchant:  { label: 'Merchant',  className: 'bg-amber-100 text-amber-700 border-amber-200' },
};

export default function WalletsIndex({ wallets }) {
    const { t } = useTranslation();

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.wallets')} />
            <AccountingLayout
                title={t('accounting.nav.wallets')}
                subtitle="All wallets and their current balances"
            >
                {wallets.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16 text-muted-foreground">
                            <WalletIcon className="mb-3 size-10 opacity-30" />
                            <p className="text-sm">No wallets found for this agency.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Wallet Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Ledger Account</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                    <TableHead>Last Activity</TableHead>
                                    <TableHead className="text-right">Transactions</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {wallets.map((wallet) => {
                                    const badge = typeBadge[wallet.type] ?? typeBadge.provider;
                                    return (
                                        <TableRow key={wallet.id}>
                                            <TableCell className="font-medium">{wallet.name}</TableCell>
                                            <TableCell>
                                                <span
                                                    className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold ${badge.className}`}
                                                >
                                                    {badge.label}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                    {wallet.ledgerAccount}
                                                </code>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay
                                                    amount={wallet.balance}
                                                    currency={wallet.currency}
                                                    colorize
                                                />
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {wallet.lastActivityAt
                                                    ? new Date(wallet.lastActivityAt).toLocaleDateString()
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-right text-sm">
                                                {wallet.transactionCount.toLocaleString()}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={route('accounting.wallets.show', wallet.id)}
                                                    className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    View <ArrowRightIcon className="size-3" />
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </AccountingLayout>
        </TenantLayout>
    );
}
