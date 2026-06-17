import React from 'react';
import { Head, router } from '@inertiajs/react';
import {
    ChevronRight,
    ChevronDown,
    Plus,
    Pencil,
    Trash2,
    List,
    GitBranch,
} from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
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

const TYPE_COLORS = {
    asset: 'bg-blue-100 text-blue-700 border-blue-200',
    liability: 'bg-red-100 text-red-700 border-red-200',
    equity: 'bg-purple-100 text-purple-700 border-purple-200',
    revenue: 'bg-green-100 text-green-700 border-green-200',
    expense: 'bg-orange-100 text-orange-700 border-orange-200',
};

const TYPE_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense'];
const TYPE_LABELS = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

function AccountTreeNode({ account, allAccounts, expandedNodes, toggleExpand, onEdit, onDelete, depth = 0 }) {
    const children = allAccounts.filter((a) => a.parentUuid === account.uuid);
    const hasChildren = children.length > 0;
    const isExpanded = expandedNodes.has(account.uuid);

    return (
        <React.Fragment>
            <TableRow className="hover:bg-muted/50">
                <TableCell style={{ paddingLeft: `${depth * 24 + 16}px` }}>
                    <div className="flex items-center gap-1">
                        {hasChildren ? (
                            <button
                                onClick={() => toggleExpand(account.uuid)}
                                className="inline-flex items-center justify-center rounded p-0.5 text-muted-foreground hover:text-foreground hover:bg-muted"
                                aria-label={isExpanded ? 'Collapse' : 'Expand'}
                            >
                                {isExpanded ? (
                                    <ChevronDown className="size-3.5" />
                                ) : (
                                    <ChevronRight className="size-3.5" />
                                )}
                            </button>
                        ) : (
                            <span className="inline-block w-5" />
                        )}
                        <code className="text-xs text-muted-foreground">{account.code}</code>
                    </div>
                </TableCell>
                <TableCell className="text-sm font-medium">{account.name}</TableCell>
                <TableCell>
                    <span
                        className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold capitalize ${TYPE_COLORS[account.type] ?? ''}`}
                    >
                        {account.type}
                    </span>
                </TableCell>
                <TableCell className="text-right text-sm">
                    <AmountDisplay amount={account.balance} colorize />
                </TableCell>
                <TableCell>
                    <div className="flex items-center justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            onClick={() => onEdit(account)}
                            title="Edit"
                        >
                            <Pencil className="size-3" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            className="text-destructive hover:text-destructive"
                            onClick={() => onDelete(account)}
                            title="Delete"
                        >
                            <Trash2 className="size-3" />
                        </Button>
                    </div>
                </TableCell>
            </TableRow>
            {isExpanded &&
                children.map((child) => (
                    <AccountTreeNode
                        key={child.uuid}
                        account={child}
                        allAccounts={allAccounts}
                        expandedNodes={expandedNodes}
                        toggleExpand={toggleExpand}
                        onEdit={onEdit}
                        onDelete={onDelete}
                        depth={depth + 1}
                    />
                ))}
        </React.Fragment>
    );
}

export default function ChartOfAccounts({ accounts, view }) {
    const { t } = useTranslation();

    const currentView = view || 'list';

    // Modal state
    const [addModalOpen, setAddModalOpen] = React.useState(false);
    const [editModalOpen, setEditModalOpen] = React.useState(false);
    const [editingAccount, setEditingAccount] = React.useState(null);

    // Add form state
    const [addCode, setAddCode] = React.useState('');
    const [addName, setAddName] = React.useState('');
    const [addParentUuid, setAddParentUuid] = React.useState('');

    // Edit form state
    const [editName, setEditName] = React.useState('');

    // Tree expanded state
    const [expandedNodes, setExpandedNodes] = React.useState(new Set());

    function toggleExpand(uuid) {
        setExpandedNodes((prev) => {
            const next = new Set(prev);
            if (next.has(uuid)) {
                next.delete(uuid);
            } else {
                next.add(uuid);
            }
            return next;
        });
    }

    function openAddModal() {
        setAddCode('');
        setAddName('');
        setAddParentUuid('');
        setAddModalOpen(true);
    }

    function handleAddSubmit(e) {
        e.preventDefault();
        router.post(route('accounting.ledger.coa.store'), {
            code: addCode,
            name: addName,
            parent_uuid: addParentUuid || null,
        }, {
            onSuccess: () => setAddModalOpen(false),
        });
    }

    function openEditModal(account) {
        setEditingAccount(account);
        setEditName(account.name);
        setEditModalOpen(true);
    }

    function handleEditSubmit(e) {
        e.preventDefault();
        if (!editingAccount) return;
        router.put(
            route('accounting.ledger.coa.update', editingAccount.code),
            { name: editName },
            {
                onSuccess: () => setEditModalOpen(false),
            },
        );
    }

    function handleDelete(account) {
        if (!window.confirm(`Delete account "${account.code} — ${account.name}"? This action cannot be undone.`)) {
            return;
        }
        router.delete(route('accounting.ledger.coa.destroy', account.code));
    }

    // Group accounts by type for list view
    const grouped = TYPE_ORDER.reduce((acc, type) => {
        acc[type] = accounts.filter((a) => a.type === type);
        return acc;
    }, {});

    // Build UUID set for parent lookup
    const accountLookup = React.useMemo(() => {
        const map = {};
        accounts.forEach((a) => {
            map[a.uuid] = a;
        });
        return map;
    }, [accounts]);

    const accountUuids = React.useMemo(() => new Set(accounts.map((a) => a.uuid)), [accounts]);

    // Roots for tree view: accounts whose parentUuid is null or not in our CoA list
    const rootAccounts = accounts.filter((a) => !a.parentUuid || !accountUuids.has(a.parentUuid));

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.coa')} />
            <AccountingLayout
                title={t('accounting.nav.coa')}
                subtitle="All ledger accounts and current balances"
                actions={
                    <div className="flex items-center gap-2">
                        {/* View toggle */}
                        <div className="inline-flex items-center rounded-md border bg-muted/40 p-0.5">
                            <button
                                onClick={() =>
                                    router.get(route('accounting.ledger.coa'), { view: 'list' }, { preserveState: true })
                                }
                                className={`inline-flex items-center gap-1 rounded-sm px-2.5 py-1 text-xs font-medium transition-colors ${
                                    currentView === 'list'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <List className="size-3.5" />
                                List
                            </button>
                            <button
                                onClick={() =>
                                    router.get(route('accounting.ledger.coa'), { view: 'tree' }, { preserveState: true })
                                }
                                className={`inline-flex items-center gap-1 rounded-sm px-2.5 py-1 text-xs font-medium transition-colors ${
                                    currentView === 'tree'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <GitBranch className="size-3.5" />
                                Tree
                            </button>
                        </div>

                        <Button size="sm" onClick={openAddModal}>
                            <Plus className="mr-1 size-3.5" /> Add Account
                        </Button>
                    </div>
                }
            >
                <Card>
                    {currentView === 'list' ? (
                        /* ───── List View ───── */
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Account Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                    <TableHead className="w-[80px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {accounts.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No accounts found. Ledger may not be bootstrapped yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    TYPE_ORDER.map((type) => {
                                        const typeRows = grouped[type] ?? [];
                                        if (typeRows.length === 0) return null;
                                        return (
                                            <React.Fragment key={type}>
                                                <TableRow className="bg-muted/40">
                                                    <TableCell
                                                        colSpan={5}
                                                        className="py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                                                    >
                                                        {TYPE_LABELS[type]}
                                                    </TableCell>
                                                </TableRow>
                                                {typeRows.map((account) => (
                                                    <TableRow key={account.code}>
                                                        <TableCell>
                                                            <code className="text-xs text-muted-foreground">
                                                                {account.code}
                                                            </code>
                                                        </TableCell>
                                                        <TableCell className="text-sm font-medium">
                                                            {account.name}
                                                        </TableCell>
                                                        <TableCell>
                                                            <span
                                                                className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold capitalize ${TYPE_COLORS[account.type] ?? ''}`}
                                                            >
                                                                {account.type}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="text-right text-sm">
                                                            <AmountDisplay amount={account.balance} colorize />
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="flex items-center justify-end gap-1">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon-xs"
                                                                    onClick={() => openEditModal(account)}
                                                                    title="Edit"
                                                                >
                                                                    <Pencil className="size-3" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon-xs"
                                                                    className="text-destructive hover:text-destructive"
                                                                    onClick={() => handleDelete(account)}
                                                                    title="Delete"
                                                                >
                                                                    <Trash2 className="size-3" />
                                                                </Button>
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </React.Fragment>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    ) : (
                        /* ───── Tree View ───── */
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Account Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                    <TableHead className="w-[80px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rootAccounts.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No accounts found. Ledger may not be bootstrapped yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rootAccounts.map((account) => (
                                        <AccountTreeNode
                                            key={account.uuid}
                                            account={account}
                                            allAccounts={accounts}
                                            expandedNodes={expandedNodes}
                                            toggleExpand={toggleExpand}
                                            onEdit={openEditModal}
                                            onDelete={handleDelete}
                                        />
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    )}
                </Card>
            </AccountingLayout>

            {/* ───── Add Account Modal ───── */}
            <Dialog open={addModalOpen} onOpenChange={setAddModalOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Account</DialogTitle>
                        <DialogDescription>
                            Create a new account in the chart of accounts.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleAddSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="add-code">Code</Label>
                            <Input
                                id="add-code"
                                value={addCode}
                                onChange={(e) => setAddCode(e.target.value)}
                                placeholder="e.g. 1100"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="add-name">Name</Label>
                            <Input
                                id="add-name"
                                value={addName}
                                onChange={(e) => setAddName(e.target.value)}
                                placeholder="e.g. Cash"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="add-parent">Parent Account</Label>
                            <Select
                                value={addParentUuid}
                                onValueChange={(value) => setAddParentUuid(value === '__none__' ? '' : value)}
                            >
                                <SelectTrigger id="add-parent" className="w-full">
                                    <SelectValue placeholder="None (top-level)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">None (top-level)</SelectItem>
                                    {accounts.map((account) => (
                                        <SelectItem key={account.uuid} value={account.uuid}>
                                            {account.code} — {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <DialogFooter>
                            <Button type="submit">Create Account</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ───── Edit Account Modal ───── */}
            <Dialog open={editModalOpen} onOpenChange={setEditModalOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Account</DialogTitle>
                        <DialogDescription>
                            {editingAccount ? (
                                <>
                                    Rename account <code className="text-xs">{editingAccount.code}</code>.
                                </>
                            ) : (
                                'Update the account name.'
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleEditSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="edit-name">Account Name</Label>
                            <Input
                                id="edit-name"
                                value={editName}
                                onChange={(e) => setEditName(e.target.value)}
                                placeholder="Account name"
                                required
                            />
                        </div>
                        <DialogFooter>
                            <Button type="submit">Save Changes</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </TenantLayout>
    );
}
