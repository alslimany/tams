import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import {
    ArrowRight,
    ArrowRightLeft,
    CalendarDays,
    Hotel,
    Plane,
    PlaneTakeoff,
    ShieldCheck,
    Smartphone,
} from 'lucide-react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';

export default function Index({ orders }) {
    const rows = orders?.data ?? [];

    const formatDateTime = (value) => {
        if (! value) {
            return '-';
        }

        return new Date(value).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const orderStatusVariant = (status) => {
        const normalized = String(status ?? '').toLowerCase();

        if (['issued', 'paid', 'confirmed'].includes(normalized)) {
            return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        }

        if (normalized === 'voided') {
            return 'bg-amber-50 text-amber-700 border-amber-200';
        }

        if (normalized === 'refunded') {
            return 'bg-rose-50 text-rose-700 border-rose-200';
        }

        return 'bg-slate-100 text-slate-700 border-slate-200';
    };

    const itemStatusVariant = (status) => {
        return orderStatusVariant(status);
    };

    const itemTypeLabel = (item) => {
        const type = String(item?.type ?? '').toLowerCase();
        const subtype = String(item?.product_subtype ?? '').toLowerCase();

        if (type.includes('hotel') || subtype.includes('hotel')) {
            return 'Hotel';
        }

        if (type.includes('insurance') || subtype.includes('insurance')) {
            return 'Insurance';
        }

        if (type.includes('esim') || subtype.includes('esim')) {
            return 'eSIM';
        }

        return 'PNR';
    };

    const itemTypeIcon = (item) => {
        const label = itemTypeLabel(item);

        return {
            PNR: <Plane className="h-3.5 w-3.5" />,
            Hotel: <Hotel className="h-3.5 w-3.5" />,
            Insurance: <ShieldCheck className="h-3.5 w-3.5" />,
            eSIM: <Smartphone className="h-3.5 w-3.5" />,
        }[label] ?? <CalendarDays className="h-3.5 w-3.5" />;
    };

    const resolveContactName = (order) => {
        const contactFirstName = String(order?.contact?.first_name ?? '').trim();
        const contactLastName = String(order?.contact?.last_name ?? '').trim();
        const contactName = `${contactFirstName} ${contactLastName}`.trim();

        if (contactName !== '') {
            return contactName;
        }

        return 'N/A';
    };

    const renderItemDetails = (item) => {
        const details = item?.item_details ?? {};
        const type = itemTypeLabel(item);

        if (type === 'PNR') {
            const airlineCode = String(details?.iata ?? details?.airline_code ?? '').toUpperCase();
            const itineraries = details?.itineraries ?? details?.segments ?? [];
            const itinerary = itineraries?.[0] ?? null;
            const origin = itinerary?.from ?? itinerary?.departure_airport ?? itinerary?.origin ?? '-';
            const destination = itinerary?.to ?? itinerary?.arrival_airport ?? itinerary?.destination ?? '-';
            const travelDate = itinerary?.date ?? itinerary?.departure_time ?? '-';
            const isReturnTrip = itineraries.length > 1 || String(item?.product_subtype ?? '').toLowerCase().includes('return');
            const logoSrc = airlineCode !== ''
                ? route('api.airlines.logo', { code: airlineCode })
                : null;

            return (
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-600">
                        <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                            {itemTypeIcon(item)}
                        </span>
                        {type} {item.provider_reference ? `• ${item.provider_reference}` : ''}
                    </div>
                    <div className="flex items-center gap-2 text-sm text-slate-800">
                        {logoSrc ? (
                            <img
                                src={logoSrc}
                                alt={airlineCode}
                                className="h-4 w-4 rounded-full border border-slate-200 object-contain"
                            />
                        ) : null}
                        <span className="inline-flex items-center gap-1 font-medium">
                            <span>{origin}</span>
                            <ArrowRight className="h-3 w-3 text-slate-400" />
                            <span>{destination}</span>
                        </span>
                        <span className="text-slate-500">| {formatDateTime(travelDate)}</span>
                    </div>
                </div>
            );
        }

        if (type === 'Hotel') {
            const hotelName = details?.hotel_name ?? details?.name ?? details?.hotel?.name ?? 'Hotel';
            const checkIn = details?.check_in ?? details?.checkin_date ?? '-';
            const checkOut = details?.check_out ?? details?.checkout_date ?? '-';

            return (
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                            {itemTypeIcon(item)}
                        </span>
                        {type}
                    </div>
                    <p className="text-sm font-medium text-slate-800">{hotelName}</p>
                    <p className="text-sm text-slate-600">{formatDateTime(checkIn)} - {formatDateTime(checkOut)}</p>
                </div>
            );
        }

        return (
            <div className="space-y-1.5">
                <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                    <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                        {itemTypeIcon(item)}
                    </span>
                    {type}
                </div>
                <p className="text-sm text-slate-700">
                    {item.provider_reference || item.ticket_number || 'Details available in order view'}
                </p>
            </div>
        );
    };

    return (
        <TenantNavbarLayout>
            <Head title="Orders" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <Card className="overflow-hidden border border-slate-200/80 bg-white shadow-sm">
                    <CardHeader className="border-b border-slate-200/70 bg-slate-50/60">
                        <CardTitle className="flex items-center justify-between text-slate-900">
                            <span className="text-xl font-semibold tracking-tight">Orders</span>
                            <Badge variant="outline" className="border-slate-300 bg-white text-slate-700">{orders?.total ?? 0}</Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
                                No orders found yet.
                            </div>
                        ) : (
                            <>
                                <div className="space-y-3 md:hidden">
                                    {rows.map((order) => (
                                        <div key={order.id} className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                                            <div className="mb-2 flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-base font-semibold text-slate-900">{order.number}</p>
                                                    <p className="text-xs text-slate-500">{resolveContactName(order)}</p>
                                                </div>
                                              
                                            </div>

                                            <div className="space-y-1.5">
                                                {(order.items ?? []).map((item) => (
                                                    <div key={item.id} className="rounded-lg border border-slate-200/80 bg-slate-50/60 p-2.5">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="min-w-0 flex-1">
                                                                {renderItemDetails(item)}
                                                            </div>
                                                            <Badge variant="outline" className={`shrink-0 ${itemStatusVariant(item.status)}`}>
                                                                {item.status}
                                                            </Badge>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>

                                            <div className="mt-2 flex items-center justify-between text-xs text-slate-500">
                                                <span>By {order.owner?.name ?? '-'}</span>
                                                <span>{formatDateTime(order.created_at)}</span>
                                            </div>

                                            <div className="mt-2">
                                                <Button asChild size="sm" variant="outline" className="w-full border-slate-300 bg-white text-slate-700">
                                                    <Link href={route('orders.show', { order: order.id })}>Show Order</Link>
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="hidden md:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="h-9 w-37.5 px-3 text-[10px] uppercase tracking-[0.12em]">Order #</TableHead>
                                                <TableHead className="h-9 w-50 px-3 text-[10px] uppercase tracking-[0.12em]">Contact</TableHead>
                                                <TableHead className="h-9 px-3 text-[10px] uppercase tracking-[0.12em]">Order Items</TableHead>
                                                <TableHead className="h-9 w-32.5 px-3 text-[10px] uppercase tracking-[0.12em]">Order Status</TableHead>
                                                <TableHead className="h-9 w-37.5 px-3 text-[10px] uppercase tracking-[0.12em]">Owner</TableHead>
                                                <TableHead className="h-9 w-47.5 px-3 text-[10px] uppercase tracking-[0.12em]">Created At</TableHead>
                                                <TableHead className="h-9 w-27.5 px-3 text-right text-[10px] uppercase tracking-[0.12em]">Action</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {rows.map((order) => (
                                                <TableRow key={order.id} className="cursor-default hover:bg-slate-50/80">
                                                    <TableCell className="px-3 py-2.5">
                                                        <p className="font-semibold text-slate-900">{order.number}</p>
                                                        <p className="text-xs text-slate-500">{order.items_count} item(s)</p>
                                                    </TableCell>
                                                    <TableCell className="px-3 py-2.5">
                                                        <p className="font-medium text-slate-900">{resolveContactName(order)}</p>
                                                        <p className="text-xs text-slate-500">{order.contact?.email ?? '-'}</p>
                                                    </TableCell>
                                                    <TableCell className="px-3 py-2.5">
                                                        <div className="space-y-1.5">
                                                            {(order.items ?? []).map((item) => (
                                                                <div key={item.id} className="rounded-md border border-slate-200/80 bg-slate-50/50 p-2">
                                                                    <div className="flex items-start justify-between gap-2">
                                                                        <div className="min-w-0 flex-1">
                                                                            {renderItemDetails(item)}
                                                                        </div>
                                                                        <Badge variant="outline" className={`shrink-0 ${itemStatusVariant(item.status)}`}>
                                                                            {item.status}
                                                                        </Badge>
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="px-3 py-2.5">
                                                        <Badge variant="outline" className={orderStatusVariant(order.status)}>
                                                            {order.status}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="px-3 py-2.5 text-slate-700">{order.owner?.name ?? '-'}</TableCell>
                                                    <TableCell className="px-3 py-2.5 text-slate-700">{formatDateTime(order.created_at)}</TableCell>
                                                    <TableCell className="px-3 py-2.5 text-right">
                                                        <Button asChild size="sm" variant="outline" className="border-slate-300 bg-white text-slate-700 hover:bg-slate-50">
                                                            <Link href={route('orders.show', { order: order.id })}>Show</Link>
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </TenantNavbarLayout>
    );
}
