import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';
import { Switch } from '@/Components/ui/Switch';
import { Badge } from '@/Components/ui/Badge';
import { HotelIcon, Settings } from 'lucide-react';

export default function HotelSettings({ providers = [] }) {
    const [selectedProvider, setSelectedProvider] = React.useState(null);
    const [depositAmounts, setDepositAmounts] = React.useState({});

    const form = useForm({
        provider_type: '',
        name: '',
        base_url: '',
        api_key: '',
        login: '',
        password: '',
        is_active: false,
        commission_hotel: 0,
        currency: 'LYD',
        initial_balance: '',
    });

    const depositForm = useForm({
        provider_type: '',
        currency: 'LYD',
        amount: '',
        description: '',
    });

    const creditCheckForm = useForm({
        provider_type: '',
    });

    const openConfig = (provider) => {
        setSelectedProvider(provider);
        form.setData({
            provider_type: provider.provider_type,
            name: provider.name,
            base_url: provider.base_url,
            api_key: provider.api_key || '',
            login: provider.login || '',
            password: provider.password || '',
            is_active: Boolean(provider.is_active),
            commission_hotel: provider.commission_hotel ?? 0,
            currency: provider.currency || provider.default_currency || 'LYD',
            initial_balance: '',
        });
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('settings.hotels.store'), {
            onSuccess: () => setSelectedProvider(null),
        });
    };

    const submitDeposit = (provider) => {
        const amount = depositAmounts[provider.provider_type] ?? '';

        if (!amount) {
            return;
        }

        depositForm.setData({
            provider_type: provider.provider_type,
            currency: provider.currency || provider.default_currency || 'LYD',
            amount,
            description: '',
        });

        depositForm.post(route('settings.hotels.deposit'), {
            preserveScroll: true,
            onSuccess: () => {
                setDepositAmounts((current) => ({ ...current, [provider.provider_type]: '' }));
            },
        });
    };

    const syncCredit = (provider) => {
        creditCheckForm.setData('provider_type', provider.provider_type);

        creditCheckForm.post(route('settings.hotels.credit-check'), {
            preserveScroll: true,
        });
    };

    return (
        <TenantSidebarLayout>
            <Head title="Hotel Configuration" />

            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
                <div>
                    <h1 className="text-2xl font-bold">Hotel Configuration</h1>
                    <p className="text-sm text-muted-foreground">Configure 3T hotel provider credentials, markup profit, and provider wallet balance.</p>
                </div>

                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {providers.map((provider) => (
                        <Card key={provider.provider_type}>
                            <CardHeader>
                                <div className="flex items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <div className="rounded-md bg-primary/10 p-2">
                                            <HotelIcon className="h-4 w-4 text-primary" />
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
                                    Wallet Balance: <span className="font-medium text-foreground">{provider.remaining_balance?.toFixed(2)} {provider.currency || provider.default_currency || 'LYD'}</span>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    3T Credit: <span className="font-medium text-foreground">{Number(provider.provider_credit_balance ?? 0).toFixed(2)} {provider.provider_credit_currency || provider.currency || 'LYD'}</span>
                                    {provider.provider_credit_checked_at && <span> · synced {new Date(provider.provider_credit_checked_at).toLocaleString()}</span>}
                                </div>
                                <div className="text-xs text-muted-foreground">Markup Profit: {provider.commission_hotel}%</div>
                                {provider.requires_initial_balance && (
                                    <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Add provider wallet balance before booking hotels.
                                    </div>
                                )}
                                <div className="grid grid-cols-[1fr_auto] gap-2">
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="Deposit amount"
                                        value={depositAmounts[provider.provider_type] ?? ''}
                                        onChange={(event) => setDepositAmounts((current) => ({ ...current, [provider.provider_type]: event.target.value }))}
                                    />
                                    <Button type="button" variant="secondary" onClick={() => submitDeposit(provider)} disabled={depositForm.processing}>
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
                                <Button type="button" variant="outline" className="w-full" onClick={() => syncCredit(provider)} disabled={creditCheckForm.processing || provider.status !== 'configured'}>
                                    Sync 3T Credit
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
                                <CardDescription>Set 3T credentials and hotel markup profit.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form className="space-y-5" onSubmit={submit}>
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
                                        <Label htmlFor="api_key">API Key</Label>
                                        <Input id="api_key" value={form.data.api_key} onChange={(event) => form.setData('api_key', event.target.value)} />
                                        {form.errors.api_key && <p className="text-xs text-destructive">{form.errors.api_key}</p>}
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="login">Login</Label>
                                            <Input id="login" value={form.data.login} onChange={(event) => form.setData('login', event.target.value)} />
                                            {form.errors.login && <p className="text-xs text-destructive">{form.errors.login}</p>}
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="password">Password</Label>
                                            <Input id="password" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                                            {form.errors.password && <p className="text-xs text-destructive">{form.errors.password}</p>}
                                        </div>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="commission_hotel">Markup Profit %</Label>
                                            <Input id="commission_hotel" type="number" min="0" max="100" step="0.01" value={form.data.commission_hotel} onChange={(event) => form.setData('commission_hotel', event.target.value)} />
                                            {form.errors.commission_hotel && <p className="text-xs text-destructive">{form.errors.commission_hotel}</p>}
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="currency">Provider Wallet Currency</Label>
                                            <Input id="currency" maxLength="3" value={form.data.currency} onChange={(event) => form.setData('currency', event.target.value.toUpperCase())} placeholder="LYD" />
                                            {form.errors.currency && <p className="text-xs text-destructive">{form.errors.currency}</p>}
                                        </div>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="initial_balance">Initial Wallet Balance</Label>
                                            <Input id="initial_balance" type="number" min="0" step="0.01" value={form.data.initial_balance} onChange={(event) => form.setData('initial_balance', event.target.value)} placeholder="0.00" />
                                            {form.errors.initial_balance && <p className="text-xs text-destructive">{form.errors.initial_balance}</p>}
                                        </div>
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
