import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { CheckCircle2, Printer, ReceiptText, Plane, CalendarDays, Users } from 'lucide-react';
import { formatMoney } from '@/lib/currency';

export default function Completed({ booking, order }) {
    const printSummary = () => {
        window.print();
    };

    const amount = order?.grand_total ?? booking?.total_price ?? 0;
    const currency = order?.currency ?? booking?.currency ?? 'USD';
    const segments = booking?.flight_segments ?? [];
    const passengers = booking?.passengers ?? [];

    const formatDate = (value) => {
        if (!value) {
            return '-';
        }

        return new Date(value).toLocaleString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    return (
        <TenantLayout>
            <Head title={`Booking ${booking.pnr} Completed`} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <Card className="border-2 border-emerald-200 bg-emerald-50/60">
                    <CardContent className="flex flex-col gap-4 py-8 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-start gap-3">
                            <CheckCircle2 className="mt-0.5 h-7 w-7 text-emerald-600" />
                            <div>
                                <p className="text-xs font-black uppercase tracking-widest text-emerald-700">Ticket Issued</p>
                                <h1 className="text-3xl font-black text-emerald-800">Reservation Completed</h1>
                                <p className="mt-1 text-sm text-emerald-700">
                                    Your booking {booking.pnr} has been ticketed successfully.
                                </p>
                            </div>
                        </div>
                        <Badge className="w-fit bg-emerald-600 text-white">Completed</Badge>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Plane className="h-5 w-5 text-primary" /> Reservation Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <p className="text-muted-foreground">PNR</p>
                                <p className="font-bold">{booking.pnr}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Provider</p>
                                <p className="font-bold">{booking.provider?.airline_name ?? booking.provider?.airline_code ?? '-'}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Customer</p>
                                <p className="font-bold">{booking.customer?.first_name} {booking.customer?.last_name}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Total Paid</p>
                                <p className="font-black text-primary">{formatMoney(amount, currency)}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Tickets</p>
                                <p className="font-bold">{booking.tickets?.length ?? 0}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Order</p>
                                <p className="font-bold">{order?.number ?? 'Created'}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Next Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Button type="button" className="w-full" onClick={printSummary}>
                                <Printer className="mr-2 h-4 w-4" /> Print Summary
                            </Button>

                            <Button asChild variant="outline" className="w-full">
                                <Link href={route('orders.index')}>
                                    <ReceiptText className="mr-2 h-4 w-4" /> Go to Orders
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <CalendarDays className="h-5 w-5 text-primary" /> Itinerary & Date
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {segments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No itinerary details available.</p>
                        ) : (
                            segments.map((segment) => (
                                <div key={segment.id} className="rounded-lg border p-4">
                                    <p className="font-semibold">{segment.origin_airport} → {segment.destination_airport}</p>
                                    <p className="text-sm text-muted-foreground">Flight {segment.flight_number || '-'}</p>
                                    <p className="mt-1 text-sm">Departure: {formatDate(segment.departure_time)}</p>
                                    <p className="text-sm">Arrival: {formatDate(segment.arrival_time)}</p>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="h-5 w-5 text-primary" /> Passengers
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {passengers.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No passenger details available.</p>
                        ) : (
                            passengers.map((passenger) => (
                                <div key={passenger.id} className="flex items-center justify-between rounded-lg border p-3">
                                    <p className="font-medium">{passenger.first_name} {passenger.last_name}</p>
                                    <Badge variant="outline" className="capitalize">{passenger.type}</Badge>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>
        </TenantLayout>
    );
}
