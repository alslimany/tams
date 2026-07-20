import React from 'react';
import { Head, router } from '@inertiajs/react';
import { RotateCcw, Save } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AccountCombobox from '@/Components/Accounting/AccountCombobox';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/Components/ui/Dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

const CATEGORY_LABELS = {
    airline: 'Airline',
    hotel: 'Hotel',
    insurance: 'Insurance',
    esim: 'eSIM',
    inventory: 'Inventory',
    general: 'General',
};

const CATEGORY_ORDER = ['airline', 'hotel', 'insurance', 'esim', 'inventory', 'general'];

function eventLabel(eventType) {
    return eventType
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ')
        .replace(/^Sale /, 'Sale — ')
        .replace(/^Void /, 'Void — ')
        .replace(/^Cost /, 'Cost — ');
}

export default function AccountRouting({ routings, accounts }) {
    const { t } = useTranslation();

    const [categoryFilter, setCategoryFilter] = React.useState('all');
    const [draft, setDraft] = React.useState(() => {
        const map = {};
        routings.forEach((r) => {
            map[r.id] = { debit_account: r.debitAccount, credit_account: r.creditAccount };
        });
        return map;
    });
    const [resetDialogOpen, setResetDialogOpen] = React.useState(false);
    const [saving, setSaving] = React.useState(false);

    const isDirty = React.useMemo(
        () =>
            routings.some(
                (r) =>
                    draft[r.id]?.debit_account !== r.debitAccount ||
                    draft[r.id]?.credit_account !== r.creditAccount,
            ),
        [routings, draft],
    );

    function setAccount(id, side, code) {
        setDraft((prev) => ({
            ...prev,
            [id]: { ...prev[id], [side]: code },
        }));
    }

    function handleSave() {
        setSaving(true);
        router.put(
            route('accounting.settings.routing.update'),
            {
                routings: routings.map((r) => ({
                    id: r.id,
                    debit_account: draft[r.id]?.debit_account ?? null,
                    credit_account: draft[r.id]?.credit_account ?? null,
                })),
            },
            {
                onFinish: () => setSaving(false),
            },
        );
    }

    function handleReset() {
        setResetDialogOpen(false);
        router.post(route('accounting.settings.routing.reset'), {}, { preserveScroll: true });
    }

    const grouped = React.useMemo(() => {
        const byCategory = {};
        routings.forEach((r) => {
            (byCategory[r.eventCategory] ??= []).push(r);
        });
        return byCategory;
    }, [routings]);

    const visibleCategories = CATEGORY_ORDER.filter(
        (category) =>
            (grouped[category] ?? []).length > 0 &&
            (categoryFilter === 'all' || categoryFilter === category),
    );

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.account_routing')} />
            <AccountingLayout
                title={t('accounting.nav.account_routing')}
                subtitle="Configure which debit and credit accounts are used for each financial event"
                actions={
                    <div className="flex items-center gap-2">
                        <Select value={categoryFilter} onValueChange={setCategoryFilter}>
                            <SelectTrigger className="w-[150px]">
                                <SelectValue placeholder="Filter category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All categories</SelectItem>
                                {CATEGORY_ORDER.map((category) => (
                                    <SelectItem key={category} value={category}>
                                        {CATEGORY_LABELS[category]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button variant="outline" size="sm" onClick={() => setResetDialogOpen(true)}>
                            <RotateCcw className="mr-1 size-3.5" /> Reset to Defaults
                        </Button>
                        <Button size="sm" onClick={handleSave} disabled={!isDirty || saving}>
                            <Save className="mr-1 size-3.5" /> Save Changes
                        </Button>
                    </div>
                }
            >
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[280px]">Event</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead className="w-[260px]">Debit A/C</TableHead>
                                <TableHead className="w-[260px]">Credit A/C</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visibleCategories.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={4} className="py-10 text-center text-sm text-muted-foreground">
                                        No routing rows found. Run the routing backfill for this tenant.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                visibleCategories.map((category) => (
                                    <React.Fragment key={category}>
                                        <TableRow className="bg-muted/40">
                                            <TableCell
                                                colSpan={4}
                                                className="py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                                            >
                                                {CATEGORY_LABELS[category]}
                                            </TableCell>
                                        </TableRow>
                                        {grouped[category].map((routing) => (
                                            <TableRow key={routing.id}>
                                                <TableCell className="text-sm font-medium">
                                                    {eventLabel(routing.eventType)}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    {routing.description}
                                                </TableCell>
                                                <TableCell>
                                                    {routing.debitAccount !== null ? (
                                                        <AccountCombobox
                                                            value={draft[routing.id]?.debit_account ?? ''}
                                                            onChange={(code) => setAccount(routing.id, 'debit_account', code)}
                                                            accounts={accounts}
                                                        />
                                                    ) : (
                                                        <span className="px-3 text-sm text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {routing.creditAccount !== null ? (
                                                        <AccountCombobox
                                                            value={draft[routing.id]?.credit_account ?? ''}
                                                            onChange={(code) => setAccount(routing.id, 'credit_account', code)}
                                                            accounts={accounts}
                                                        />
                                                    ) : (
                                                        <span className="px-3 text-sm text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </React.Fragment>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </AccountingLayout>

            {/* Reset confirmation */}
            <Dialog open={resetDialogOpen} onOpenChange={setResetDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reset routing to defaults?</DialogTitle>
                        <DialogDescription>
                            All account routing rows will be reverted to the system defaults. Any custom
                            account assignments will be lost. This does not affect journal entries that
                            were already posted.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setResetDialogOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={handleReset}>
                            Reset to Defaults
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </TenantLayout>
    );
}
