import React from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
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
    AlertTriangle,
    CalendarDays,
    Clock3,
    CreditCard,
    Mail,
    MapPin,
    HotelIcon,
    RefreshCcw,
    Phone,
    Plane,
    ReceiptText,
    ShieldCheck,
    Ticket,
    UserRound,
    MoreHorizontal,
} from 'lucide-react';

export default function Show({ order, itemTransactions, voidRefundAccount }) {
    const { auth } = usePage().props;
    const voidForm = useForm({});
    const cancelForm = useForm({ remarks: '' });
    const finalizeCancellationForm = useForm({});
    const currentUserRole = String(auth?.user?.role ?? auth?.landlordUser?.role ?? '').trim().toLowerCase();
    const canManageItems = ['admin', 'manager'].includes(currentUserRole);
    const canManageInsurance = ['admin', 'manager', 'agent'].includes(currentUserRole);
    const canManageHotels = ['admin', 'manager', 'agent'].includes(currentUserRole);
    const [voidTarget, setVoidTarget] = React.useState(null);
    const [cancelTarget, setCancelTarget] = React.useState(null);
    const [finalizeTarget, setFinalizeTarget] = React.useState(null);

    const primaryItem = order.items?.[0] ?? null;
    const primaryReferenceLabel = primaryItem?.type === 'insurance' ? 'Policy Reference' : 'PNR';
    const primaryReferenceValue = primaryItem?.type === 'insurance'
        ? (primaryItem.provider_reference ?? primaryItem.ticket_number ?? '-')
        : (primaryItem?.provider_reference ?? '-');

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

    const stripHtml = (value) => String(value ?? '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/&agrave;/g, 'à')
        .replace(/&acirc;/g, 'â')
        .replace(/&eacute;/g, 'é')
        .replace(/&#39;/g, "'")
        .replace(/\s+/g, ' ')
        .trim();

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

    const insuranceProductLabel = (subtype) => {
        const normalized = String(subtype ?? '').toLowerCase();

        if (normalized === 'travel') {
            return 'Travel Insurance';
        }

        if (normalized === 'orange') {
            return 'Orange Insurance';
        }

        return 'Compulsory Insurance';
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
        if (voidForm.processing) {
            return;
        }

        setVoidTarget(null);
    };

    const confirmVoid = () => {
        if (!voidTarget) {
            return;
        }

        voidForm.post(route('tickets.void', { booking: order.id, ticket: voidTarget.id }), {
            preserveScroll: true,
            onSuccess: () => setVoidTarget(null),
        });
    };

    const getInsurancePayload = (item) => item?.product_details?.policy_details ?? {};

    const getInsurancePassengerName = (item) => {
        const directName = String(item?.product_details?.passenger_name ?? item?.item_details?.passenger_name ?? '').trim();

        if (directName !== '') {
            return directName;
        }

        const passenger = item?.product_details?.passenger ?? item?.item_details?.insurance?.passenger ?? {};
        const fallbackName = `${String(passenger?.first_name ?? '').trim()} ${String(passenger?.last_name ?? '').trim()}`.trim();

        return fallbackName || '-';
    };

    const getTravelDurationLabel = (item, policyDetails) => {
        const start = String(policyDetails?.policy_date_from ?? policyDetails?.PolicyDateFrom ?? '').trim();
        const end = String(policyDetails?.policy_date_to ?? policyDetails?.PolicyDateTo ?? '').trim();

        if (start === '' || end === '') {
            return '-';
        }

        const startDate = new Date(start);
        const endDate = new Date(end);

        if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
            return '-';
        }

        const durationDays = Math.max(1, Math.round((endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24)));

        return `${durationDays} day${durationDays > 1 ? 's' : ''}`;
    };

    const getInsuranceBeneficiary = (item) => item?.item_details?.beneficiary ?? {};

    const getInsuranceCancellation = (item) => item?.item_details?.insurance?.cancellation ?? {};

    const getInsuranceRemark = (item) => String(getInsuranceCancellation(item)?.latest_remark ?? '');

    const isInsuranceCancellationApproved = (item) => getInsuranceRemark(item) === 'تم الالغاء';

    const openCancelModal = (item) => {
        setCancelTarget(item);
        cancelForm.setData('remarks', '');
        cancelForm.clearErrors();
    };

    const openPolicyReport = (item) => {
        const url = route('insurance.order-items.report', { order: order.id, item: item.id });

        window.open(url, '_blank', 'noopener,noreferrer');
    };

    const cancelHotelBooking = (item) => {
        if (!window.confirm('Cancel this hotel booking with the provider?')) {
            return;
        }

        router.post(route('hotels.order-items.cancel', { order: order.id, item: item.id }), {}, {
            preserveScroll: true,
        });
    };

    const closeCancelModal = () => {
        if (cancelForm.processing) {
            return;
        }

        setCancelTarget(null);
        cancelForm.reset('remarks');
    };

    const submitCancellation = () => {
        if (!cancelTarget) {
            return;
        }

        cancelForm.post(route('insurance.order-items.cancel', { order: order.id, item: cancelTarget.id }), {
            preserveScroll: true,
            onSuccess: () => {
                setCancelTarget(null);
                cancelForm.reset('remarks');
            },
        });
    };

    const closeFinalizeModal = () => {
        if (finalizeCancellationForm.processing) {
            return;
        }

        setFinalizeTarget(null);
    };

    const confirmCancellationFinalization = () => {
        if (!finalizeTarget) {
            return;
        }

        finalizeCancellationForm.post(route('insurance.order-items.finalize-cancellation', { order: order.id, item: finalizeTarget.id }), {
            preserveScroll: true,
            onSuccess: () => setFinalizeTarget(null),
        });
    };

    const refreshCancellationStatus = () => {
        router.reload({
            only: ['order', 'itemTransactions'],
            preserveScroll: true,
            preserveState: true,
        });
    };

    React.useEffect(() => {
        const pendingCancellationItems = (order.items ?? []).filter((item) => item.type === 'insurance' && item.status === 'cancellation');

        if (pendingCancellationItems.length === 0) {
            return undefined;
        }

        const timerId = window.setInterval(() => {
            router.reload({
                only: ['order', 'itemTransactions'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 30000);

        return () => window.clearInterval(timerId);
    }, [order.items]);

    React.useEffect(() => {
        const approvedItem = (order.items ?? []).find((item) => item.type === 'insurance' && item.status === 'cancellation' && isInsuranceCancellationApproved(item));

        if (!approvedItem) {
            setFinalizeTarget((current) => (current && current.status === 'cancellation' ? null : current));

            return;
        }

        setFinalizeTarget((current) => (current?.id === approvedItem.id ? current : approvedItem));
    }, [order.items]);

    const depositAccountName = voidRefundAccount?.name ?? auth?.user?.name ?? 'Agency Account';
    const depositAccountEmail = voidRefundAccount?.email ?? auth?.user?.email ?? '-';

    return (
        <TenantSidebarLayout>
            <Head title={`Order ${order.number}`} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <Card className="overflow-hidden border-0 shadow-xl shadow-slate-200/60 ring-1 ring-slate-200/70">
                    <CardContent className="space-y-6 bg-[linear-gradient(180deg,rgba(248,250,252,0.95),rgba(255,255,255,1))] p-0">
                        <div className="flex flex-col gap-4 border-b border-slate-200/80 px-6 py-6 md:flex-row md:items-start md:justify-between">
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-3">
                                    <p className="text-3xl font-black tracking-tight text-white-950">Order {order.number}</p>
                                    <Badge variant={orderStatusVariant(order.status)} className="px-3 py-1 font-black uppercase text-white tracking-wider">
                                        {order.status}
                                    </Badge>
                                </div>
                                <div className="grid gap-2 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-4">
                                    <p className='w-50 truncate'><span className="font-semibold text-slate-900 ">{primaryReferenceLabel}:</span> {primaryReferenceValue}</p>
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

                            if (item.type === 'insurance') {
                                const policyDetails = getInsurancePayload(item);
                                const beneficiary = getInsuranceBeneficiary(item);
                                const cancellation = getInsuranceCancellation(item);
                                const latestRemark = getInsuranceRemark(item);
                                const netWalletEffect = Number(cancellation?.financials?.net_wallet_effect ?? 0);
                                const passengerName = getInsurancePassengerName(item);
                                const travelZone = policyDetails.zone_text ?? policyDetails.zone ?? item?.product_details?.zone_text ?? item?.product_details?.zone_id ?? policyDetails.zone_id ?? '-';
                                const travelDuration = policyDetails.duration_text ?? item?.product_details?.duration_text ?? getTravelDurationLabel(item, policyDetails);
                                const policyNumber = policyDetails.policy_number ?? item.ticket_number ?? item.provider_reference ?? '-';

                                return (
                                    <div key={item.id}>
                                        <div className="flex flex-col gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-5 md:flex-row md:items-start md:justify-between">
                                            <div className="space-y-2">
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <p className="text-2xl font-black tracking-tight text-slate-950 w-120 truncate">Policy: {policyNumber}</p>
                                                    <Badge variant={itemStatusVariant(item.status)} className="px-3 py-1 font-black uppercase text-white tracking-wider">
                                                        {item.status}
                                                    </Badge>
                                                    {item.status === 'cancellation' ? (
                                                        <Badge variant={isInsuranceCancellationApproved(item) ? 'success' : 'secondary'}>
                                                            {isInsuranceCancellationApproved(item) ? 'Insurance Company Approved' : 'Waiting For Approval'}
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                                <p className="text-sm text-slate-500">{insuranceProductLabel(item.product_subtype)} • Item #{item.id}</p>
                                            </div>

                                            <div className="flex flex-col items-start gap-3 md:items-end">
                                                <div className="text-left md:text-right">
                                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Policy Total</p>
                                                    <p className="text-2xl font-black text-slate-950">{formatAmount(item.total_amount ?? item.total, item.currency)}</p>
                                                </div>

                                                {canManageInsurance ? (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="outline" size="sm" className="border-slate-300 bg-white/90 font-semibold text-slate-700 hover:bg-slate-100">
                                                                Manage
                                                                <MoreHorizontal className="ml-1.5 h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end" className="w-52">
                                                            <DropdownMenuLabel>Manage Policy</DropdownMenuLabel>
                                                            <DropdownMenuSeparator />
                                                            {item.status === 'issued' ? (
                                                                <DropdownMenuItem
                                                                    className="text-rose-600 focus:text-rose-700"
                                                                    onSelect={(event) => {
                                                                        event.preventDefault();
                                                                        openCancelModal(item);
                                                                    }}
                                                                >
                                                                    Request Cancellation
                                                                </DropdownMenuItem>
                                                            ) : (
                                                                <DropdownMenuItem disabled>
                                                                    Request Cancellation
                                                                </DropdownMenuItem>
                                                            )}
                                                            <DropdownMenuItem
                                                                onSelect={(event) => {
                                                                    event.preventDefault();
                                                                    openPolicyReport(item);
                                                                }}
                                                            >
                                                                Print Policy
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                ) : null}
                                            </div>
                                        </div>

                                        <div className="space-y-5 p-5">
                                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <ShieldCheck className="h-3.5 w-3.5" /> Beneficiary
                                                    </p>
                                                    <p className="text-lg font-black text-slate-950">{beneficiary.name ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">{beneficiary.phone ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Passenger: {passengerName}</p>
                                                    <p className="mt-1 text-sm text-slate-600">{beneficiary.address ?? '-'}</p>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <CalendarDays className="h-3.5 w-3.5" /> Coverage
                                                    </p>
                                                    <p className="text-lg font-black text-slate-950">{policyDetails.policy_date_from ?? policyDetails.PolicyDateFrom ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">to {policyDetails.policy_date_to ?? policyDetails.PolicyDateTo ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Zone: {travelZone}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Duration: {travelDuration}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Policy ID: {policyDetails.policy_id ?? cancellation.insurance_policy_id ?? '-'}</p>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <CreditCard className="h-3.5 w-3.5" /> Premiums
                                                    </p>
                                                    <p className="text-sm text-slate-600">Net premium</p>
                                                    <p className="text-lg font-black text-slate-950">{formatAmount(item.net_fare ?? item.price, item.currency)}</p>
                                                    <p className="mt-2 text-sm text-slate-600">Commission</p>
                                                    <p className="text-base font-bold text-slate-950">{formatAmount(item.commission_amount ?? 0, item.currency)}</p>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <ReceiptText className="h-3.5 w-3.5" /> Transactions
                                                    </p>
                                                    <p className="text-sm text-slate-600">Issue wallet transaction</p>
                                                    <p className="mt-1 break-all font-mono text-xs text-slate-700">{tx?.wallet_transaction?.uuid ?? item.wallet_transaction_id ?? '-'}</p>
                                                    {item.status === 'cancelled' ? (
                                                        <>
                                                            <p className="mt-3 text-sm text-slate-600">Net wallet effect</p>
                                                            <p className="text-base font-bold text-slate-950">{formatAmount(netWalletEffect, item.currency)}</p>
                                                        </>
                                                    ) : null}
                                                </div>
                                            </div>

                                            {item.status === 'cancellation' || item.status === 'cancelled' ? (
                                                <div className="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950">
                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                        <div>
                                                            <p className="font-black uppercase tracking-[0.2em] text-amber-700">Cancellation Status</p>
                                                            <p className="mt-2 font-semibold">Latest insurance company remark: {latestRemark || '-'}</p>
                                                            <p className="mt-1 text-amber-800">Customer note: {cancellation.note ?? '-'}</p>
                                                        </div>
                                                        {isInsuranceCancellationApproved(item) ? (
                                                            <Badge variant="success" className="bg-emerald-600 text-white">Ready To Confirm</Badge>
                                                        ) : (
                                                            <Badge variant="secondary">Pending Insurance Company</Badge>
                                                        )}
                                                    </div>
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                );
                            }

                            if (item.type === 'hotel' || item.product_type === 'hotel') {
                                const details = item.item_details ?? {};
                                const product = item.product_details ?? {};
                                const hotel = product.hotel ?? {};
                                const room = product.room ?? {};
                                const stay = product.stay ?? details.provider_booking ?? {};
                                const bookedRooms = product.rooms ?? details.provider_booking?.rooms ?? details.rooms ?? [];
                                const providerComments = stripHtml(product.comments ?? details.comments ?? '');
                                const customer = product.customer ?? details.customer ?? {};
                                const cancellation = details.cancellation ?? {};
                                const cancellationRequest = details.cancellation_request ?? {};

                                return (
                                    <div key={item.id}>
                                        <div className="flex flex-col gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-5 md:flex-row md:items-start md:justify-between">
                                            <div className="space-y-2">
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <p className="text-2xl font-black tracking-tight text-slate-950 w-120 truncate">Hotel Booking: {details.booking_id ?? item.provider_reference ?? '-'}</p>
                                                    <Badge variant={itemStatusVariant(item.status)} className="px-3 py-1 font-black uppercase text-white tracking-wider">
                                                        {item.status}
                                                    </Badge>
                                                </div>
                                                <p className="text-sm text-slate-500">Hotel • Item #{item.id}</p>
                                            </div>

                                            <div className="flex flex-col items-start gap-3 md:items-end">
                                                <div className="text-left md:text-right">
                                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Booking Total</p>
                                                    <p className="text-2xl font-black text-slate-950">{formatAmount(item.total_amount ?? item.total, item.currency)}</p>
                                                </div>

                                                {canManageHotels ? (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="outline" size="sm" className="border-slate-300 bg-white/90 font-semibold text-slate-700 hover:bg-slate-100">
                                                                Manage
                                                                <MoreHorizontal className="ml-1.5 h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end" className="w-52">
                                                            <DropdownMenuLabel>Manage Booking</DropdownMenuLabel>
                                                            <DropdownMenuSeparator />
                                                    {item.status !== 'cancelled' ? (
                                                                <DropdownMenuItem
                                                                    className="text-rose-600 focus:text-rose-700"
                                                                    onSelect={(event) => {
                                                                        event.preventDefault();
                                                                        cancelHotelBooking(item);
                                                                    }}
                                                                >
                                                                    {item.status === 'cancellation' ? 'Cancellation Requested' : 'Cancel Booking'}
                                                                </DropdownMenuItem>
                                                            ) : (
                                                                <DropdownMenuItem disabled>Cancel Booking</DropdownMenuItem>
                                                            )}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                ) : null}
                                            </div>
                                        </div>

                                        <div className="space-y-5 p-5">
                                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <HotelIcon className="h-3.5 w-3.5" /> Hotel
                                                    </p>
                                                    <p className="text-lg font-black text-slate-950">{hotel.name ?? room.hotel_name ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">{hotel.city_name ?? '-'}, {hotel.country_name ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Rating: {hotel.rating ?? hotel.rating_id ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Room: {room.room_name ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Board: {room.board_name ?? '-'}</p>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <CalendarDays className="h-3.5 w-3.5" /> Stay
                                                    </p>
                                                    <p className="text-lg font-black text-slate-950">{stay.from ?? details.search?.check_in ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">to {stay.to ?? details.search?.check_out ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Destination: {details.search?.city ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">Deadline: {stay.deadline || '-'}</p>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <UserRound className="h-3.5 w-3.5" /> Customer
                                                    </p>
                                                    <p className="text-lg font-black text-slate-950">{customer.firstName ?? customer.first_name ?? '-'} {customer.lastName ?? customer.last_name ?? ''}</p>
                                                    <p className="mt-1 text-sm text-slate-600">{customer.email ?? '-'}</p>
                                                    <p className="mt-1 text-sm text-slate-600">{customer.mobile ?? '-'}</p>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                    <p className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                                        <ReceiptText className="h-3.5 w-3.5" /> Transactions
                                                    </p>
                                                    <p className="text-sm text-slate-600">Provider wallet transaction</p>
                                                    <p className="mt-1 break-all font-mono text-xs text-slate-700">{tx?.wallet_transaction?.uuid ?? item.wallet_transaction_id ?? '-'}</p>
                                                    <p className="mt-3 text-sm text-slate-600">Provider total</p>
                                                    <p className="text-base font-bold text-slate-950">{formatAmount(details.total_purchase ?? item.total_amount ?? item.total, details.provider_currency ?? item.currency)}</p>
                                                    <p className="mt-3 text-sm text-slate-600">Markup profit</p>
                                                    <p className="text-base font-bold text-slate-950">{formatAmount(details.markup_amount ?? item.commission_amount ?? 0, item.currency)} ({details.markup_percent ?? item.commission_percent ?? 0}%)</p>
                                                    <p className="mt-1 text-xs text-slate-500">Returned price: {details.returned_price ? 'Yes' : 'No'}</p>
                                                    {item.status === 'cancelled' ? (
                                                        <>
                                                            <p className="mt-3 text-sm text-slate-600">Refund amount</p>
                                                            <p className="text-base font-bold text-slate-950">{formatAmount(cancellation.refund_amount ?? 0, item.currency)}</p>
                                                        </>
                                                    ) : null}
                                                </div>
                                            </div>

                                            {bookedRooms.length > 0 ? (
                                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                                    <p className="mb-3 font-black uppercase tracking-[0.2em] text-slate-500">Booked Rooms</p>
                                                    <div className="grid gap-3 md:grid-cols-2">
                                                        {bookedRooms.map((bookedRoom, index) => (
                                                            <div key={`${bookedRoom.rateKey ?? bookedRoom.ratekey ?? index}`} className="rounded-xl border border-slate-200 bg-slate-50/60 p-3 text-sm">
                                                                <div className="flex items-start justify-between gap-3">
                                                                    <div>
                                                                        <p className="font-bold text-slate-950">Room {bookedRoom.roomIndex ?? index + 1}: {bookedRoom.name ?? bookedRoom.room_name ?? '-'}</p>
                                                                        <p className="mt-1 text-slate-600">Board: {bookedRoom.boardName ?? bookedRoom.board_name ?? '-'}</p>
                                                                        <p className="mt-1 text-slate-600">Association: {bookedRoom.associationId ?? '-'}</p>
                                                                    </div>
                                                                    <p className="font-black text-slate-950">{formatAmount(bookedRoom.price ?? 0, bookedRoom.currency ?? item.currency)}</p>
                                                                </div>
                                                                <p className="mt-2 text-slate-600">No-show: {formatAmount(bookedRoom.noShow ?? 0, bookedRoom.currency ?? item.currency)}</p>
                                                                {(bookedRoom.cancellationPolicies ?? []).length > 0 ? (
                                                                    <p className="mt-1 text-slate-600">
                                                                        Cancellation from {bookedRoom.cancellationPolicies[0]?.from ?? '-'}: {formatAmount(bookedRoom.cancellationPolicies[0]?.amount ?? 0, bookedRoom.currency ?? item.currency)}
                                                                    </p>
                                                                ) : null}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            ) : null}

                                            {providerComments ? (
                                                <div className="rounded-2xl border border-sky-200 bg-sky-50/80 p-4 text-sm text-sky-950">
                                                    <p className="font-black uppercase tracking-[0.2em] text-sky-700">Provider Notes</p>
                                                    <p className="mt-2">{providerComments}</p>
                                                </div>
                                            ) : null}

                                            {item.status === 'cancellation' ? (
                                                <div className="rounded-2xl border border-sky-200 bg-sky-50/80 p-4 text-sm text-sky-950">
                                                    <p className="font-black uppercase tracking-[0.2em] text-sky-700">Cancellation Request</p>
                                                    <p className="mt-2">Auto cancellation was denied by 3T, but a cancellation request has been sent for this booking.</p>
                                                    <p className="mt-1">Provider message: {cancellationRequest.message ?? '-'}</p>
                                                    <p className="mt-1">Requested at: {formatDateTime(cancellationRequest.requested_at)}</p>
                                                </div>
                                            ) : null}

                                            {item.status === 'cancelled' ? (
                                                <div className="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950">
                                                    <p className="font-black uppercase tracking-[0.2em] text-amber-700">Cancellation</p>
                                                    <p className="mt-2">Cancellation fee: {formatAmount(cancellation.cancellation_fee ?? 0, item.currency)}</p>
                                                    <p className="mt-1">Refund amount: {formatAmount(cancellation.refund_amount ?? 0, item.currency)}</p>
                                                    <p className="mt-1 break-all">Provider wallet refund transaction: {cancellation.provider_wallet_transaction_id ?? '-'}</p>
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                );
                            }

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
                                                <Badge variant={itemStatusVariant(item.status)} className="px-3 py-1 font-black uppercase text-white tracking-wider">
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
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Main Wallet Transaction</p>
                                                <p className="mt-2 break-all font-mono text-xs text-slate-700">{tx?.wallet_transaction?.uuid ?? '-'}</p>
                                            </div>
                                            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Provider Wallet</p>
                                                <p className="mt-2 text-sm font-semibold text-slate-900">
                                                    {tx?.provider_wallet_transaction
                                                        ? `${tx.provider_wallet_transaction.type} (${formatAmount(Number(tx.provider_wallet_transaction.amount ?? 0) / 100, item.currency)})`
                                                        : tx?.provider_wallet_void_transaction
                                                            ? `${tx.provider_wallet_void_transaction.type} (${formatAmount(Number(tx.provider_wallet_void_transaction.amount ?? 0) / 100, item.currency)})`
                                                            : tx?.provider_wallet_refund_transaction
                                                                ? `${tx.provider_wallet_refund_transaction.type} (${formatAmount(Number(tx.provider_wallet_refund_transaction.amount ?? 0) / 100, item.currency)})`
                                                                : '-'}
                                                </p>
                                            </div>
                                            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Refund / Penalty</p>
                                                <p className="mt-2 text-sm font-semibold text-slate-900">
                                                    {tx?.refund_wallet_transaction
                                                        ? `${formatAmount(Number(tx.refund_wallet_transaction.amount ?? 0) / 100, item.currency)} refunded`
                                                        : '-'}
                                                </p>
                                                {tx?.refund_penalty_transaction ? (
                                                    <p className="mt-1 text-xs font-semibold text-rose-700">
                                                        Penalty {formatAmount(Math.abs(Number(tx.refund_penalty_transaction.amount ?? 0)) / 100, item.currency)}
                                                    </p>
                                                ) : null}
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
                            <Button type="button" variant="outline" onClick={closeVoidModal} disabled={voidForm.processing}>
                                Keep Ticket
                            </Button>
                            <Button type="button" variant="destructive" onClick={confirmVoid} disabled={voidForm.processing}>
                                {voidForm.processing ? 'Voiding...' : 'Confirm Void'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(cancelTarget)} onOpenChange={(open) => (open ? null : closeCancelModal())}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Cancel Insurance Policy</DialogTitle>
                            <DialogDescription>
                                This operation is not reversible. Once the cancellation request is sent, the insurance company will review it before the policy can be finalized as cancelled.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                                <div className="flex items-start gap-3">
                                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                                    <p>Please confirm carefully before continuing. After approval from the insurance company, the user will still need to confirm the final cancellation.</p>
                                </div>
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Policy Reference</p>
                                    <p className="mt-2 font-semibold text-slate-900">{cancelTarget?.provider_reference ?? cancelTarget?.ticket_number ?? '-'}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Policy Amount</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {cancelTarget ? formatAmount(cancelTarget.total_amount ?? cancelTarget.total, cancelTarget.currency) : '-'}
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <label htmlFor="insurance-cancellation-remarks" className="text-sm font-semibold text-slate-900">
                                    Cancellation Note
                                </label>
                                <textarea
                                    id="insurance-cancellation-remarks"
                                    rows={5}
                                    value={cancelForm.data.remarks}
                                    onChange={(event) => cancelForm.setData('remarks', event.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                                    placeholder="Explain why the policy should be cancelled."
                                />
                                {cancelForm.errors.remarks ? <p className="text-sm text-rose-600">{cancelForm.errors.remarks}</p> : null}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeCancelModal} disabled={cancelForm.processing}>
                                Keep Policy
                            </Button>
                            <Button type="button" variant="destructive" onClick={submitCancellation} disabled={cancelForm.processing}>
                                {cancelForm.processing ? 'Sending...' : 'Send Cancellation Request'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(finalizeTarget)} onOpenChange={(open) => (open ? null : closeFinalizeModal())}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Confirm Insurance Cancellation</DialogTitle>
                            <DialogDescription>
                                The insurance company has approved this cancellation. Confirming now will finalize the policy cancellation, deposit the policy amount, and reverse the commission.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                                <div className="flex items-start gap-3">
                                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0" />
                                    <p>The latest insurance company remark is <span className="font-black">تم الالغاء</span>. Final confirmation is required from the user.</p>
                                </div>
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-3">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Policy Amount</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount(finalizeTarget.total_amount ?? finalizeTarget.total, finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Commission Reversal</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount(finalizeTarget.commission_amount ?? 0, finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Net Wallet Effect</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount((finalizeTarget.total_amount ?? finalizeTarget.total ?? 0) - (finalizeTarget.commission_amount ?? 0), finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeFinalizeModal} disabled={finalizeCancellationForm.processing}>
                                Not Now
                            </Button>
                            <Button type="button" onClick={confirmCancellationFinalization} disabled={finalizeCancellationForm.processing}>
                                {finalizeCancellationForm.processing ? 'Confirming...' : 'Confirm Cancellation'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </TenantSidebarLayout>
    );
}
