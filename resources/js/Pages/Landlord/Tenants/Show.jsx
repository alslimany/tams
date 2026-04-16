import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';
import { Switch } from '@/Components/ui/Switch';
import { Plus, Pencil, Trash2, ShieldCheck, User as UserIcon, Mail, Key } from 'lucide-react';

export default function Show({ tenantRecord }) {
    const [isUserModalOpen, setIsUserModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState(null);

    const { data, setData, post, put, delete: destroy, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        role: 'agent',
        is_active: true,
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

    return (
        <LandlordLayout>
            <Head title={tenantRecord.company_name || tenantRecord.id} />

            <div className="mx-auto max-w-6xl p-6 space-y-8">
                <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-bold tracking-tight">{tenantRecord.company_name || tenantRecord.id}</h1>
                            <Badge variant={tenantRecord.status === 'active' ? 'success' : tenantRecord.status === 'frozen' ? 'secondary' : 'destructive'}>
                                {tenantRecord.status}
                            </Badge>
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
                            <p><span className="font-semibold">Users:</span> {tenantRecord.snapshot.stats.users}</p>
                            <p><span className="font-semibold">Active Users:</span> {tenantRecord.snapshot.stats.active_users}</p>
                            <p><span className="font-semibold">Providers:</span> {tenantRecord.snapshot.stats.active_providers}/{tenantRecord.snapshot.stats.providers}</p>
                            <p><span className="font-semibold">Bookings:</span> {tenantRecord.snapshot.stats.bookings}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm font-black uppercase tracking-widest text-muted-foreground">Tenant Admin</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {tenantRecord.snapshot.admin_user ? (
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

                <div className="grid gap-8">
                    <Card className="border-2 shadow-sm">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b bg-muted/10 pb-4">
                            <div>
                                <CardTitle className="text-lg font-bold">Tenant Users</CardTitle>
                                <DialogDescription>Manage the accounts that have access to this tenant.</DialogDescription>
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
                                    {tenantRecord.snapshot.users.map((user) => (
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
                                    {tenantRecord.snapshot.users.length === 0 && (
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
                                    {tenantRecord.snapshot.providers.map((provider) => (
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
                                    {tenantRecord.snapshot.recent_bookings.map((booking) => (
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
                                        id="role" 
                                        value={data.role} 
                                        onChange={e => setData('role', e.target.value)}
                                    >
                                        <option value="admin">Admin</option>
                                        <option value="manager">Manager</option>
                                        <option value="agent">Agent</option>
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
        </LandlordLayout>
    );
}
