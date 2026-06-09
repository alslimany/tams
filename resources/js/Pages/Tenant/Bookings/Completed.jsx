import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { CheckCircle2, FileText, ExternalLink, LayoutList } from 'lucide-react';
import { formatMoney } from '@/lib/currency';

export default function Completed({ booking, order }) {
    const isDraft = order?.status === 'pending';
    const amount = order?.amount_paid ?? 0;
    const currency = order?.currency ?? 'USD';
    const passengers = booking?.passengers ?? [];
    const customer = booking?.customer ?? {};

    const formatDate = (value) => {
        if (!value) return '-';
        return new Date(value).toLocaleDateString(undefined, {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <TenantNavbarLayout>
            <Head title={`Order ${order?.number ?? ''} — ${isDraft ? 'Draft Saved' : 'Confirmed'}`} />

            <div className="mx-auto max-w-lg px-4 py-16 space-y-8">

                {/* Hero */}
                <div className="flex flex-col items-center text-center space-y-3">
                    <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                        {isDraft
                            ? <FileText className="h-8 w-8 text-primary" />
                            : <CheckCircle2 className="h-8 w-8 text-primary" />
                        }
                    </div>
                    <h1 className="text-2xl font-black">
                        {isDraft ? 'Draft Saved' : 'Order Confirmed'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {isDraft
                            ? 'PNR reserved successfully. No payment has been taken. Open the order to issue the ticket when ready.'
                            : 'Booking confirmed and ticket issued successfully.'
                        }
                    </p>
                </div>

                {/* Details */}
                <Card>
                    <CardContent className="divide-y p-0 text-sm">
                        <Row label="Order Number">
                            <span className="font-black text-primary">{order?.number ?? '—'}</span>
                        </Row>
                        <Row label="Date">
                            <span className="font-semibold">{formatDate(order?.created_at)}</span>
                        </Row>
                        <Row label="Amount Paid">
                            {isDraft
                                ? <Badge variant="outline">Pending payment</Badge>
                                : <span className="font-black text-primary">{formatMoney(amount, currency)}</span>
                            }
                        </Row>
                        <Row label="Customer">
                            <span className="font-semibold">
                                {[customer.first_name, customer.last_name].filter(Boolean).join(' ') || '—'}
                            </span>
                        </Row>
                        <Row label="Passengers">
                            <span className="font-semibold">{passengers.length}</span>
                        </Row>
                        <Row label="PNR Reference">
                            <span className="font-mono font-black">{booking?.pnr ?? '—'}</span>
                        </Row>
                        <Row label="Status">
                            <Badge variant={isDraft ? 'outline' : 'default'}>
                                {isDraft ? 'Draft' : 'Confirmed'}
                            </Badge>
                        </Row>
                    </CardContent>
                </Card>

                {/* Actions */}
                <div className="flex flex-col gap-3">
                    <Button asChild size="lg" className="w-full">
                        <Link href={route('flights.show', { booking: order?.id })}>
                            <ExternalLink className="mr-2 h-4 w-4" />
                            View Order
                        </Link>
                    </Button>
                    <Button asChild variant="outline" className="w-full">
                        <Link href={route('orders.index')}>
                            <LayoutList className="mr-2 h-4 w-4" />
                            All Orders
                        </Link>
                    </Button>
                </div>

            </div>
        </TenantNavbarLayout>
    );
}

function Row({ label, children }) {
    return (
        <div className="flex items-center justify-between gap-4 px-5 py-3.5">
            <span className="text-muted-foreground">{label}</span>
            <span>{children}</span>
        </div>
    );
}
