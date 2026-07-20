import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from '@/hooks/useTranslation';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';
import { Switch } from '@/Components/ui/Switch';
import { Badge } from '@/Components/ui/Badge';
import { Settings, SmartphoneNfcIcon, Building2 } from 'lucide-react';

export default function ESimSettings({ providers = [] }) {
    const { t } = useTranslation();
    const [selectedProvider, setSelectedProvider] = React.useState(null);
    const [depositAmounts, setDepositAmounts] = React.useState({});

    const form = useForm({
        provider_type: '',
        name: '',
        base_url: '',
        api_key: '',
        client_secret: '',
        is_active: false,
        commission_esim: 0,
        usd_to_lyd_rate: '',
        initial_balance: '',
    });

    const depositForm = useForm({
        provider_type: '',
        amount: '',
        description: '',
    });

    const openConfig = (provider) => {
        setSelectedProvider(provider);
        form.setData({
            provider_type: provider.provider_type,
            name: provider.name,
            base_url: provider.base_url,
            api_key: provider.api_key || '',
            client_secret: provider.client_secret || '',
            is_active: Boolean(provider.is_active),
            commission_esim: provider.commission_esim ?? 0,
            usd_to_lyd_rate: provider.usd_to_lyd_rate ?? '',
            initial_balance: '',
        });
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('settings.esim.store'), {
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
            amount,
            description: '',
        });

        depositForm.post(route('settings.esim.deposit'), {
            preserveScroll: true,
            onSuccess: () => {
                setDepositAmounts((current) => ({ ...current, [provider.provider_type]: '' }));
            },
        });
    };

    return (
        <TenantSidebarLayout>
            <Head title={t('tenant.nav.esim_config')} />

            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
                <div>
                    <h1 className="text-2xl font-bold">{t('tenant.nav.esim_config')}</h1>
                    <p className="text-sm text-muted-foreground">{t('esim.settings.description')}</p>
                </div>

                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {providers.map((provider) => (
                        <Card key={provider.provider_type}>
                            <CardHeader>
                                <div className="flex items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <div className="rounded-md bg-primary/10 p-2">
                                        <SmartphoneNfcIcon className="h-4 w-4 text-primary" />
                                        </div>
                                        <div>
                                            <CardTitle>{provider.name}</CardTitle>
                                            <CardDescription>{provider.description}</CardDescription>
                                        </div>
                                    </div>
                                    <Badge variant={provider.status === 'configured' ? 'default' : 'outline'}>
                                        {provider.status === 'configured' ? t('esim.settings.configured') : t('esim.settings.pending')}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="text-xs text-muted-foreground">
                                    {t('esim.settings.base_url')}: {provider.base_url}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {t('esim.settings.wallet_balance')}:{' '}
                                    <span className="font-medium text-foreground">
                                        {provider.remaining_balance?.toFixed(2)} {provider.currency || provider.default_currency || 'USD'}
                                    </span>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {t('esim.settings.commission')}: {provider.commission_esim}%
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {t('esim.settings.usd_to_lyd_rate')}:{' '}
                                    {provider.usd_to_lyd_rate
                                        ? `${provider.usd_to_lyd_rate} LYD`
                                        : t('esim.settings.usd_to_lyd_rate_not_set')}
                                </div>

                                {provider.provider_org && (
                                    <div className="rounded-md border bg-muted/30 px-3 py-2.5 space-y-1.5">
                                        <p className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                            <Building2 className="h-3 w-3" />
                                            {t('esim.settings.provider_account')}
                                        </p>
                                        <p className="text-sm font-medium">
                                            {[provider.provider_org.firstName, provider.provider_org.lastName].filter(Boolean).join(' ') || '—'}
                                        </p>
                                        {provider.provider_org.email && (
                                            <p className="text-xs text-muted-foreground">{provider.provider_org.email}</p>
                                        )}
                                        <div className="flex items-center justify-between pt-0.5">
                                            <p className="text-xs text-muted-foreground">{t('esim.settings.provider_balance')}</p>
                                            <p className="text-sm font-semibold text-primary">
                                                {Number(provider.provider_org.balance).toFixed(2)} {provider.currency || 'USD'}
                                            </p>
                                        </div>
                                    </div>
                                )}
                                {provider.requires_initial_balance && (
                                    <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        {t('esim.settings.add_balance_notice')}
                                    </div>
                                )}
                                <div className="grid grid-cols-[1fr_auto] gap-2">
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder={t('esim.settings.deposit_placeholder')}
                                        value={depositAmounts[provider.provider_type] ?? ''}
                                        onChange={(event) =>
                                            setDepositAmounts((current) => ({
                                                ...current,
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
                                        {t('esim.settings.deposit')}
                                    </Button>
                                </div>
                                <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                    <span className="text-sm">{t('esim.settings.active')}</span>
                                    <Switch checked={Boolean(provider.is_active)} disabled />
                                </div>
                                <Button type="button" variant="outline" className="w-full" onClick={() => openConfig(provider)}>
                                    <Settings className="mr-2 h-4 w-4" />
                                    {t('esim.settings.configure')}
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {selectedProvider && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/80 p-4 backdrop-blur-sm">
                        <Card className="w-full max-w-2xl">
                            <CardHeader>
                                <CardTitle>{t('esim.settings.configure_title', { name: selectedProvider.name })}</CardTitle>
                                <CardDescription>{t('esim.settings.configure_description')}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form className="space-y-5" onSubmit={submit}>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">{t('esim.settings.provider_name')}</Label>
                                        <Input
                                            id="name"
                                            value={form.data.name}
                                            onChange={(event) => form.setData('name', event.target.value)}
                                        />
                                        {form.errors.name && <p className="text-xs text-destructive">{form.errors.name}</p>}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="base_url">{t('esim.settings.base_url')}</Label>
                                        <Input
                                            id="base_url"
                                            value={form.data.base_url}
                                            onChange={(event) => form.setData('base_url', event.target.value)}
                                        />
                                        {form.errors.base_url && <p className="text-xs text-destructive">{form.errors.base_url}</p>}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="api_key">{t('esim.settings.api_key')}</Label>
                                        <Input
                                            id="api_key"
                                            type="password"
                                            value={form.data.api_key}
                                            onChange={(event) => form.setData('api_key', event.target.value)}
                                        />
                                        {form.errors.api_key && <p className="text-xs text-destructive">{form.errors.api_key}</p>}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="client_secret">{t('esim.settings.client_secret')}</Label>
                                        <Input
                                            id="client_secret"
                                            type="password"
                                            value={form.data.client_secret}
                                            onChange={(event) => form.setData('client_secret', event.target.value)}
                                        />
                                        {form.errors.client_secret && <p className="text-xs text-destructive">{form.errors.client_secret}</p>}
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="commission_esim">{t('esim.settings.commission_label')}</Label>
                                            <Input
                                                id="commission_esim"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value={form.data.commission_esim}
                                                onChange={(event) => form.setData('commission_esim', event.target.value)}
                                            />
                                            {form.errors.commission_esim && (
                                                <p className="text-xs text-destructive">{form.errors.commission_esim}</p>
                                            )}
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="usd_to_lyd_rate">{t('esim.settings.usd_to_lyd_rate')}</Label>
                                            <Input
                                                id="usd_to_lyd_rate"
                                                type="number"
                                                min="0"
                                                step="0.0001"
                                                value={form.data.usd_to_lyd_rate}
                                                onChange={(event) => form.setData('usd_to_lyd_rate', event.target.value)}
                                                placeholder={t('esim.settings.usd_to_lyd_rate_placeholder')}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t('esim.settings.usd_to_lyd_rate_help')}
                                            </p>
                                            {form.errors.usd_to_lyd_rate && (
                                                <p className="text-xs text-destructive">{form.errors.usd_to_lyd_rate}</p>
                                            )}
                                        </div>
                                        <div className="grid gap-2 sm:col-span-2">
                                            <Label htmlFor="initial_balance">{t('esim.settings.initial_balance')}</Label>
                                            <Input
                                                id="initial_balance"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={form.data.initial_balance}
                                                onChange={(event) => form.setData('initial_balance', event.target.value)}
                                                placeholder="0.00"
                                            />
                                            {form.errors.initial_balance && (
                                                <p className="text-xs text-destructive">{form.errors.initial_balance}</p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <p className="text-sm">{t('esim.settings.provider_active')}</p>
                                        <Switch
                                            checked={form.data.is_active}
                                            onCheckedChange={(checked) => form.setData('is_active', checked)}
                                        />
                                    </div>

                                    <div className="flex justify-end gap-2">
                                        <Button type="button" variant="ghost" onClick={() => setSelectedProvider(null)}>
                                            {t('esim.settings.cancel')}
                                        </Button>
                                        <Button type="submit" disabled={form.processing}>
                                            {t('esim.settings.save')}
                                        </Button>
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
