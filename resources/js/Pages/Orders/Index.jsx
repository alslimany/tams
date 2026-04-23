import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';

export default function Index({ orders }) {
    const rows = orders?.data ?? [];

    return (
        <TenantLayout>
            <Head title="Orders" />

            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between">
                            <span>Orders</span>
                            <Badge variant="outline">{orders?.total ?? 0}</Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
                                No orders found yet.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {rows.map((order) => {
                                    const firstItem = order.items?.[0] ?? null;

                                    return (
                                        <div key={order.id} className="rounded-lg border p-4">
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-lg font-semibold">{order.number}</p>
                                                    <p className="text-sm text-muted-foreground">
                                                        PNR: {firstItem?.provider_reference ?? '-'}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <Badge>{order.status}</Badge>
                                                    <p className="mt-1 text-sm font-medium">
                                                        {order.grand_total} {order.currency}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="mt-3 grid gap-2 text-sm text-muted-foreground sm:grid-cols-3">
                                                <p>Items: {order.items_count}</p>
                                                <p>Method: {order.payment_method}</p>
                                                <p>Issued: {order.issued_at ?? '-'}</p>
                                            </div>

                                            <div className="mt-4">
                                                <Button asChild size="sm">
                                                    <Link href={route('orders.show', { order: order.id })}>Show Order</Link>
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </TenantLayout>
    );
}
