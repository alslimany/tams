import React, { useState } from 'react';
import axios from 'axios';
import { Head, useForm, router } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import { Badge } from "@/Components/ui/Badge";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/Components/ui/Card";
import { Switch } from "@/Components/ui/Switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/ui/Tabs";
import { Loader2, CheckCircle2, XCircle, Plane, Globe, Settings } from "lucide-react";
import { toast } from "sonner";
import { formatMoney } from '@/lib/currency';

export default function Index({ airlines }) {
    const [selectedAirline, setSelectedAirline] = useState(null);
    const [selectedAccount, setSelectedAccount] = useState(null);
    const [selectedConfigTab, setSelectedConfigTab] = useState('connection');
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);
    const [depositAmounts, setDepositAmounts] = useState({});

    const { data, setData, post, processing, errors, reset } = useForm({
        provider_type: '',
        airline_code: '',
        airline_name: '',
        account_name: '',
        mode: 'session',
        username: '',
        password: '',
        token: '',
        base_url: '',
        currency: '',
        airports: [],
        domestic_commission_rate: '',
        international_commission_rate: '',
        commission_domestic: '',
        commission_international: '',
    });

    const openConfig = (airline, account) => {
        setSelectedAirline(airline);
        setSelectedAccount(account);
        setSelectedConfigTab('connection');
        setTestResult(null);

        // Populate form with account defaults and existing credentials
        setData({
            provider_type: airline.provider_type,
            airline_code: airline.id,
            airline_name: airline.name,
            account_name: account.name,
            mode: account.credentials?.mode || 'session',
            username: account.credentials?.username || '',
            password: account.credentials?.password || '',
            token: account.credentials?.token || '',
            base_url: airline.base_url,
            currency: account.currency,
            airports: account.airports || [],
            domestic_commission_rate: account.domestic_commission_rate || '',
            international_commission_rate: account.international_commission_rate || '',
            commission_domestic: account.commission_domestic || '',
            commission_international: account.commission_international || '',
        });
    };

    const handleTest = () => {
        setTesting(true);
        setTestResult(null);

        axios.post(route('settings.airlines.test'), data, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            }
        })
            .then(res => {
                setTestResult({ success: true, message: res.data.message });
                toast.success(res.data.message);
            })
            .catch(err => {
                let msg = err.response?.data?.message || 'Connection test failed.';
                if (err.response?.data?.errors) {
                    const errors = err.response.data.errors;
                    msg = Object.values(errors).flat().join(' ');
                }
                setTestResult({ success: false, message: msg });
                toast.error(msg);
            })
            .finally(() => setTesting(false));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.airlines.store'), {
            onSuccess: () => {
                setSelectedAirline(null);
                setSelectedAccount(null);
            }
        });
    };

    const toggleAirline = (id) => {
        if (!id) return;
        router.patch(route('settings.airlines.toggle', id));
    };

    const submitDeposit = (account) => {
        if (!account?.config_id) {
            return;
        }

        const amount = depositAmounts[account.config_id] ?? '';

        if (!amount) {
            return;
        }

        router.post(route('settings.airlines.deposit'), {
            tenant_provider_id: account.config_id,
            currency: account.currency,
            amount,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setDepositAmounts((prev) => ({
                    ...prev,
                    [account.config_id]: '',
                }));
            },
        });
    };

    const isVidecomProvider = selectedAirline?.provider_type === 'videcom';

    return (
        <TenantSidebarLayout>
            <Head title="Airline Configuration" />

            <div className="flex justify-between items-center mb-6">
                <div>
                    <h2 className="text-3xl font-bold tracking-tight">Airline Configuration</h2>
                    <p className="text-muted-foreground">Manage your airline provider connections and credentials.</p>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                {airlines.map((airline) => (
                    <Card key={airline.id}>
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="bg-primary/5 p-1 rounded-sm shrink-0 flex items-center justify-center min-w-12 min-h-12">
                                        {airline.icao || airline.iata || airline.id ? (
                                            <img src={route('api.airlines.logo', { code: airline.icao || airline.iata || airline.id, variant: 'icon-transparent', radius: 8 })} alt={airline.name} className="h-10 w-10 object-contain mix-blend-multiply dark:mix-blend-normal" onError={(e) => { e.target.style.display = 'none'; e.target.nextSibling.style.display = 'block'; }} />
                                        ) : null}
                                        <Plane className="h-6 w-6 text-primary" style={{ display: (airline.icao || airline.iata || airline.id) ? 'none' : 'block' }} />
                                    </div>
                                    <div>
                                        <CardTitle>{airline.name}</CardTitle>
                                        {/* <CardDescription>IATA Code: {airline.id}</CardDescription> */}
                                    </div>
                                </div>
                                {false && ( // Future feature: show overall connection status based on recent test results
                                    <Badge variant="outline" className="capitalize">
                                        {airline.provider_type}
                                    </Badge>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {airline.accounts.map((account) => (
                                    <div key={account.name} className="flex items-center justify-between p-4 border rounded-lg bg-muted/30">
                                        <div className="flex items-center gap-4">
                                            <div className="flex flex-col">
                                               
                                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                    {/* <Badge variant="secondary" className="text-[10px] py-0">{account.currency}</Badge> */}
                                                    {account.airports && (
                                                        <span>Airports: {account.airports.join(', ')}</span>
                                                    )}
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    Balance: <span className="font-medium text-foreground">{formatMoney(account.remaining_balance, account.currency)}</span>
                                                </div>
                                                {account.config_id && (
                                                    <div className="mt-2 grid grid-cols-[1fr_auto] gap-2">
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            placeholder="Deposit"
                                                            value={depositAmounts[account.config_id] ?? ''}
                                                            onChange={(event) =>
                                                                setDepositAmounts((prev) => ({
                                                                    ...prev,
                                                                    [account.config_id]: event.target.value,
                                                                }))
                                                            }
                                                        />
                                                        <Button type="button" size="sm" variant="secondary" onClick={() => submitDeposit(account)}>
                                                            Deposit
                                                        </Button>
                                                    </div>
                                                )}
                                                {airline.provider_type === 'videcom' && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        Domestic Commission <span className="font-medium text-foreground">{account.domestic_commission_rate || '0.00'}%</span>
                                                        {account.commission_domestic > 0 && <span className="ml-1">+ {account.commission_domestic} fixed</span>}
                                                        <br />
                                                        International Commission <span className="font-medium text-foreground">{account.international_commission_rate || '0.00'}%</span>
                                                        {account.commission_international > 0 && <span className="ml-1">+ {account.commission_international} fixed</span>}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            
                                            
                                            <Switch
                                                checked={account.is_enabled}
                                                onCheckedChange={(nextChecked) => {
                                                    if (nextChecked) {
                                                        // Always show config modal on enable so user can review credentials/mode.
                                                        openConfig(airline, account);
                                                        return;
                                                    }

                                                    if (account.config_id) {
                                                        toggleAirline(account.config_id);
                                                    }
                                                }}
                                            />

                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => openConfig(airline, account)}
                                            >
                                                <Settings className="h-3.5 w-3.5" />

                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {selectedAirline && (
                <div className="fixed inset-0 bg-background/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <Card className="w-full max-w-2xl shadow-2xl">
                        <form onSubmit={submit}>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle>Configure {selectedAirline.name}</CardTitle>
                                    <Button variant="ghost" size="icon" onClick={() => setSelectedAirline(null)} type="button">
                                        <XCircle className="h-5 w-5" />
                                    </Button>
                                </div>
                                <CardDescription>
                                    Setting up <strong>{selectedAccount.name}</strong> for {selectedAirline.name}.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <Tabs value={selectedConfigTab} onValueChange={setSelectedConfigTab} className="w-full">
                                    <TabsList className={`grid w-full ${isVidecomProvider ? 'grid-cols-2' : 'grid-cols-1'}`}>
                                        <TabsTrigger value="connection">Connection</TabsTrigger>
                                        {isVidecomProvider && <TabsTrigger value="commission">Commission</TabsTrigger>}
                                    </TabsList>
                                    <TabsContent value="connection" className="space-y-6 pt-4">
                                        <Tabs value={data.mode} onValueChange={(val) => setData('mode', val)} className="w-full">
                                            <TabsList className="grid w-full grid-cols-2">
                                                <TabsTrigger value="session">User / Auth Mode</TabsTrigger>
                                                <TabsTrigger value="api">API / Token Mode</TabsTrigger>
                                            </TabsList>
                                            <TabsContent value="session" className="space-y-4 pt-4">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="username">VRS Sine Code / Username</Label>
                                                    <Input
                                                        id="username"
                                                        value={data.username}
                                                        onChange={e => setData('username', e.target.value)}
                                                        placeholder="e.g. AGENT123"
                                                    />
                                                    {errors.username && <p className="text-xs text-destructive">{errors.username}</p>}
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="password">VRS Password</Label>
                                                    <Input
                                                        id="password"
                                                        type="password"
                                                        value={data.password}
                                                        onChange={e => setData('password', e.target.value)}
                                                        placeholder="••••••••"
                                                    />
                                                    {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
                                                </div>
                                            </TabsContent>
                                            <TabsContent value="api" className="space-y-4 pt-4">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="token">API Token</Label>
                                                    <Input
                                                        id="token"
                                                        value={data.token}
                                                        onChange={e => setData('token', e.target.value)}
                                                        placeholder="Paste your XML API token here"
                                                    />
                                                    {errors.token && <p className="text-xs text-destructive">{errors.token}</p>}
                                                </div>
                                            </TabsContent>
                                        </Tabs>

                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="grid gap-2">
                                                <Label>Currency</Label>
                                                <Input value={data.currency} disabled />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label>Base URL</Label>
                                                <Input value={data.base_url} disabled />
                                            </div>
                                        </div>
                                    </TabsContent>
                                    {isVidecomProvider && (
                                        <TabsContent value="commission" className="space-y-4 pt-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="domestic_commission_rate">Domestic Commission %</Label>
                                                <Input
                                                    id="domestic_commission_rate"
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    value={data.domestic_commission_rate}
                                                    onChange={e => setData('domestic_commission_rate', e.target.value)}
                                                    placeholder="0.00"
                                                />
                                                {errors.domestic_commission_rate && <p className="text-xs text-destructive">{errors.domestic_commission_rate}</p>}
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="international_commission_rate">International Commission %</Label>
                                                <Input
                                                    id="international_commission_rate"
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    value={data.international_commission_rate}
                                                    onChange={e => setData('international_commission_rate', e.target.value)}
                                                    placeholder="0.00"
                                                />
                                                {errors.international_commission_rate && <p className="text-xs text-destructive">{errors.international_commission_rate}</p>}
                                            </div>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="commission_domestic">Domestic Fixed Amount</Label>
                                                    <Input
                                                        id="commission_domestic"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={data.commission_domestic}
                                                        onChange={e => setData('commission_domestic', e.target.value)}
                                                        placeholder="0.00"
                                                    />
                                                    {errors.commission_domestic && <p className="text-xs text-destructive">{errors.commission_domestic}</p>}
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="commission_international">International Fixed Amount</Label>
                                                    <Input
                                                        id="commission_international"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={data.commission_international}
                                                        onChange={e => setData('commission_international', e.target.value)}
                                                        placeholder="0.00"
                                                    />
                                                    {errors.commission_international && <p className="text-xs text-destructive">{errors.commission_international}</p>}
                                                </div>
                                            </div>
                                            <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                                                Commission is calculated as: percentage of fare + fixed amount. Domestic flights use the domestic rate, and international flights use the international rate.
                                            </div>
                                        </TabsContent>
                                    )}
                                </Tabs>

                                {testResult && (
                                    <div className={`p-3 rounded-lg flex items-center gap-3 text-sm ${testResult.success ? 'bg-green-500/10 text-green-600 border border-green-200' : 'bg-destructive/10 text-destructive border border-destructive/20'}`}>
                                        {testResult.success ? <CheckCircle2 className="h-5 w-5" /> : <XCircle className="h-5 w-5" />}
                                        {testResult.message}
                                    </div>
                                )}
                            </CardContent>
                            <CardFooter className="flex justify-between border-t pt-6">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={handleTest}
                                    disabled={testing || processing}
                                >
                                    {testing ? (
                                        <>
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                            Testing Credentials...
                                        </>
                                    ) : (
                                        <>
                                            <Globe className="mr-2 h-4 w-4" />
                                            Test Connection
                                        </>
                                    )}
                                </Button>
                                <div className="flex gap-2">
                                    <Button variant="ghost" onClick={() => setSelectedAirline(null)} type="button">
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing || testing}>
                                        Save Configuration
                                    </Button>
                                </div>
                            </CardFooter>
                        </form>
                    </Card>
                </div>
            )}
        </TenantSidebarLayout>
    );
}
