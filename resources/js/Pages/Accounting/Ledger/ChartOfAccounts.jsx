import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronRight,
    ChevronDown,
    Plus,
    Pencil,
    Trash2,
    Lock,
    Search,
    Eye,
} from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import AccountCombobox from '@/Components/Accounting/AccountCombobox';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Switch } from '@/Components/ui/Switch';
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
    purchase: 'bg-amber-100 text-amber-700 border-amber-200',
};

const TYPE_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense', 'purchase'];
const TYPE_LABELS = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
    purchase: 'Purchases',
};

function AccountRow({ account, depth = 0, hasChildren, isExpanded, onToggle, onEdit, onAddChild, onDelete }) {
    const deletable = !account.isSystem && !account.hasActivity && !hasChildren;

    return (
        <TableRow className={`hover:bg-muted/50 ${account.isActive === false ? 'opacity-50' : ''}`}>
            <TableCell style={{ paddingLeft: `${depth * 24 + 16}px` }}>
                <div className="flex items-center gap-1">
                    {hasChildren ? (
                        <button
                            onClick={onToggle}
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
            <TableCell className="text-sm font-medium">
                <span className="inline-flex items-center gap-1.5">
                    {account.name}
                    {account.isSystem && (
                        <Lock className="size-3 text-muted-foreground" title="System account — cannot be deleted" />
                    )}
                    {account.isActive === false && (
                        <span className="rounded border px-1.5 py-0 text-[10px] uppercase tracking-wide text-muted-foreground">
                            Inactive
                        </span>
                    )}
                </span>
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
                        onClick={() => onAddChild(account)}
                        title="Add child account"
                    >
                        <Plus className="size-3" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-xs"
                        asChild
                        title="View account"
                    >
                        <Link href={route('accounting.ledger.account', account.code)}>
                            <Eye className="size-3" />
                        </Link>
                    </Button>
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
                        className="text-destructive hover:text-destructive disabled:opacity-30"
                        onClick={() => onDelete(account)}
                        disabled={!deletable}
                        title={
                            account.isSystem
                                ? 'System accounts cannot be deleted'
                                : account.hasActivity
                                  ? 'Accounts with journal activity cannot be deleted'
                                  : hasChildren
                                    ? 'Accounts with sub-accounts cannot be deleted'
                                    : 'Delete'
                        }
                    >
                        <Trash2 className="size-3" />
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}

function AccountTreeNode({ account, childrenByParent, expandedNodes, toggleExpand, onEdit, onAddChild, onDelete, depth = 0 }) {
    const children = childrenByParent[account.uuid] ?? [];
    const hasChildren = children.length > 0;
    const isExpanded = expandedNodes.has(account.uuid);

    return (
        <React.Fragment>
            <AccountRow
                account={account}
                depth={depth}
                hasChildren={hasChildren}
                isExpanded={isExpanded}
                onToggle={() => toggleExpand(account.uuid)}
                onEdit={onEdit}
                onAddChild={onAddChild}
                onDelete={onDelete}
            />
            {isExpanded &&
                children.map((child) => (
                    <AccountTreeNode
                        key={child.uuid}
                        account={child}
                        childrenByParent={childrenByParent}
                        expandedNodes={expandedNodes}
                        toggleExpand={toggleExpand}
                        onEdit={onEdit}
                        onAddChild={onAddChild}
                        onDelete={onDelete}
                        depth={depth + 1}
                    />
                ))}
        </React.Fragment>
    );
}

export default function ChartOfAccounts({ accounts }) {
    const { t } = useTranslation();

    // Filters
    const [search, setSearch] = React.useState('');
    const [typeFilter, setTypeFilter] = React.useState('all');

    // Modal state
    const [addModalOpen, setAddModalOpen] = React.useState(false);
    const [editModalOpen, setEditModalOpen] = React.useState(false);
    const [editingAccount, setEditingAccount] = React.useState(null);

    // Add form
    const addForm = useForm({
        code: '',
        name: '',
        type: 'expense',
        parent: '',
        description: '',
        is_active: true,
    });
    const [parentLocked, setParentLocked] = React.useState(false);

    // Edit form state
    const [editName, setEditName] = React.useState('');
    const [editDescription, setEditDescription] = React.useState('');
    const [editActive, setEditActive] = React.useState(true);

    // Tree expanded state — expand top-level groups by default
    const [expandedNodes, setExpandedNodes] = React.useState(() => {
        const roots = accounts.filter((a) => !a.parentUuid || !accounts.some((p) => p.uuid === a.parentUuid));
        return new Set(roots.map((a) => a.uuid));
    });

    const accountByCode = React.useMemo(() => {
        const map = {};
        accounts.forEach((a) => {
            map[a.code] = a;
        });
        return map;
    }, [accounts]);

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

    async function suggestCode({ parent, type }) {
        try {
            const params = new URLSearchParams();
            if (parent) params.set('parent', parent);
            if (type) params.set('type', type);
            const response = await fetch(
                `${route('accounting.ledger.coa.next-code')}?${params.toString()}`,
                { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
            );
            if (response.ok) {
                const data = await response.json();
                addForm.setData('code', data.code ?? '');
            }
        } catch {
            // Suggestion failure is non-fatal — the accountant can type a code manually.
        }
    }

    function openAddModal() {
        addForm.clearErrors();
        addForm.setData({
            code: '',
            name: '',
            type: 'expense',
            parent: '',
            description: '',
            is_active: true,
        });
        setParentLocked(false);
        setAddModalOpen(true);
        suggestCode({ type: 'expense' });
    }

    function openAddChildModal(parentAccount) {
        addForm.clearErrors();
        addForm.setData({
            code: '',
            name: '',
            type: parentAccount.type,
            parent: parentAccount.code,
            description: '',
            is_active: true,
        });
        setParentLocked(true);
        setAddModalOpen(true);
        suggestCode({ parent: parentAccount.code });
    }

    function handleAddTypeChange(type) {
        addForm.setData('type', type);
        if (!addForm.data.parent) {
            suggestCode({ type });
        }
    }

    function handleAddParentChange(code) {
        const parentAccount = accountByCode[code];
        addForm.setData('parent', code);
        if (parentAccount) {
            addForm.setData('type', parentAccount.type);
            suggestCode({ parent: code });
        } else {
            suggestCode({ type: addForm.data.type });
        }
    }

    function handleAddSubmit(e) {
        e.preventDefault();
        addForm
            .transform((data) => ({
                ...data,
                parent: data.parent || null,
                description: data.description || null,
            }))
            .post(route('accounting.ledger.coa.store'), {
                preserveScroll: true,
                onSuccess: () => {
                    addForm.reset();
                    setAddModalOpen(false);
                },
            });
    }

    function handleAddModalOpenChange(open) {
        setAddModalOpen(open);
        if (!open) {
            addForm.clearErrors();
        }
    }

    function openEditModal(account) {
        setEditingAccount(account);
        setEditName(account.name);
        setEditDescription(account.description ?? '');
        setEditActive(account.isActive !== false);
        setEditModalOpen(true);
    }

    function handleEditSubmit(e) {
        e.preventDefault();
        if (!editingAccount) return;
        router.put(
            route('accounting.ledger.coa.update', editingAccount.code),
            {
                name: editName,
                description: editDescription || null,
                is_active: editActive,
            },
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

    // Filtered accounts for display
    const filteredAccounts = React.useMemo(() => {
        let list = accounts;
        if (typeFilter !== 'all') {
            list = list.filter((a) => a.type === typeFilter);
        }
        if (search.trim()) {
            const lower = search.toLowerCase();
            list = list.filter(
                (a) => a.code.toLowerCase().includes(lower) || a.name.toLowerCase().includes(lower),
            );
        }
        return list;
    }, [accounts, search, typeFilter]);

    const isFiltering = search.trim() !== '' || typeFilter !== 'all';

    const childrenByParent = React.useMemo(() => {
        const map = {};
        accounts.forEach((a) => {
            if (a.parentUuid) {
                (map[a.parentUuid] ??= []).push(a);
            }
        });
        return map;
    }, [accounts]);

    const accountUuids = React.useMemo(() => new Set(accounts.map((a) => a.uuid)), [accounts]);
    const rootAccounts = accounts.filter((a) => !a.parentUuid || !accountUuids.has(a.parentUuid));

    const childrenCountByUuid = React.useMemo(() => {
        const map = {};
        accounts.forEach((a) => {
            if (a.parentUuid) {
                map[a.parentUuid] = (map[a.parentUuid] ?? 0) + 1;
            }
        });
        return map;
    }, [accounts]);

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.coa')} />
            <AccountingLayout
                title={t('accounting.nav.coa')}
                subtitle="Manage ledger accounts, hierarchy and balances"
                actions={
                    <Button size="sm" onClick={openAddModal}>
                        <Plus className="mr-1 size-3.5" /> Add Account
                    </Button>
                }
            >
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <div className="relative w-full max-w-xs">
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search accounts…"
                            className="pl-8"
                        />
                    </div>
                    <Select value={typeFilter} onValueChange={setTypeFilter}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="Filter by type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {TYPE_ORDER.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {TYPE_LABELS[type]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Account Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">Balance</TableHead>
                                <TableHead className="w-[110px]" />
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
                            ) : isFiltering ? (
                                /* Flat filtered list */
                                filteredAccounts.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No accounts match the current filters.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    filteredAccounts.map((account) => (
                                        <AccountRow
                                            key={account.uuid}
                                            account={account}
                                            hasChildren={(childrenCountByUuid[account.uuid] ?? 0) > 0}
                                            isExpanded={false}
                                            onToggle={() => {}}
                                            onEdit={openEditModal}
                                            onAddChild={openAddChildModal}
                                            onDelete={handleDelete}
                                        />
                                    ))
                                )
                            ) : (
                                /* Tree view */
                                rootAccounts.map((account) => (
                                    <AccountTreeNode
                                        key={account.uuid}
                                        account={account}
                                        childrenByParent={childrenByParent}
                                        expandedNodes={expandedNodes}
                                        toggleExpand={toggleExpand}
                                        onEdit={openEditModal}
                                        onAddChild={openAddChildModal}
                                        onDelete={handleDelete}
                                    />
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </AccountingLayout>

            {/* ───── Add Account Modal ───── */}
            <Dialog open={addModalOpen} onOpenChange={handleAddModalOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Account</DialogTitle>
                        <DialogDescription>
                            {addForm.data.parent
                                ? `Create a child account under ${addForm.data.parent} — the code is auto-suggested.`
                                : 'Create a new account in the chart of accounts.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleAddSubmit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-2">
                                <Label htmlFor="add-code">Code</Label>
                                <Input
                                    id="add-code"
                                    value={addForm.data.code}
                                    onChange={(e) => addForm.setData('code', e.target.value)}
                                    placeholder="Auto-suggested"
                                    required
                                    aria-invalid={Boolean(addForm.errors.code)}
                                />
                                {addForm.errors.code && (
                                    <p className="text-xs text-destructive">{addForm.errors.code}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="add-type">Type</Label>
                                <Select
                                    value={addForm.data.type}
                                    onValueChange={handleAddTypeChange}
                                    disabled={Boolean(addForm.data.parent)}
                                >
                                    <SelectTrigger id="add-type" className="w-full">
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {TYPE_ORDER.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {TYPE_LABELS[type]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {addForm.errors.type && (
                                    <p className="text-xs text-destructive">{addForm.errors.type}</p>
                                )}
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="add-name">Name</Label>
                            <Input
                                id="add-name"
                                value={addForm.data.name}
                                onChange={(e) => addForm.setData('name', e.target.value)}
                                placeholder="e.g. Rent Expense"
                                required
                                aria-invalid={Boolean(addForm.errors.name)}
                            />
                            {addForm.errors.name && (
                                <p className="text-xs text-destructive">{addForm.errors.name}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="add-parent">Parent Account</Label>
                            {parentLocked ? (
                                <Input
                                    id="add-parent"
                                    value={
                                        accountByCode[addForm.data.parent]
                                            ? `${addForm.data.parent} — ${accountByCode[addForm.data.parent].name}`
                                            : addForm.data.parent
                                    }
                                    disabled
                                />
                            ) : (
                                <AccountCombobox
                                    id="add-parent"
                                    value={addForm.data.parent}
                                    onChange={handleAddParentChange}
                                    accounts={accounts}
                                    placeholder="None (top-level)"
                                />
                            )}
                            {addForm.errors.parent && (
                                <p className="text-xs text-destructive">{addForm.errors.parent}</p>
                            )}
                            {addForm.data.parent && !parentLocked && (
                                <button
                                    type="button"
                                    className="text-xs text-muted-foreground underline"
                                    onClick={() => {
                                        addForm.setData('parent', '');
                                        suggestCode({ type: addForm.data.type });
                                    }}
                                >
                                    Clear parent (make top-level)
                                </button>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="add-description">Description</Label>
                            <Input
                                id="add-description"
                                value={addForm.data.description}
                                onChange={(e) => addForm.setData('description', e.target.value)}
                                placeholder="Optional"
                            />
                            {addForm.errors.description && (
                                <p className="text-xs text-destructive">{addForm.errors.description}</p>
                            )}
                        </div>
                        <div className="flex items-center justify-between rounded-md border px-3 py-2">
                            <div>
                                <Label htmlFor="add-active" className="text-sm">Active</Label>
                                <p className="text-xs text-muted-foreground">
                                    Inactive accounts are hidden from journal entry and routing selectors.
                                </p>
                            </div>
                            <Switch
                                id="add-active"
                                checked={addForm.data.is_active}
                                onCheckedChange={(checked) => addForm.setData('is_active', checked)}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={addForm.processing}>
                                Create Account
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ───── Edit Account Modal ───── */}
            <Dialog open={editModalOpen} onOpenChange={setEditModalOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            Edit Account
                            {editingAccount?.isSystem && <Lock className="size-3.5 text-muted-foreground" />}
                        </DialogTitle>
                        <DialogDescription>
                            {editingAccount ? (
                                <>
                                    Account <code className="text-xs">{editingAccount.code}</code>
                                    {editingAccount.isSystem &&
                                        ' — system account: it can be renamed but not deleted.'}
                                </>
                            ) : (
                                'Update the account.'
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
                        <div className="space-y-2">
                            <Label htmlFor="edit-description">Description</Label>
                            <Input
                                id="edit-description"
                                value={editDescription}
                                onChange={(e) => setEditDescription(e.target.value)}
                                placeholder="Optional"
                            />
                        </div>
                        <div className="flex items-center justify-between rounded-md border px-3 py-2">
                            <div>
                                <Label htmlFor="edit-active" className="text-sm">Active</Label>
                                <p className="text-xs text-muted-foreground">
                                    Inactive accounts are hidden from journal entry and routing selectors.
                                </p>
                            </div>
                            <Switch id="edit-active" checked={editActive} onCheckedChange={setEditActive} />
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
