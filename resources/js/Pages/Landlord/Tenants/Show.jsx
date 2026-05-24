import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/Select';
import { Switch } from '@/Components/ui/Switch';
import { Plus, Pencil, Trash2, ShieldCheck, User as UserIcon, Mail, Key, Wallet, Star, ArrowUpCircle, DatabaseZap } from 'lucide-react';

export default function Show({ tenantRecord }) {
    const [isUserModalOpen, setIsUserModalOpen] = useState(false);
    const [isTopUpModalOpen, setIsTopUpModalOpen] = useState(false);
    const [isDefaultAgencyModalOpen, setIsDefaultAgencyModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState(null);

    const { data, setData, post, put, delete: destroy, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        role: 'agent',
        is_active: true,
    });

    const topUpForm = useForm({
        currency: 'LYD',
        amount: '',
        description: '',
    });

    const defaultAgencyForm = useForm({
        is_default_agency: tenantRecord.is_default_agency || false,
        master_commission_rate: tenantRecord.master_commission_rate || 0,
    });

    const updateStatus = (status) => {
        router.patch(route('landlord.tenants.status', tenantRecord.id), { status });
    };

    const openCreateModal = () => {
        setEditingUser(null);
        reset();
        setIsUserModalOpen(true);
    };

    const openEditModal = (user) => {
        setEditingUser(user);
        setData({
            name: user.name,
            email: user.email,
            password: '',
            role: user.role,
            is_active: user.is_active,
        });
        setIsUserModalOpen(true);
    };

    const submitUserForm = (e) => {
        e.preventDefault();
        if (editingUser) {
            put(route('landlord.tenants.users.update', [tenantRecord.id, editingUser.id]), {
                onSuccess: () => {
                    setIsUserModalOpen(false);
                    reset();
                },
            });
        } else {
            post(route('landlord.tenants.users.store', tenantRecord.id), {
                onSuccess: () => {
                    setIsUserModalOpen(false);
                    reset();
                },
            });
        }
    };

    const deleteUser = (userId) => {
        if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
            destroy(route('landlord.tenants.users.destroy', [tenantRecord.id, userId]));
        }
    };

    const submitTopUp = (e) => {
        e.preventDefault();
        topUpForm.post(route('landlord.tenants.wallet.topup', tenantRecord.id), {
            onSuccess: () => {
                setIsTopUpModalOpen(false);
                topUpForm.reset();
            },
        });
    };

    const submitDefaultAgency = (e) => {
        e.preventDefault();
        defaultAgencyForm.patch(route('landlord.tenants.default-agency', tenantRecord.id), {
            onSuccess: () => {
                setIsDefaultAgencyModalOpen(false);
            },
        });
    };

    const toggleCredentialsPermission = (useOwn) => {
        router.patch(route('landlord.tenants.credentials-permission', tenantRecord.id), {
            use_own_airline_credentials: useOwn,
        });
    };

    const walletBalances = tenantRecord.wallet_balances || {};
    const recentWalletTransactions = tenantRecord.recent_wallet_transactions || [];
    const databaseMissing = tenantRecord.snapshot?.database_missing === true;

    const formatAmount = (amount, currency) => {
        return `${Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
    };

    const transactionTypeLabel = (type) => {
        const labels = {
            topup_from_admin: 'Top-Up',
            ticket_cost_deduction: 'Ticket Deduction',
            commission_payment: 'Commission',
            settlement: 'Settlement',
        };
        return labels[type] || type;
    };

    const transactionTypeVariant = (type) => {
        const variants = {
            topup_from_admin: 'success',
            ticket_cost_deduction: 'destructive',
            commission_payment: 'outline',
            settlement: 'secondary',
        };
        return variants[type] || 'outline';
    };

    return (
        <LandlordLayout>
            <Head title={tenantRecord.company_name || tenantRecord.id} />

            <div className="mx-auto max-w-7xl p-6 space-y-8">
                {databaseMissing && (
                    <div className="flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">
                        <DatabaseZap className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                        <div>
                            <p className="font-semibold">Tenant database is missing</p>
                            <p className="text-sm text-red-700">The SQLite database file for this tenant does not exist. Stats, users, providers, and bookings cannot be loaded. Re-run tenant migrations or restore the database to resolve this.</p>
                        </div>
                    </div>
                )}

                <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-bold tracking-tight">{tenantRecord.company_name || tenantRecord.id}</h1>
                            <Badge variant={tenantRecord.status === 'active' ? 'success' : tenantRecord.status === 'frozen' ? 'secondary' : 'destructive'}>
                                {tenantRecord.status}
                            </Badge>
                            {tenantRecord.is_default_agency && (
                                                <Badge variant="outline" className="border-amber-400/50 bg-amber-50 text-amber-700 font-bold">
                                                    <Star className="mr-1 h-3 w-3 fill-amber-400 text-amber-400" /> Master Agency
                                                </Badge>
                                            )}
                                            {databaseMissing && (
                                                <Badge variant="outline" className="border-red-400/50 bg-red-50 text-red-700 font-bold gap-1">
                                                    <DatabaseZap className="h-3.5 w-3.5" /> No Database
                                                </Badge>
                                            )}
                        </div>
                        <p className="text-muted-foreground">{tenantRecord.domains.join(', ')}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => updateStatus('active')}>Activate</Button>
                        <Button variant="secondary" onClick={() => updateStatus('frozen')}>Freeze</Button>
                        <Button variant="destructive" onClick={() => updateStatus('suspended')}>Suspend</Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-black uppercase tracking-widest text-muted-foreground">Agency Profile</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p><span className="font-semibold">Owner:</span> {tenantRecord.owner_name || 'Unassigned'}</p>
                            <p><span className="font-semibold">Email:</span> {tenantRecord.owner_email}</p>
                            <p><span className="font-semibold">Phone:</span> {tenantRecord.owner_phone || 'N/A'}</p>
                            <p><span className="font-semibold">Plan:</span> {tenantRecord.subscription_plan || 'Not assigned'}</p>
                            <p><span className="font-semibold">Subscription:</span> {tenantRecord.subscription_status}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm font-black uppercase tracking-widest text-muted-foreground">Tenant Health</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {databaseMissing ? (
                                <p className="text-muted-foreground italic">Unavailable — database missing.</p>
                            ) : (
                                <>
                                    <p><span className="font-semibold">Users:</span> {tenantRecord.snapshot.stats.users}</p>
                                    <p><span className="font-semibold">Active Users:</span> {tenantRecord.snapshot.stats.active_users}</p>
                                    <p><span className="font-semibold">Providers:</span> {tenantRecord.snapshot.stats.active_providers}/{tenantRecord.snapshot.stats.providers}</p>
                                    <p><span className="font-semibold">Bookings:</span> {tenantRecord.snapshot.stats.bookings}</p>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm font-black uppercase tracking-widest text-muted-foreground">Tenant Admin</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {databaseMissing ? (
                                <p className="text-muted-foreground italic">Unavailable — database missing.</p>
                            ) : tenantRecord.snapshot.admin_user ? (
                                <>
                                    <p><span className="font-semibold">Name:</span> {tenantRecord.snapshot.admin_user.name}</p>
                                    <p><span className="font-semibold">Email:</span> {tenantRecord.snapshot.admin_user.email}</p>
                                    <p><span className="font-semibold">Last Login:</span> {tenantRecord.snapshot.admin_user.last_login_at || 'Never'}</p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">No admin user found in this tenant.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Wallet & Master Agency Controls */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="border-2 shadow-sm">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b bg-muted/10 pb-4">
                            <div>
                                <CardTitle className="flex items-center gap-2 text-lg font-bold">
                                    <Wallet className="h-5 w-5" /> Agency Wallet
                                </CardTitle>
                                <CardDescription>Wallet balances and top-up management.</CardDescription>
                            </div>
                            <Button onClick={() => setIsTopUpModalOpen(true)} size="sm" className="rounded-full shadow-md">
                                <ArrowUpCircle className="mr-2 h-4 w-4" /> Top Up
                            </Button>
                        </CardHeader>
                        <CardContent className="pt-4">
                            <div className="grid grid-cols-3 gap-4 mb-4">
                                {Object.entries(walletBalances).map(([currency, balance]) => (
                                    <div key={currency} className="rounded-lg border bg-card p-3 text-center">
                                        <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider">{currency}</p>
                                        <p className="text-xl font-bold">{Number(balance).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                                    </div>
                                ))}
                            </div>

                            {recentWalletTransactions.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Recent Transactions</p>
                                    <div className="space-y-1">
                                        {recentWalletTransactions.slice(0, 5).map((tx) => (
                                            <div key={tx.id} className="flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-muted/30">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={transactionTypeVariant(tx.type)} className="text-[10px] px-1.5">
                                                        {transactionTypeLabel(tx.type)}
                                                    </Badge>
                                                    <span className="text-muted-foreground text-xs">{tx.description}</span>
                                                </div>
                                                <div className="text-right">
                                                    <span className={`font-bold ${tx.type === 'topup_from_admin' ? 'text-green-600' : 'text-red-600'}`}>
                                                        {tx.type === 'topup_from_admin' ? '+' : '-'}{formatAmount(tx.amount, tx.currency)}
                                                    </span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {recentWalletTransactions.length === 0 && (
                                <p className="text-sm text-muted-foreground text-center py-4">No wallet transactions yet.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="border-2 shadow-sm">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b bg-muted/10 pb-4">
                            <div>
                                <CardTitle className="flex items-center gap-2 text-lg font-bold">
                                    <Star className="h-5 w-5" /> Master Agency & Credentials
                                </CardTitle>
                                <CardDescription>Control default agency designation and credential permissions.</CardDescription>
                            </div>
                            <Button onClick={() => {
                                defaultAgencyForm.setData({
                                    is_default_agency: tenantRecord.is_default_agency || false,
                                    master_commission_rate: tenantRecord.master_commission_rate || 0,
                                });
                                setIsDefaultAgencyModalOpen(true);
                            }} size="sm" variant="outline" className="rounded-full">
                                <Star className="mr-2 h-4 w-4" /> Configure
                            </Button>
                        </CardHeader>
                        <CardContent className="pt-4 space-y-4">
                            <div className="flex items-center justify-between rounded-lg border p-3">
                                <div>
                                    <p className="font-semibold text-sm">Default Agency</p>
                                    <p className="text-xs text-muted-foreground">
                                        {tenantRecord.is_default_agency
                                            ? 'This agency supplies airline credentials to other agencies.'
                                            : 'Not the default agency. Other agencies use their own or the default agency\'s credentials.'}
                                    </p>
                                </div>
                                <Badge variant={tenantRecord.is_default_agency ? 'success' : 'secondary'}>
                                    {tenantRecord.is_default_agency ? 'Active' : 'Inactive'}
                                </Badge>
                            </div>

                            {tenantRecord.is_default_agency && (
                                <div className="flex items-center justify-between rounded-lg border p-3">
                                    <div>
                                        <p className="font-semibold text-sm">Master Commission Rate</p>
                                        <p className="text-xs text-muted-foreground">Commission earned by the default agency on each ticket.</p>
                                    </div>
                                    <span className="text-lg font-bold">{tenantRecord.master_commission_rate}%</span>
                                </div>
                            )}

                            <div className="flex items-center justify-between rounded-lg border p-3">
                                <div>
                                    <p className="font-semibold text-sm">Airline Credentials</p>
                                    <p className="text-xs text-muted-foreground">
                                        {tenantRecord.uses_own_airline_credentials
                                            ? 'This agency uses its own airline credentials for bookings.'
                                            : 'This agency uses the default agency\'s airline credentials (master supply).'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Switch
                                        checked={tenantRecord.uses_own_airline_credentials}
                                        onCheckedChange={(checked) => toggleCredentialsPermission(checked)}
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {tenantRecord.uses_own_airline_credentials ? 'Own' : 'Master'}
                                    </span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-8">
                    <Card className="border-2 shadow-sm">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b bg-muted/10 pb-4">
                            <div>
                                <CardTitle className="text-lg font-bold">Tenant Users</CardTitle>
                                <CardDescription>Manage the accounts that have access to this tenant.</CardDescription>
                            </div>
                            <Button onClick={openCreateModal} size="sm" className="rounded-full shadow-md">
                                <Plus className="mr-2 h-4 w-4" /> Add User
                            </Button>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="font-bold">User</TableHead>
                                        <TableHead className="font-bold">Role</TableHead>
                                        <TableHead className="font-bold">Status</TableHead>
                                        <TableHead className="font-bold">Last Login</TableHead>
                                        <TableHead className="text-right font-bold">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {databaseMissing ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="py-10 text-center text-muted-foreground italic">
                                                Unavailable — tenant database is missing.
                                            </TableCell>
                                        </TableRow>
                                    ) : tenantRecord.snapshot.users.map((user) => (
                                        <TableRow key={user.id} className="hover:bg-muted/30">
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span className="font-bold">{user.name}</span>
                                                    <span className="text-xs text-muted-foreground">{user.email}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className="capitalize font-bold border-primary/20 bg-primary/5 text-primary">
                                                    {user.role}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={user.is_active ? 'success' : 'destructive'} className="font-bold">
                                                    {user.is_active ? 'active' : 'inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="ghost" size="icon" onClick={() => openEditModal(user)} className="h-8 w-8 text-muted-foreground hover:text-primary">
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="icon" onClick={() => deleteUser(user.id)} className="h-8 w-8 text-muted-foreground hover:text-destructive">
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {!databaseMissing && tenantRecord.snapshot.users.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                                                No users found for this tenant.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="border-b bg-muted/10 pb-4"><CardTitle className="text-lg font-bold">Configured Providers</CardTitle></CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="font-bold">Airline</TableHead>
                                        <TableHead className="font-bold">Account</TableHead>
                                        <TableHead className="font-bold">Status</TableHead>
                                        <TableHead className="font-bold">Last Test</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {databaseMissing ? (
                                        <TableRow>
                                            <TableCell colSpan={4} className="py-6 text-center text-muted-foreground italic">
                                                Unavailable — tenant database is missing.
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                     {!databaseMissing && tenantRecord.snapshot.providers.map((provider) => (
                                        <TableRow key={provider.id} className="hover:bg-muted/30">
                                            <TableCell className="font-bold">{provider.airline_name}</TableCell>
                                            <TableCell>{provider.account_name}</TableCell>
                                            <TableCell>
                                                <Badge variant={provider.is_active ? 'success' : 'outline'} className="font-bold">
                                                    {provider.is_active ? 'active' : 'inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">{provider.last_test_status || 'untested'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="border-b bg-muted/10 pb-4"><CardTitle className="text-lg font-bold">Recent Bookings</CardTitle></CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="font-bold">PNR</TableHead>
                                        <TableHead className="font-bold">Status</TableHead>
                                        <TableHead className="font-bold">Provider</TableHead>
                                        <TableHead className="font-bold">Total</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {databaseMissing ? (
                                        <TableRow>
                                            <TableCell colSpan={4} className="py-6 text-center text-muted-foreground italic">
                                                Unavailable — tenant database is missing.
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                    {!databaseMissing && tenantRecord.snapshot.recent_bookings.map((booking) => (
                                        <TableRow key={booking.id} className="hover:bg-muted/30">
                                            <TableCell className="font-mono font-bold">{booking.pnr}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className="capitalize font-bold border-muted-foreground/20">
                                                    {booking.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{booking.provider?.airline_name}</TableCell>
                                            <TableCell className="font-bold text-primary">{booking.total_price} {booking.currency}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* User Modal */}
            <Dialog open={isUserModalOpen} onOpenChange={setIsUserModalOpen}>
                <DialogContent className="sm:max-w-[425px]">
                    <form onSubmit={submitUserForm}>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                {editingUser ? <Pencil className="h-5 w-5" /> : <Plus className="h-5 w-5" />}
                                {editingUser ? 'Edit Tenant User' : 'Create New Tenant User'}
                            </DialogTitle>
                            <DialogDescription>
                                {editingUser ? "Update the user's account information below." : "Enter the details for the new tenant user account."}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="name" className="flex items-center gap-2">
                                    <UserIcon className="h-4 w-4 text-muted-foreground" /> Full Name
                                </Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={e => setData('name', e.target.value)}
                                    placeholder="e.g. Abdullah Ishtiwy"
                                    required
                                />
                                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="email" className="flex items-center gap-2">
                                    <Mail className="h-4 w-4 text-muted-foreground" /> Email Address
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    placeholder="user@example.com"
                                    required
                                />
                                {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="password" className="flex items-center gap-2">
                                    <Key className="h-4 w-4 text-muted-foreground" /> {editingUser ? 'New Password (optional)' : 'Password'}
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={e => setData('password', e.target.value)}
                                    placeholder={editingUser ? "Leave blank to keep current" : "Minimum 8 characters"}
                                    required={!editingUser}
                                />
                                {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="role" className="flex items-center gap-2">
                                        <ShieldCheck className="h-4 w-4 text-muted-foreground" /> Role
                                    </Label>
                                    <Select
                                        value={data.role}
                                        onValueChange={value => setData('role', value)}
                                    >
                                        <SelectTrigger id="role" className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="admin">Admin</SelectItem>
                                            <SelectItem value="manager">Manager</SelectItem>
                                            <SelectItem value="agent">Agent</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.role && <p className="text-xs text-destructive">{errors.role}</p>}
                                </div>

                                <div className="flex flex-col justify-end space-y-2">
                                    <div className="flex items-center space-x-2 pb-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={val => setData('is_active', val)}
                                        />
                                        <Label htmlFor="is_active">Active Account</Label>
                                    </div>
                                    {errors.is_active && <p className="text-xs text-destructive">{errors.is_active}</p>}
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsUserModalOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing} className="font-bold">
                                {processing ? 'Saving...' : (editingUser ? 'Update User' : 'Create User')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Wallet Top-Up Modal */}
            <Dialog open={isTopUpModalOpen} onOpenChange={setIsTopUpModalOpen}>
                <DialogContent className="sm:max-w-[425px]">
                    <form onSubmit={submitTopUp}>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <ArrowUpCircle className="h-5 w-5" /> Wallet Top-Up
                            </DialogTitle>
                            <DialogDescription>
                                Add funds to this agency's wallet. The balance will be updated immediately.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="topup-currency">Currency</Label>
                                <Select
                                    value={topUpForm.data.currency}
                                    onValueChange={value => topUpForm.setData('currency', value)}
                                >
                                    <SelectTrigger id="topup-currency" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="LYD">LYD - Libyan Dinar</SelectItem>
                                        <SelectItem value="USD">USD - US Dollar</SelectItem>
                                        <SelectItem value="EUR">EUR - Euro</SelectItem>
                                    </SelectContent>
                                </Select>
                                {topUpForm.errors.currency && <p className="text-xs text-destructive">{topUpForm.errors.currency}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="topup-amount">Amount</Label>
                                <Input
                                    id="topup-amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    value={topUpForm.data.amount}
                                    onChange={e => topUpForm.setData('amount', e.target.value)}
                                    placeholder="Enter amount"
                                    required
                                />
                                {topUpForm.errors.amount && <p className="text-xs text-destructive">{topUpForm.errors.amount}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="topup-description">Description (optional)</Label>
                                <Input
                                    id="topup-description"
                                    value={topUpForm.data.description}
                                    onChange={e => topUpForm.setData('description', e.target.value)}
                                    placeholder="Reason for top-up"
                                />
                                {topUpForm.errors.description && <p className="text-xs text-destructive">{topUpForm.errors.description}</p>}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsTopUpModalOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={topUpForm.processing} className="font-bold">
                                {topUpForm.processing ? 'Processing...' : 'Top Up Wallet'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Default Agency Configuration Modal */}
            <Dialog open={isDefaultAgencyModalOpen} onOpenChange={setIsDefaultAgencyModalOpen}>
                <DialogContent className="sm:max-w-[425px]">
                    <form onSubmit={submitDefaultAgency}>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <Star className="h-5 w-5" /> Default Agency Configuration
                            </DialogTitle>
                            <DialogDescription>
                                Set this tenant as the default agency. Only one agency can be the default at a time.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="flex items-center justify-between rounded-lg border p-3">
                                <div>
                                    <Label htmlFor="is_default_agency" className="font-semibold">Default Agency</Label>
                                    <p className="text-xs text-muted-foreground">Designate this agency as the default supplier.</p>
                                </div>
                                <Switch
                                    id="is_default_agency"
                                    checked={defaultAgencyForm.data.is_default_agency}
                                    onCheckedChange={val => defaultAgencyForm.setData('is_default_agency', val)}
                                />
                            </div>

                            {defaultAgencyForm.data.is_default_agency && (
                                <div className="space-y-2">
                                    <Label htmlFor="master_commission_rate">Master Commission Rate (%)</Label>
                                    <Input
                                        id="master_commission_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        value={defaultAgencyForm.data.master_commission_rate}
                                        onChange={e => defaultAgencyForm.setData('master_commission_rate', e.target.value)}
                                        placeholder="e.g. 5.00"
                                    />
                                    <p className="text-xs text-muted-foreground">Commission percentage earned by the default agency on each ticket sold through master supply.</p>
                                    {defaultAgencyForm.errors.master_commission_rate && <p className="text-xs text-destructive">{defaultAgencyForm.errors.master_commission_rate}</p>}
                                </div>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsDefaultAgencyModalOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={defaultAgencyForm.processing} className="font-bold">
                                {defaultAgencyForm.processing ? 'Saving...' : 'Save Configuration'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </LandlordLayout>
    );
}
