import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';
import { Switch } from '@/Components/ui/Switch';
import { Badge } from '@/Components/ui/Badge';
import { Settings, Shield } from 'lucide-react';

export default function InsuranceSettings({ providers = [] }) {
    const [selectedProvider, setSelectedProvider] = React.useState(null);
    const [depositAmounts, setDepositAmounts] = React.useState({});

    const form = useForm({
        provider_type: '',
        name: '',
        base_url: '',
        token: '',
        is_active: false,
        commission_compulsory: 0,
        commission_travel: 0,
        commission_orange: 0,
        initial_balance: '',
    });

    const depositForm = useForm({
        provider_type: '',
        currency: 'LYD',
        amount: '',
        description: '',
    });

    const openConfig = (provider) => {
        setSelectedProvider(provider);
        form.setData({
            provider_type: provider.provider_type,
            name: provider.name,
            base_url: provider.base_url,
            token: provider.token || '',
            is_active: Boolean(provider.is_active),
            commission_compulsory: provider.commission_compulsory ?? 0,
            commission_travel: provider.commission_travel ?? 0,
            commission_orange: provider.commission_orange ?? 0,
            initial_balance: '',
        });
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('settings.insurance.store'), {
            onSuccess: () => {
                setSelectedProvider(null);
            },
        });
    };

    const submitDeposit = (provider) => {
        const amount = depositAmounts[provider.provider_type] ?? '';

        if (!amount) {
            return;
        }

        depositForm.setData({
            provider_type: provider.provider_type,
            currency: provider.currency || 'LYD',
            amount,
            description: '',
        });

        depositForm.post(route('settings.insurance.deposit'), {
            preserveScroll: true,
            onSuccess: () => {
                setDepositAmounts((prev) => ({
                    ...prev,
                    [provider.provider_type]: '',
                }));
            },
        });
    };

    return (
        <TenantSidebarLayout>
            <Head title="Insurance Configuration" />

            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
                <div>
                    <h1 className="text-2xl font-bold">Insurance Configuration</h1>
                    <p className="text-sm text-muted-foreground">Preconfigured insurance providers are listed below. Configure credentials and commissions per provider.</p>
                </div>

                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {providers.map((provider) => (
                        <Card key={provider.provider_type}>
                            <CardHeader>
                                <div className="flex items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <div className="rounded-md bg-primary/10 p-2">
                                            <Shield className="h-4 w-4 text-primary" />
                                        </div>
                                        <div>
                                            <CardTitle>{provider.name}</CardTitle>
                                            <CardDescription>{provider.description}</CardDescription>
                                        </div>
                                    </div>
                                    <Badge variant={provider.status === 'configured' ? 'default' : 'outline'}>
                                        {provider.status === 'configured' ? 'Configured' : 'Pending'}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="text-xs text-muted-foreground">Base URL: {provider.base_url}</div>
                                <div className="text-xs text-muted-foreground">
                                    Wallet Balance: <span className="font-medium text-foreground">{provider.remaining_balance?.toFixed(2)} {provider.currency || 'LYD'}</span>
                                </div>
                                <div className="grid gap-1 text-xs text-muted-foreground">
                                    <div>Compulsory: {provider.commission_compulsory}%</div>
                                    <div>Travel: {provider.commission_travel}%</div>
                                    <div>Orange: {provider.commission_orange}%</div>
                                </div>
                                {provider.requires_initial_balance && (
                                    <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        This provider does not expose a balance API. Add an initial balance before issuing policies.
                                    </div>
                                )}
                                <div className="grid grid-cols-[1fr_auto] gap-2">
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="Deposit amount"
                                        value={depositAmounts[provider.provider_type] ?? ''}
                                        onChange={(event) =>
                                            setDepositAmounts((prev) => ({
                                                ...prev,
                                                [provider.provider_type]: event.target.value,
                                            }))
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => submitDeposit(provider)}
                                        disabled={depositForm.processing}
                                    >
                                        Deposit
                                    </Button>
                                </div>
                                <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                    <span className="text-sm">Active</span>
                                    <Switch checked={Boolean(provider.is_active)} disabled />
                                </div>
                                <Button type="button" variant="outline" className="w-full" onClick={() => openConfig(provider)}>
                                    <Settings className="mr-2 h-4 w-4" />
                                    Configure
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {selectedProvider && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/80 p-4 backdrop-blur-sm">
                        <Card className="w-full max-w-2xl">
                            <CardHeader>
                                <CardTitle>Configure {selectedProvider.name}</CardTitle>
                                <CardDescription>Set bearer token/auth details and commissions for this provider.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form className="space-y-5" onSubmit={submit}>
                                    <Input type="hidden" value={form.data.provider_type} onChange={() => {}} />

                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Provider Name</Label>
                                        <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                                        {form.errors.name && <p className="text-xs text-destructive">{form.errors.name}</p>}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="base_url">API Base URL</Label>
                                        <Input id="base_url" value={form.data.base_url} onChange={(event) => form.setData('base_url', event.target.value)} />
                                        {form.errors.base_url && <p className="text-xs text-destructive">{form.errors.base_url}</p>}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="token">Bearer Token / Auth Token</Label>
                                        <Input id="token" value={form.data.token} onChange={(event) => form.setData('token', event.target.value)} placeholder="Paste provider bearer token" />
                                        {form.errors.token && <p className="text-xs text-destructive">{form.errors.token}</p>}
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="commission_compulsory">Compulsory Commission %</Label>
                                            <Input
                                                id="commission_compulsory"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value={form.data.commission_compulsory}
                                                onChange={(event) => form.setData('commission_compulsory', event.target.value)}
                                            />
                                            {form.errors.commission_compulsory && <p className="text-xs text-destructive">{form.errors.commission_compulsory}</p>}
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="commission_travel">Travel Commission %</Label>
                                            <Input
                                                id="commission_travel"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value={form.data.commission_travel}
                                                onChange={(event) => form.setData('commission_travel', event.target.value)}
                                            />
                                            {form.errors.commission_travel && <p className="text-xs text-destructive">{form.errors.commission_travel}</p>}
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="commission_orange">Orange Commission %</Label>
                                            <Input
                                                id="commission_orange"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value={form.data.commission_orange}
                                                onChange={(event) => form.setData('commission_orange', event.target.value)}
                                            />
                                            {form.errors.commission_orange && <p className="text-xs text-destructive">{form.errors.commission_orange}</p>}
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="initial_balance">Initial Provider Wallet Balance (optional)</Label>
                                        <Input
                                            id="initial_balance"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={form.data.initial_balance}
                                            onChange={(event) => form.setData('initial_balance', event.target.value)}
                                            placeholder="0.00"
                                        />
                                        <p className="text-xs text-muted-foreground">Applied only when current provider wallet balance is zero.</p>
                                        {form.errors.initial_balance && <p className="text-xs text-destructive">{form.errors.initial_balance}</p>}
                                    </div>

                                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <p className="text-sm">Provider Active</p>
                                        <Switch checked={form.data.is_active} onCheckedChange={(checked) => form.setData('is_active', checked)} />
                                    </div>

                                    <div className="flex justify-end gap-2">
                                        <Button type="button" variant="ghost" onClick={() => setSelectedProvider(null)}>Cancel</Button>
                                        <Button type="submit" disabled={form.processing}>Save Configuration</Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </TenantSidebarLayout>
    );
}
