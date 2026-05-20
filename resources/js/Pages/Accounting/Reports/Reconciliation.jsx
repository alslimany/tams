import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2Icon, XCircleIcon, RefreshCwIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import ExportButton from '@/Components/Accounting/ExportButton';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function Reconciliation({ lastRunAt, results, overallStatus }) {
    const { t } = useTranslation();
    const [rerunning, setRerunning] = useState(false);

    function rerun() {
        setRerunning(true);
        router.get(route('accounting.reports.reconciliation'), {}, {
            onFinish: () => setRerunning(false),
        });
    }

    const mismatchCount = results.filter(r => r.status === 'mismatch').length;

    return (
        <TenantLayout>
            <Head title={t('accounting.reports.reconciliation_title', 'Wallet vs Ledger Reconciliation')} />
            <AccountingLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold">{t('accounting.reports.reconciliation_title', 'Wallet vs Ledger Reconciliation')}</h1>
                            <p className="text-sm text-gray-500 mt-1">
                                {t('accounting.reports.last_run', 'Last run')}: {new Date(lastRunAt).toLocaleString()}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={rerun} disabled={rerunning}>
                                <RefreshCwIcon className={`w-4 h-4 mr-2 ${rerunning ? 'animate-spin' : ''}`} />
                                {t('accounting.reports.rerun', 'Re-run Now')}
                            </Button>
                            <ExportButton url={route('accounting.reports.reconciliation') + '?export=csv'} label={t('common.export_csv', 'Export CSV')} />
                        </div>
                    </div>

                    {/* Overall status banner */}
                    {overallStatus === 'all_matched' ? (
                        <Alert className="border-green-200 bg-green-50">
                            <CheckCircle2Icon className="w-4 h-4 text-green-600" />
                            <AlertDescription className="text-green-700 font-medium">
                                {t('accounting.reports.all_matched', '✓ All wallets match their ledger accounts')}
                            </AlertDescription>
                        </Alert>
                    ) : (
                        <Alert className="border-red-200 bg-red-50">
                            <XCircleIcon className="w-4 h-4 text-red-600" />
                            <AlertDescription className="text-red-700 font-medium">
                                {t('accounting.reports.has_mismatches', '✗ {count} mismatch(es) found — investigate immediately').replace('{count}', String(mismatchCount))}
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Reconciliation table */}
                    <Card>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('accounting.reports.wallet', 'Wallet')}</TableHead>
                                        <TableHead>{t('accounting.reports.ledger_account', 'Ledger A/C')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.wallet_balance', 'Wallet Balance')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.ledger_balance', 'Ledger Balance')}</TableHead>
                                        <TableHead className="text-right">{t('accounting.reports.difference', 'Δ Difference')}</TableHead>
                                        <TableHead>{t('accounting.reports.status', 'Status')}</TableHead>
                                        <TableHead></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {results.map(row => (
                                        <TableRow key={row.ledgerAccount} className={row.status === 'mismatch' ? 'bg-red-50' : ''}>
                                            <TableCell className="font-medium">{row.walletName}</TableCell>
                                            <TableCell className="font-mono text-sm text-gray-600">{row.ledgerAccount}</TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay amount={row.walletBalance} currency="LYD" />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <AmountDisplay amount={row.ledgerBalance} currency="LYD" />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.difference === 0
                                                    ? <span className="text-gray-400">—</span>
                                                    : <span className="text-red-600 font-medium"><AmountDisplay amount={row.difference} currency="LYD" /></span>}
                                            </TableCell>
                                            <TableCell>
                                                {row.status === 'matched' ? (
                                                    <span className="inline-flex items-center gap-1 text-green-700 text-sm">
                                                        <CheckCircle2Icon className="w-4 h-4" />
                                                        {t('accounting.reports.matched', 'Matched')}
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 text-red-700 text-sm font-medium">
                                                        <XCircleIcon className="w-4 h-4" />
                                                        {t('accounting.reports.mismatch', 'Mismatch')}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {row.status === 'mismatch' && (
                                                    <Button size="sm" variant="outline" className="text-red-600 border-red-200 hover:bg-red-50" onClick={() => router.get(route('accounting.ledger.account', row.ledgerAccount))}>
                                                        {t('accounting.reports.investigate', 'Investigate')}
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </Card>
                </div>
            </AccountingLayout>
        </TenantLayout>
    );
}
