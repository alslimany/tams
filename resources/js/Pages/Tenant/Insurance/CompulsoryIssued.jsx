import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { CheckCircle2, Wallet, ReceiptText, Percent, ArrowRight } from 'lucide-react';

function formatMoney(amount, currency = 'LYD') {
    const numeric = Number(amount ?? 0);

    return `${numeric.toFixed(2)} ${currency}`;
}

export default function CompulsoryIssued({ order, item }) {
    const currency = String(order?.currency ?? item?.currency ?? 'LYD');
    const chargedFromWallet = Number(item?.total_amount ?? order?.grand_total ?? 0);
    const netPremium = Number(item?.net_fare ?? order?.subtotal ?? 0);
    const commissionPercent = Number(item?.commission_percent ?? 0);
    const commissionAmount = Number(item?.commission_amount ?? 0);
    const policyNumber = item?.provider_reference || item?.ticket_number || '-';

    return (
        <TenantNavbarLayout>
            <Head title="Compulsory Policy Issued" />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
                <Card className="border-2 border-emerald-200 bg-emerald-50/60">
                    <CardContent className="flex flex-col gap-4 py-8 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-start gap-3">
                            <CheckCircle2 className="mt-0.5 h-7 w-7 text-emerald-600" />
                            <div>
                                <p className="text-xs font-black uppercase tracking-widest text-emerald-700">Policy Issued</p>
                                <h1 className="text-3xl font-black text-emerald-800">Compulsory Insurance Confirmed</h1>
                                <p className="mt-1 text-sm text-emerald-700">
                                    Your compulsory insurance policy was issued successfully.
                                </p>
                            </div>
                        </div>
                        <Badge className="w-fit bg-emerald-600 text-white">Issued</Badge>
                    </CardContent>
                </Card>

                <div className="grid gap-6 md:grid-cols-2">
                    <Card className="border-2 shadow-sm">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Wallet className="h-5 w-5 text-primary" /> Financial Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Charged from wallet</span>
                                <span className="font-black text-primary">{formatMoney(chargedFromWallet, currency)}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Net premium base</span>
                                <span className="font-bold">{formatMoney(netPremium, currency)}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Commission rate</span>
                                <span className="font-bold">{commissionPercent.toFixed(2)}%</span>
                            </div>
                            <div className="flex items-center justify-between border-t pt-3">
                                <span className="text-muted-foreground">Commission from net premium</span>
                                <span className="font-black">{formatMoney(commissionAmount, currency)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-2 shadow-sm">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ReceiptText className="h-5 w-5 text-primary" /> Policy & Order
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Order number</span>
                                <span className="font-bold">{order?.number ?? '-'}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Policy reference</span>
                                <span className="font-bold">{policyNumber}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Product</span>
                                <span className="font-bold">Compulsory Insurance</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Commission basis</span>
                                <span className="inline-flex items-center gap-1 font-bold">
                                    <Percent className="h-4 w-4" /> NetPremium
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-3 border-t pt-6">
                    <Button asChild variant="outline">
                        <Link href={route('orders.index')}>Back to Orders</Link>
                    </Button>
                    <Button asChild>
                        <Link href={route('orders.show', order?.id)}>
                            Go to Order Page <ArrowRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                </div>
            </div>
        </TenantNavbarLayout>
    );
}
