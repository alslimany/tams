import React from 'react';
import { Head } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';

export default function Show({ order, itemTransactions }) {
    const formatAmount = (amount, currency) => `${amount} ${currency}`;

    return (
        <TenantLayout>
            <Head title={`Order ${order.number}`} />

            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between gap-3">
                            <span>Order {order.number}</span>
                            <Badge>{order.status}</Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <p className="text-muted-foreground">PNR Reference</p>
                            <p className="font-semibold">{order.items?.[0]?.provider_reference ?? '-'}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">Issued At</p>
                            <p className="font-semibold">{order.issued_at ?? '-'}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">Payment Method</p>
                            <p className="font-semibold">{order.payment_method}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">Grand Total</p>
                            <p className="font-semibold">{formatAmount(order.grand_total, order.currency)}</p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Order Items</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {order.items?.map((item) => {
                            const tx = itemTransactions?.find((entry) => entry.order_item_id === item.id);

                            return (
                                <div key={item.id} className="rounded-lg border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <p className="font-semibold">{item.item_details?.passenger_name ?? 'Passenger'}</p>
                                        <Badge variant="outline">{item.status}</Badge>
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Ticket: {item.ticket_number ?? '-'}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Provider Ref: {item.provider_reference ?? '-'}
                                    </p>
                                    <p className="mt-2 font-medium">
                                        Total: {formatAmount(item.total, item.currency)}
                                    </p>

                                    <div className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                        <div>
                                            <p className="text-muted-foreground">Wallet Transaction</p>
                                            <p className="font-mono text-xs break-all">
                                                {tx?.wallet_transaction?.uuid ?? '-'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Airline Transaction</p>
                                            <p className="font-semibold">
                                                {tx?.airline_transaction
                                                    ? `${tx.airline_transaction.type} (${tx.airline_transaction.amount})`
                                                    : '-'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Status Log</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {order.status_logs?.map((log) => (
                            <div key={log.id} className="rounded-md border p-3 text-sm">
                                <p className="font-medium">
                                    {log.old_status ?? 'null'} → {log.new_status}
                                </p>
                                <p className="text-muted-foreground">{log.comment ?? '-'}</p>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </TenantLayout>
    );
}
