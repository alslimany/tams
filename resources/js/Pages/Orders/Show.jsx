import React from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/Dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { formatMoney } from '@/lib/currency';
import {
    CalendarDays,
    Clock3,
    CreditCard,
    Mail,
    MapPin,
    Phone,
    Plane,
    ReceiptText,
    Ticket,
    UserRound,
    MoreHorizontal,
} from 'lucide-react';

export default function Show({ order, itemTransactions, voidRefundAccount }) {
    const { auth } = usePage().props;
    const { post, processing } = useForm({});
    const canManageItems = ['admin', 'manager'].includes(String(auth?.user?.role ?? ''));
    const [voidTarget, setVoidTarget] = React.useState(null);

    const formatDateTime = (value) => {
        if (!value) {
            return '-';
        }

        return new Date(value).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const formatDate = (value) => {
        if (!value) {
            return '-';
        }

        return new Date(value).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const formatAmount = (amount, currency) => formatMoney(amount, currency);

    const orderStatusVariant = (status) => {
        if (status === 'paid' || status === 'issued') {
            return 'success';
        }

        if (status === 'voided' || status === 'refunded') {
            return 'destructive';
        }

        if (status === 'confirmed') {
            return 'default';
        }

        return 'secondary';
    };

    const itemStatusVariant = (status) => {
        if (status === 'issued' || status === 'paid') {
            return 'success';
        }

        if (status === 'voided' || status === 'refunded') {
            return 'destructive';
        }

        return 'outline';
    };

    const flightNumberMatches = (ticketFlightNumber, itinerary) => {
        if (!ticketFlightNumber) {
            return false;
        }

        const compactTicketFlight = String(ticketFlightNumber).replace(/\s+/g, '').toUpperCase();
        const compactItineraryFlight = `${itinerary.airline_id ?? ''}${itinerary.flight_number ?? ''}`.replace(/\s+/g, '').toUpperCase();

        return compactTicketFlight === compactItineraryFlight;
    };

    const getTicketsForItinerary = (pnr, itinerary) => {
        const tickets = pnr?.tickets ?? [];

        return tickets.filter((ticket) => {
            if (String(ticket.segment_number ?? '') === String(itinerary.itinerary_id ?? '')) {
                return true;
            }

            return flightNumberMatches(ticket.flight_number, itinerary)
                && String(ticket.from ?? '') === String(itinerary.from ?? '')
                && String(ticket.to ?? '') === String(itinerary.to ?? '');
        });
    };

    const getItineraryPrice = (pnr, itinerary, fallbackTotal) => {
        const fareQuote = (pnr?.fare_qoute ?? []).find((entry) => String(entry.segment_id ?? '') === String(itinerary.itinerary_id ?? ''));

        if (fareQuote?.total != null) {
            return Number(fareQuote.total);
        }

        const fareStoreTotal = (pnr?.fare_store ?? []).reduce((total, store) => {
            const matchingSegment = (store.segments ?? []).find((segment) => String(segment.segment_id ?? '') === String(itinerary.itinerary_id ?? ''));

            if (!matchingSegment) {
                return total;
            }

            return total + Number(matchingSegment.fare ?? 0) + Number(matchingSegment.tax1 ?? 0) + Number(matchingSegment.tax2 ?? 0) + Number(matchingSegment.tax3 ?? 0);
        }, 0);

        if (fareStoreTotal > 0) {
            return fareStoreTotal;
        }

        return Number(fallbackTotal ?? 0);
    };

    const renderContactValue = (contact) => {
        if (contact.type === 'E') {
            return (
                <span className="flex items-center gap-2">
                    <Mail className="h-3.5 w-3.5 text-primary" />
                    {contact.value}
                </span>
            );
        }

        return (
            <span className="flex items-center gap-2">
                <Phone className="h-3.5 w-3.5 text-primary" />
                {contact.value}
            </span>
        );
    };

    const openVoidModal = (item, amount, currency) => {
        setVoidTarget({
            id: item.id,
            pnr: item.provider_reference ?? item.item_details?.rloc ?? '-',
            amount,
            currency,
        });
    };

    const closeVoidModal = () => {
        if (processing) {
            return;
        }

        setVoidTarget(null);
    };

    const confirmVoid = () => {
        if (!voidTarget) {
            return;
        }

        post(route('tickets.void', { booking: order.id, ticket: voidTarget.id }), {
            preserveScroll: true,
            onSuccess: () => setVoidTarget(null),
        });
    };

    const depositAccountName = voidRefundAccount?.name ?? auth?.user?.name ?? 'Agency Account';
    const depositAccountEmail = voidRefundAccount?.email ?? auth?.user?.email ?? '-';

    return (
        <TenantLayout>
            <Head title={`Order ${order.number}`} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <Card className="overflow-hidden border-0 shadow-xl shadow-slate-200/60 ring-1 ring-slate-200/70">
                    <CardContent className="space-y-6 bg-[linear-gradient(180deg,rgba(248,250,252,0.95),rgba(255,255,255,1))] p-0">
                        <div className="flex flex-col gap-4 border-b border-slate-200/80 px-6 py-6 md:flex-row md:items-start md:justify-between">
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-3">
                                    <p className="text-3xl font-black tracking-tight text-slate-950">Order {order.number}</p>
                                    <Badge variant={orderStatusVariant(order.status)} className="px-3 py-1 font-black uppercase tracking-wider">
                                        {order.status}
                                    </Badge>
                                </div>
                                <div className="grid gap-2 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-4">
                                    <p><span className="font-semibold text-slate-900">PNR:</span> {order.items?.[0]?.provider_reference ?? '-'}</p>
                                    <p><span className="font-semibold text-slate-900">Issued:</span> {formatDateTime(order.issued_at)}</p>
                                    <p><span className="font-semibold text-slate-900">Payment:</span> {order.payment_method}</p>
                                    <p><span className="font-semibold text-slate-900">Items:</span> {order.items?.length ?? 0}</p>
                                </div>
                            </div>

                            <div className="flex flex-col items-start gap-3 md:items-end">
                                <div className="text-left md:text-right">
                                    <p className="text-xs font-bold uppercase tracking-[0.25em] text-slate-500">Total Paid</p>
                                    <p className="text-3xl font-black text-slate-950">{formatAmount(order.amount_paid || order.grand_total, order.currency)}</p>
                                </div>

                                <Button asChild variant="outline" className="border-slate-300 bg-white/80 font-bold text-slate-700 hover:bg-slate-50">
                                    <Link href={route('orders.index')}>
                                        <ReceiptText className="mr-2 h-4 w-4" /> Back to Orders
                                    </Link>
                                </Button>
                            </div>
                        </div>

                        <div className="grid gap-4 px-6 pb-6 text-sm sm:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-2xl border border-slate-200/80 bg-white/90 p-4">
                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Order Owner</p>
                                <p className="mt-2 font-semibold text-slate-900">{order.owner?.name ?? order.contact?.first_name ?? '-'}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200/80 bg-white/90 p-4">
                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Contact Email</p>
                                <p className="mt-2 font-semibold text-slate-900">{order.contact?.email ?? '-'}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200/80 bg-white/90 p-4">
                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Grand Total</p>
                                <p className="mt-2 font-semibold text-slate-900">{formatAmount(order.grand_total, order.currency)}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200/80 bg-white/90 p-4">
                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Refunded</p>
                                <p className="mt-2 font-semibold text-slate-900">{formatAmount(order.amount_refunded, order.currency)}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card className="overflow-hidden border-0 shadow-xl shadow-slate-200/60 ring-1 ring-slate-200/70">
                    <CardContent className="space-y-6 bg-[linear-gradient(180deg,rgba(248,250,252,0.95),rgba(255,255,255,1))] p-0">
                        {order.items?.map((item) => {
                            const tx = itemTransactions?.find((entry) => entry.order_item_id === item.id);
                            const pnr = item.item_details ?? {};
                            const itineraries = pnr.itineraries ?? [];
                            const passengers = pnr.passengers ?? [];
                            const contacts = pnr.contacts ?? [];
                            const payments = pnr.payments ?? [];
                            const displayedPrice = Number(pnr.total_price ?? item.total ?? 0);
                            const displayCurrency = pnr.currency ?? item.currency;

                            return (
                                <div key={item.id}>
                                    <div className="flex flex-col gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-5 md:flex-row md:items-start md:justify-between">
                                        <div className="space-y-2">
                                            <div className="flex flex-wrap items-center gap-3">
                                                <p className="text-2xl font-black tracking-tight text-slate-950">PNR: {item.provider_reference ?? pnr.rloc ?? '-'}</p>
                                                <Badge variant={itemStatusVariant(item.status)} className="px-3 py-1 font-black uppercase tracking-wider">
                                                    {item.status}
                                                </Badge>
                                                {pnr.is_voidable ? (
                                                    <Badge variant="success" className="bg-emerald-500/90 px-3 py-1 font-bold uppercase tracking-wider">
                                                        Refundable
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <p className="text-sm text-slate-500">
                                                {pnr.iata ? `${pnr.iata} booking` : 'Airline booking'} • Item #{item.id}
                                            </p>
                                        </div>

                                        <div className="flex items-start gap-3 md:items-end">
                                            <div className="text-left md:text-right">
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Item Total</p>
                                                <p className="text-2xl font-black text-slate-950">{formatAmount(displayedPrice, displayCurrency)}</p>
                                            </div>

                                            {canManageItems ? (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="outline" size="sm" className="border-slate-300 bg-white/90 font-semibold text-slate-700 hover:bg-slate-100">
                                                            Manage
                                                            <MoreHorizontal className="ml-1.5 h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-44">
                                                        <DropdownMenuLabel>Manage Ticket</DropdownMenuLabel>
                                                        <DropdownMenuSeparator />
                                                        {item.status === 'voided' ? (
                                                            <DropdownMenuItem disabled>
                                                                Void Tickets
                                                            </DropdownMenuItem>
                                                        ) : (
                                                            <DropdownMenuItem
                                                                onSelect={(event) => {
                                                                    event.preventDefault();
                                                                    openVoidModal(item, displayedPrice, displayCurrency);
                                                                }}
                                                            >
                                                                Void Tickets
                                                            </DropdownMenuItem>
                                                        )}
                                                        <DropdownMenuItem disabled>
                                                            Refund Tickets
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem disabled>
                                                            Change
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            ) : null}
                                        </div>
                                    </div>

                                    <div className="space-y-5 p-5">
                                        {itineraries.map((itinerary) => {
                                            const itineraryTickets = getTicketsForItinerary(pnr, itinerary);
                                            const itineraryPrice = getItineraryPrice(pnr, itinerary, item.total);

                                            return (
                                                <div key={`${item.id}-${itinerary.itinerary_id}`} className="rounded-3xl border border-slate-200/90 bg-white shadow-sm">
                                                    <div className="flex flex-col gap-4 border-b border-slate-200/90 px-5 py-5 lg:flex-row lg:items-start lg:justify-between">
                                                        <div className="space-y-3">
                                                            <div className="flex flex-wrap items-center gap-3">
                                                                <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 shadow-inner">
                                                                    <Plane className="h-5 w-5" />
                                                                </div>
                                                                <div>
                                                                    <p className="text-lg font-black text-slate-950">
                                                                        {itinerary.from} → {itinerary.to} | {itinerary.airline_id} {itinerary.flight_number}
                                                                    </p>
                                                                    <p className="text-sm text-slate-500">
                                                                        {itinerary.class_band || itinerary.class_band_display_name || `Class ${itinerary.class}`}
                                                                    </p>
                                                                </div>
                                                            </div>

                                                            <div className="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                                                                {itineraryTickets.length > 0 ? itineraryTickets.map((ticket) => (
                                                                    <span key={ticket.ticket_number} className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1">
                                                                        {ticket.ticket_number} • {ticket.status}
                                                                    </span>
                                                                )) : (
                                                                    <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1">
                                                                        No ticket attached yet
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>

                                                        <div className="min-w-45 rounded-2xl bg-slate-50 px-4 py-3 text-sm">
                                                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Price</p>
                                                            <p className="mt-2 text-2xl font-black text-slate-950">{formatAmount(itineraryPrice, displayCurrency)}</p>
                                                        </div>
                                                    </div>

                                                    <div className="grid gap-4 px-5 py-5 md:grid-cols-2 xl:grid-cols-4">
                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                            <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                                <MapPin className="h-3.5 w-3.5" /> From
                                                            </p>
                                                            <p className="text-lg font-black text-slate-950">{itinerary.from}</p>
                                                            <p className="mt-1 flex items-center gap-2 text-sm text-slate-600">
                                                                <CalendarDays className="h-4 w-4" /> {formatDate(itinerary.date)}
                                                            </p>
                                                            <p className="mt-1 flex items-center gap-2 text-sm text-slate-600">
                                                                <Clock3 className="h-4 w-4" /> {itinerary.departure}
                                                            </p>
                                                        </div>

                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                            <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                                <MapPin className="h-3.5 w-3.5" /> To
                                                            </p>
                                                            <p className="text-lg font-black text-slate-950">{itinerary.to}</p>
                                                            <p className="mt-1 flex items-center gap-2 text-sm text-slate-600">
                                                                <CalendarDays className="h-4 w-4" /> {formatDate(itinerary.date)}
                                                            </p>
                                                            <p className="mt-1 flex items-center gap-2 text-sm text-slate-600">
                                                                <Clock3 className="h-4 w-4" /> {itinerary.arrival}
                                                            </p>
                                                        </div>

                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                            <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                                <UserRound className="h-3.5 w-3.5" /> Passengers
                                                            </p>
                                                            <div className="space-y-2 text-sm text-slate-700">
                                                                {passengers.length > 0 ? passengers.map((passenger) => (
                                                                    <p key={`${item.id}-${passenger.id}`} className="font-medium">
                                                                        {passenger.first_name} {passenger.last_name}
                                                                    </p>
                                                                )) : <p className="text-slate-500">No passengers found</p>}
                                                            </div>
                                                        </div>

                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                            <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                                <Ticket className="h-3.5 w-3.5" /> Contacts
                                                            </p>
                                                            <div className="space-y-2 text-sm text-slate-700">
                                                                {contacts.length > 0 ? contacts.map((contact, index) => (
                                                                    <div key={`${item.id}-${itinerary.itinerary_id}-contact-${index}`} className="font-medium">
                                                                        {renderContactValue(contact)}
                                                                    </div>
                                                                )) : <p className="text-slate-500">No contacts found</p>}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}

                                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Payment Reference</p>
                                                <p className="mt-2 text-sm font-semibold text-slate-900">{payments[0]?.reference ?? '-'}</p>
                                            </div>
                                            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Wallet Transaction</p>
                                                <p className="mt-2 break-all font-mono text-xs text-slate-700">{tx?.wallet_transaction?.uuid ?? '-'}</p>
                                            </div>
                                            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Airline Transaction</p>
                                                <p className="mt-2 text-sm font-semibold text-slate-900">
                                                    {tx?.airline_transaction
                                                        ? `${tx.airline_transaction.type} (${tx.airline_transaction.amount})`
                                                        : '-'}
                                                </p>
                                            </div>
                                            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Synced At</p>
                                                <p className="mt-2 text-sm font-semibold text-slate-900">{formatDateTime(pnr.pnr_synced_at)}</p>
                                            </div>
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

                <Dialog open={Boolean(voidTarget)} onOpenChange={(open) => (open ? null : closeVoidModal())}>
                    <DialogContent className="sm:max-w-130">
                        <DialogHeader>
                            <DialogTitle>Confirm Ticket Void</DialogTitle>
                            <DialogDescription>
                                The ticket for PNR {voidTarget?.pnr ?? '-'} will be cancelled.
                                This operation cannot be changed after cancelling the PNR.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                Please confirm carefully before continuing.
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Amount To Be Deposited</p>
                                    <p className="mt-2 text-lg font-black text-slate-950">
                                        {voidTarget ? formatAmount(voidTarget.amount, voidTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Deposit Account</p>
                                    <p className="mt-2 font-semibold text-slate-900">{depositAccountName}</p>
                                    <p className="text-xs text-slate-600">{depositAccountEmail}</p>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeVoidModal} disabled={processing}>
                                Keep Ticket
                            </Button>
                            <Button type="button" variant="destructive" onClick={confirmVoid} disabled={processing}>
                                {processing ? 'Voiding...' : 'Confirm Void'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </TenantLayout>
    );
}
