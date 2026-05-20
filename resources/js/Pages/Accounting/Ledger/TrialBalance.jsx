import React from 'react';
import { Head, router } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const TYPE_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense'];
const TYPE_LABELS = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

export default function TrialBalance({ period, rows, totals, isBalanced }) {
    const { t } = useTranslation();
    const [showZero, setShowZero] = React.useState(false);

    const grouped = TYPE_ORDER.reduce((acc, type) => {
        acc[type] = rows.filter((r) => r.type === type && (showZero || r.debit !== 0 || r.credit !== 0));
        return acc;
    }, {});

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.trial_balance')} />
            <AccountingLayout
                title={t('accounting.nav.trial_balance')}
                subtitle={`As of ${period.asOf}`}
                actions={
                    <div className="flex items-center gap-2">
                        <label className="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground">
                            <input
                                type="checkbox"
                                checked={showZero}
                                onChange={(e) => setShowZero(e.target.checked)}
                                className="rounded"
                            />
                            Show zero balances
                        </label>
                        <input
                            type="date"
                            value={period.asOf}
                            onChange={(e) =>
                                router.get(
                                    route('accounting.ledger.trial-balance'),
                                    { asOf: e.target.value },
                                    { preserveState: true },
                                )
                            }
                            className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        />
                        <Button variant="outline" size="sm" onClick={() => window.print()}>
                            <PrinterIcon className="mr-1.5 size-3.5" /> Print
                        </Button>
                        {isBalanced ? (
                            <Badge variant="success">Balanced</Badge>
                        ) : (
                            <Badge variant="destructive">Imbalance</Badge>
                        )}
                    </div>
                }
            >
                {!isBalanced && (
                    <Alert variant="destructive" className="mb-4">
                        <AlertDescription>
                            Trial balance is not balanced — total debits do not equal total credits. Please
                            investigate unbalanced journal entries.
                        </AlertDescription>
                    </Alert>
                )}

                <Card className="print:shadow-none">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Account Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">Debit</TableHead>
                                <TableHead className="text-right">Credit</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {TYPE_ORDER.map((type) => {
                                const typeRows = grouped[type] ?? [];
                                if (typeRows.length === 0) return null;
                                return (
                                    <React.Fragment key={type}>
                                        {/* Section header */}
                                        <TableRow className="bg-muted/40">
                                            <TableCell
                                                colSpan={5}
                                                className="py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                                            >
                                                {TYPE_LABELS[type]}
                                            </TableCell>
                                        </TableRow>
                                        {typeRows.map((row) => (
                                            <TableRow key={row.code}>
                                                <TableCell>
                                                    <code className="text-xs text-muted-foreground">
                                                        {row.code}
                                                    </code>
                                                </TableCell>
                                                <TableCell className="text-sm">{row.name}</TableCell>
                                                <TableCell>
                                                    <span className="text-xs capitalize text-muted-foreground">
                                                        {row.type}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right text-sm">
                                                    {row.debit > 0 ? (
                                                        <AmountDisplay amount={row.debit} />
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-sm">
                                                    {row.credit > 0 ? (
                                                        <AmountDisplay amount={row.credit} />
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </React.Fragment>
                                );
                            })}

                            {/* Totals row */}
                            <TableRow className="border-t-2 font-bold">
                                <TableCell colSpan={3} className="text-sm font-semibold">
                                    TOTALS
                                </TableCell>
                                <TableCell className="text-right text-sm">
                                    <AmountDisplay amount={totals.debit} />
                                </TableCell>
                                <TableCell className="text-right text-sm">
                                    <AmountDisplay amount={totals.credit} />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
