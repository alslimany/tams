import React from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';

const statusVariant = (status) => {
    if (status === 'active') {
        return 'success';
    }

    if (status === 'revoked' || status === 'suspended') {
        return 'destructive';
    }

    return 'outline';
};

const rateLabels = {
    domestic_discount_rate: 'Domestic discount %',
    international_discount_rate: 'International discount %',
    compulsory_discount_rate: 'Compulsory discount %',
    travel_discount_rate: 'Travel discount %',
    orange_discount_rate: 'Orange discount %',
    hotel_markup_rate: 'Hotel markup profit %',
};

const rateSummary = (terms) => {
    const merchantRates = terms?.merchant_rates ?? {};

    return Object.entries(merchantRates)
        .map(([key, value]) => `${rateLabels[key] ?? key}: ${Number(value).toFixed(2)}%`)
        .join(' · ');
};

export default function NetworkIndex({ agencyNumber, availableProviders = [], agencyMemberships = [], merchantMemberships = [] }) {
    const inviteForm = useForm({
        merchant_email: '',
        merchant_contact_name: '',
        provider_keys: [],
        provider_terms: {},
    });

    const joinForm = useForm({
        invitation_code: '',
    });

    const [selectedAllocations, setSelectedAllocations] = React.useState({});

    const toggleProvider = (key) => {
        const nextKeys = inviteForm.data.provider_keys.includes(key)
            ? inviteForm.data.provider_keys.filter((providerKey) => providerKey !== key)
            : [...inviteForm.data.provider_keys, key];

        inviteForm.setData('provider_keys', nextKeys);
    };

    const setProviderRate = (providerKey, rateKey, value) => {
        inviteForm.setData('provider_terms', {
            ...inviteForm.data.provider_terms,
            [providerKey]: {
                ...(inviteForm.data.provider_terms[providerKey] ?? {}),
                [rateKey]: value,
            },
        });
    };

    const toggleAllocation = (membershipId, allocationId) => {
        setSelectedAllocations((current) => {
            const selected = current[membershipId] ?? [];
            const next = selected.includes(allocationId)
                ? selected.filter((id) => id !== allocationId)
                : [...selected, allocationId];

            return { ...current, [membershipId]: next };
        });
    };

    const submitInvite = (event) => {
        event.preventDefault();

        inviteForm.post(route('network.invite'), {
            preserveScroll: true,
            onSuccess: () => inviteForm.reset(),
        });
    };

    const submitJoin = (event) => {
        event.preventDefault();

        joinForm.post(route('network.join'), {
            preserveScroll: true,
            onSuccess: () => joinForm.reset(),
        });
    };

    const acceptMembership = (membership) => {
        router.post(route('network.accept', membership.id), {
            allocation_ids: selectedAllocations[membership.id] ?? membership.allocations
                .filter((allocation) => allocation.is_enabled_by_merchant)
                .map((allocation) => allocation.id),
        }, {
            preserveScroll: true,
        });
    };

    React.useEffect(() => {
        const defaults = {};

        merchantMemberships.forEach((membership) => {
            defaults[membership.id] = membership.allocations
                .filter((allocation) => allocation.is_enabled_by_merchant)
                .map((allocation) => allocation.id);
        });

        setSelectedAllocations(defaults);
    }, [merchantMemberships]);

    return (
        <TenantSidebarLayout>
            <Head title="Agency Network" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Agency Network</h1>
                        <p className="text-sm text-muted-foreground">Invite merchants, share selected provider APIs, and join agency networks.</p>
                    </div>
                    <Badge variant="secondary" className="w-fit">Agency No. {agencyNumber ?? 'Pending'}</Badge>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Invite Merchant</CardTitle>
                            <CardDescription>Select configured provider APIs to offer. Credentials stay inside this agency tenant.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-5" onSubmit={submitInvite}>
                                <div className="grid gap-2">
                                    <Label htmlFor="merchant_email">Merchant Email</Label>
                                    <Input id="merchant_email" type="email" value={inviteForm.data.merchant_email} onChange={(event) => inviteForm.setData('merchant_email', event.target.value)} />
                                    {inviteForm.errors.merchant_email && <p className="text-xs text-destructive">{inviteForm.errors.merchant_email}</p>}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="merchant_contact_name">Contact Name</Label>
                                    <Input id="merchant_contact_name" value={inviteForm.data.merchant_contact_name} onChange={(event) => inviteForm.setData('merchant_contact_name', event.target.value)} />
                                </div>

                                <div className="space-y-3">
                                    <Label>Provider APIs Offered</Label>
                                    {availableProviders.length === 0 && (
                                        <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">No active provider APIs are configured yet.</div>
                                    )}
                                    <div className="grid gap-2">
                                        {availableProviders.map((provider) => (
                                            <div key={provider.key} className="rounded-md border p-3">
                                                <label className="flex cursor-pointer items-start gap-3">
                                                    <Checkbox checked={inviteForm.data.provider_keys.includes(provider.key)} onCheckedChange={() => toggleProvider(provider.key)} />
                                                    <span className="grid gap-1">
                                                        <span className="text-sm font-medium">{provider.display_name}</span>
                                                        <span className="text-xs text-muted-foreground capitalize">{provider.provider_type} · {provider.provider_driver} · {provider.description}</span>
                                                    </span>
                                                </label>
                                                {inviteForm.data.provider_keys.includes(provider.key) && (
                                                    <div className="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-2">
                                                        {Object.entries(provider.agency_rates ?? {}).map(([rateKey, agencyRate]) => (
                                                            <div key={rateKey} className="grid gap-1">
                                                                <Label htmlFor={`${provider.key}-${rateKey}`} className="text-xs">{rateLabels[rateKey] ?? rateKey}</Label>
                                                                <Input
                                                                    id={`${provider.key}-${rateKey}`}
                                                                    type="number"
                                                                    min="0"
                                                                    max={Number(agencyRate)}
                                                                    step="0.01"
                                                                    placeholder={`Max ${Number(agencyRate).toFixed(2)}%`}
                                                                    value={inviteForm.data.provider_terms[provider.key]?.[rateKey] ?? ''}
                                                                    onChange={(event) => setProviderRate(provider.key, rateKey, event.target.value)}
                                                                />
                                                                <p className="text-[11px] text-muted-foreground">Agency rate: {Number(agencyRate).toFixed(2)}%</p>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                    {inviteForm.errors.provider_keys && <p className="text-xs text-destructive">{inviteForm.errors.provider_keys}</p>}
                                </div>

                                <Button type="submit" disabled={inviteForm.processing || availableProviders.length === 0}>Create Invitation</Button>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Join Agency Network</CardTitle>
                            <CardDescription>Enter an invitation code, then enable only the provider APIs you want.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="flex gap-2" onSubmit={submitJoin}>
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="invitation_code">Invitation Code</Label>
                                    <Input id="invitation_code" value={joinForm.data.invitation_code} onChange={(event) => joinForm.setData('invitation_code', event.target.value.toUpperCase())} />
                                    {joinForm.errors.invitation_code && <p className="text-xs text-destructive">{joinForm.errors.invitation_code}</p>}
                                </div>
                                <div className="flex items-end">
                                    <Button type="submit" disabled={joinForm.processing}>Load Invite</Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Invitations Sent</CardTitle>
                        <CardDescription>Pending and active merchants invited by this agency.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Merchant</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Providers</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {agencyMemberships.map((membership) => (
                                    <TableRow key={membership.id}>
                                        <TableCell>
                                            <div className="font-medium">{membership.merchant_contact_name || membership.merchant_email}</div>
                                            <div className="text-xs text-muted-foreground">{membership.merchant_email}</div>
                                        </TableCell>
                                        <TableCell>{membership.invitation_code}</TableCell>
                                        <TableCell>{membership.allocations.length}</TableCell>
                                        <TableCell><Badge variant={statusVariant(membership.status)}>{membership.status}</Badge></TableCell>
                                        <TableCell className="space-x-2 text-right">
                                            <Button type="button" size="sm" variant="outline" onClick={() => router.patch(route('network.suspend', membership.id), {}, { preserveScroll: true })}>Suspend</Button>
                                            <Button type="button" size="sm" variant="destructive" onClick={() => router.patch(route('network.revoke', membership.id), {}, { preserveScroll: true })}>Revoke</Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Joined Networks</CardTitle>
                        <CardDescription>Provider APIs offered to this tenant by other agencies.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {merchantMemberships.length === 0 && <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">No joined networks yet.</div>}
                        {merchantMemberships.map((membership) => (
                            <div key={membership.id} className="rounded-md border p-4">
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <div>
                                        <div className="font-medium">{membership.agency_name || membership.agency_tenant_id}</div>
                                        <div className="text-xs text-muted-foreground">{membership.agency_number}</div>
                                    </div>
                                    <Badge variant={statusVariant(membership.status)}>{membership.status}</Badge>
                                </div>
                                <div className="grid gap-2">
                                    {membership.allocations.map((allocation) => (
                                        <label key={allocation.id} className="flex cursor-pointer items-start gap-3 rounded-md border p-3">
                                            <Checkbox checked={(selectedAllocations[membership.id] ?? []).includes(allocation.id)} onCheckedChange={() => toggleAllocation(membership.id, allocation.id)} disabled={membership.status !== 'pending'} />
                                                <span className="grid gap-1">
                                                    <span className="text-sm font-medium">{allocation.display_name}</span>
                                                    <span className="text-xs text-muted-foreground capitalize">{allocation.provider_type} · {allocation.provider_driver} · {allocation.provider_identity}</span>
                                                    {allocation.financial_terms && <span className="text-xs font-medium text-foreground">{rateSummary(allocation.financial_terms)}</span>}
                                                </span>
                                            </label>
                                    ))}
                                </div>
                                {membership.status === 'pending' && (
                                    <Button type="button" className="mt-4" onClick={() => acceptMembership(membership)}>Confirm Selected APIs</Button>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </TenantSidebarLayout>
    );
}
