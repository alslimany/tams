import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import { Badge } from "@/Components/ui/Badge";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/Components/ui/Card";
import { Switch } from "@/Components/ui/Switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/ui/Tabs";
import { Loader2, CheckCircle2, XCircle, Settings2, Plane, Globe } from "lucide-react";
import { toast } from "sonner"; // Assuming sonner is installed

export default function Index({ airlines }) {
    const [selectedAirline, setSelectedAirline] = useState(null);
    const [selectedAccount, setSelectedAccount] = useState(null);
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);

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
    });

    const openConfig = (airline, account) => {
        setSelectedAirline(airline);
        setSelectedAccount(account);
        setTestResult(null);
        
        // Populate form with account defaults
        setData({
            provider_type: airline.provider_type,
            airline_code: airline.id,
            airline_name: airline.name,
            account_name: account.name,
            mode: 'session',
            username: '',
            password: '',
            token: '',
            base_url: airline.base_url,
            currency: account.currency,
            airports: account.airports || [],
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

    return (
        <TenantLayout>
            <Head title="Airline Configuration" />
            
            <div className="flex justify-between items-center mb-6">
                <div>
                    <h2 className="text-3xl font-bold tracking-tight">Airline Configuration</h2>
                    <p className="text-muted-foreground">Manage your airline provider connections and credentials.</p>
                </div>
            </div>

            <div className="grid gap-6">
                {airlines.map((airline) => (
                    <Card key={airline.id}>
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="bg-primary/10 p-2 rounded-lg">
                                        <Plane className="h-6 w-6 text-primary" />
                                    </div>
                                    <div>
                                        <CardTitle>{airline.name}</CardTitle>
                                        <CardDescription>IATA Code: {airline.id}</CardDescription>
                                    </div>
                                </div>
                                <Badge variant="outline" className="capitalize">
                                    {airline.provider_type}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {airline.accounts.map((account) => (
                                    <div key={account.name} className="flex items-center justify-between p-4 border rounded-lg bg-muted/30">
                                        <div className="flex items-center gap-4">
                                            <div className="flex flex-col">
                                                <span className="font-medium">{account.name}</span>
                                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                    <Badge variant="secondary" className="text-[10px] py-0">{account.currency}</Badge>
                                                    {account.airports && (
                                                        <span>Airports: {account.airports.join(', ')}</span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-4">
                                            <div className="flex items-center gap-2">
                                                <span className="text-xs text-muted-foreground">
                                                    {account.is_enabled ? 'Enabled' : 'Disabled'}
                                                </span>
                                                <Switch 
                                                    checked={account.is_enabled}
                                                    onCheckedChange={() => {
                                                        if (account.is_enabled) {
                                                            toggleAirline(account.config_id);
                                                        } else {
                                                            openConfig(airline, account);
                                                        }
                                                    }}
                                                />
                                            </div>
                                            <Button 
                                                variant="ghost" 
                                                size="icon"
                                                onClick={() => openConfig(airline, account)}
                                            >
                                                <Settings2 className="h-4 w-4" />
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
                                <Tabs value={data.mode} onValueChange={(val) => setData('mode', val)} className="w-full">
                                    <TabsList className="grid w-full grid-cols-2">
                                        <TabsTrigger value="session">User / Auth Mode</TabsTrigger>
                                        <TabsTrigger value="soap">API / Token Mode</TabsTrigger>
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
                                    <TabsContent value="soap" className="space-y-4 pt-4">
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
        </TenantLayout>
    );
}
