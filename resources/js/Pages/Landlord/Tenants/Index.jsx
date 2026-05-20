import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';
import { Switch } from '@/Components/ui/Switch';
import { ArrowUpCircle, DatabaseZap, Save, Star } from 'lucide-react';

export default function Index({ tenants }) {
    const [isTopUpModalOpen, setIsTopUpModalOpen] = useState(false);
    const [selectedTenant, setSelectedTenant] = useState(null);

    const initialCommissionDrafts = useMemo(() => {
        return tenants.reduce((carry, tenant) => {
            carry[tenant.id] = String(tenant.master_commission_percent ?? 0);

            return carry;
        }, {});
    }, [tenants]);

    const [commissionDrafts, setCommissionDrafts] = useState(initialCommissionDrafts);

    useEffect(() => {
        setCommissionDrafts(initialCommissionDrafts);
    }, [initialCommissionDrafts]);

    const topUpForm = useForm({
        currency: 'LYD',
        amount: '',
        description: '',
    });

    const updateAgencySetting = (tenantId, payload) => {
        router.patch(route('landlord.tenants.agency-settings', tenantId), payload, {
            preserveScroll: true,
        });
    };

    const saveMasterCommission = (tenantId) => {
        const value = Number.parseFloat(commissionDrafts[tenantId] ?? '0');

        updateAgencySetting(tenantId, {
            master_commission_percent: Number.isNaN(value) ? 0 : value,
        });
    };

    const openTopUpModal = (tenant) => {
        setSelectedTenant(tenant);
        topUpForm.reset();
        topUpForm.setData({
            currency: 'LYD',
            amount: '',
            description: '',
        });
        setIsTopUpModalOpen(true);
    };

    const submitTopUp = (event) => {
        event.preventDefault();

        if (!selectedTenant) {
            return;
        }

        topUpForm.post(route('landlord.tenants.wallet.topup', selectedTenant.id), {
            preserveScroll: true,
            onSuccess: () => {
                setIsTopUpModalOpen(false);
                setSelectedTenant(null);
                topUpForm.reset();
            },
        });
    };

    return (
        <LandlordLayout>
            <Head title="Agencies" />

            <div className="mx-auto max-w-7xl p-6 space-y-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Agencies</h1>
                    <p className="text-muted-foreground">Review agency status, tenant health, and provider footprint.</p>
                </div>

                <div className="rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Agency</TableHead>
                                <TableHead>Subdomain</TableHead>
                                <TableHead>Own Credentials</TableHead>
                                <TableHead>Force Default Agency</TableHead>
                                <TableHead>Master Commission %</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tenants.map((tenant) => (
                                <TableRow key={tenant.id}>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            {tenant.is_default_agency && (
                                                <Star className="h-4 w-4 fill-amber-400 text-amber-400" />
                                            )}
                                            <div>
                                                <p className="font-semibold">{tenant.company_name || tenant.id}</p>
                                                <p className="text-xs text-muted-foreground">{tenant.owner_name || tenant.owner_email || 'Unassigned'}</p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <p className="text-xs text-muted-foreground">{tenant.subdomain || tenant.domains?.[0] || '-'}</p>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <Switch
                                                checked={Boolean(tenant.can_use_own_airline_credentials)}
                                                onCheckedChange={(checked) => {
                                                    updateAgencySetting(tenant.id, {
                                                        can_use_own_airline_credentials: checked,
                                                    });
                                                }}
                                            />
                                            <span className="text-xs text-muted-foreground">
                                                {tenant.can_use_own_airline_credentials ? 'Enabled' : 'Disabled'}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <Switch
                                                checked={Boolean(tenant.force_use_default_agency)}
                                                onCheckedChange={(checked) => {
                                                    updateAgencySetting(tenant.id, {
                                                        force_use_default_agency: checked,
                                                    });
                                                }}
                                            />
                                            <span className="text-xs text-muted-foreground">
                                                {tenant.force_use_default_agency ? 'Forced' : 'Not Forced'}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                className="h-8 w-24"
                                                value={commissionDrafts[tenant.id] ?? '0'}
                                                onChange={(event) => {
                                                    setCommissionDrafts((current) => ({
                                                        ...current,
                                                        [tenant.id]: event.target.value,
                                                    }));
                                                }}
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => saveMasterCommission(tenant.id)}
                                            >
                                                <Save className="mr-1 h-3.5 w-3.5" /> Save
                                            </Button>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-1.5">
                                            <Badge variant={tenant.status === 'active' ? 'success' : tenant.status === 'frozen' ? 'secondary' : 'destructive'}>
                                                {tenant.status}
                                            </Badge>
                                            {tenant.is_default_agency && (
                                                <Badge variant="outline" className="border-amber-400/50 bg-amber-50 text-amber-700 text-[10px]">
                                                    Master
                                                </Badge>
                                            )}
                                            {tenant.database_missing && (
                                                <Badge variant="outline" className="border-red-400/50 bg-red-50 text-red-700 text-[10px] gap-1">
                                                    <DatabaseZap className="h-3 w-3" /> No DB
                                                </Badge>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <div className="flex justify-end gap-2">
                                            <Button size="sm" variant="outline" onClick={() => openTopUpModal(tenant)}>
                                                <ArrowUpCircle className="mr-1.5 h-4 w-4" /> Top Up
                                            </Button>
                                            <Button asChild size="sm" variant="ghost">
                                                <Link href={route('landlord.tenants.show', tenant.id)}>Open</Link>
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Dialog open={isTopUpModalOpen} onOpenChange={setIsTopUpModalOpen}>
                    <DialogContent className="sm:max-w-[425px]">
                        <form onSubmit={submitTopUp}>
                            <DialogHeader>
                                <DialogTitle>Wallet Top-Up</DialogTitle>
                                <DialogDescription>
                                    Top up {selectedTenant?.company_name || selectedTenant?.id || 'agency'} wallet and record transaction.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4 py-4">
                                <div className="space-y-2">
                                    <Label htmlFor="topup-currency">Currency</Label>
                                    <Select
                                        id="topup-currency"
                                        value={topUpForm.data.currency}
                                        onChange={(event) => topUpForm.setData('currency', event.target.value)}
                                    >
                                        <option value="LYD">LYD - Libyan Dinar</option>
                                        <option value="USD">USD - US Dollar</option>
                                        <option value="EUR">EUR - Euro</option>
                                    </Select>
                                    {topUpForm.errors.currency && <p className="text-xs text-destructive">{topUpForm.errors.currency}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="topup-amount">Amount</Label>
                                    <Input
                                        id="topup-amount"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={topUpForm.data.amount}
                                        onChange={(event) => topUpForm.setData('amount', event.target.value)}
                                        required
                                    />
                                    {topUpForm.errors.amount && <p className="text-xs text-destructive">{topUpForm.errors.amount}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="topup-description">Description</Label>
                                    <Input
                                        id="topup-description"
                                        value={topUpForm.data.description}
                                        onChange={(event) => topUpForm.setData('description', event.target.value)}
                                        placeholder="Reason for top-up"
                                    />
                                    {topUpForm.errors.description && <p className="text-xs text-destructive">{topUpForm.errors.description}</p>}
                                </div>
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setIsTopUpModalOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={topUpForm.processing}>
                                    {topUpForm.processing ? 'Processing...' : 'Top Up'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </LandlordLayout>
    );
}
