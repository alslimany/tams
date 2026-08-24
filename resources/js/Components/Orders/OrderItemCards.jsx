import React from 'react';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { useTranslation } from '@/hooks/useTranslation';
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
    HotelIcon,
    MoreHorizontal,
    Plane,
    ShieldCheck,
    SmartphoneNfc,
    Smartphone,
    QrCode,
} from 'lucide-react';

const compactStatusVariant = (status) => {
    if (['issued', 'paid', 'confirmed'].includes(status)) {
        return 'success';
    }

    if (['voided', 'refunded', 'cancelled'].includes(status)) {
        return 'secondary';
    }

    return 'outline';
};

const valueOrFallback = (...values) => values.find((value) => value !== undefined && value !== null && String(value).trim() !== '') ?? '-';

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const daysBetween = (from, to) => {
    const start = new Date(from);
    const end = new Date(to);

    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
        return null;
    }

    return Math.max(1, Math.round((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)));
};

const stripHtml = (value) => String(value ?? '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&agrave;/g, 'à')
    .replace(/&acirc;/g, 'â')
    .replace(/&eacute;/g, 'é')
    .replace(/&#39;/g, "'")
    .replace(/&ocirc;/g, 'ô')
    .replace(/&egrave;/g, 'è')
    .replace(/&icirc;/g, 'î')
    .replace(/&ccedil;/g, 'ç')
    .replace(/\s+/g, ' ')
    .trim();

const providerLabel = (provider) => {
    const map = {
        videcom: 'Videcom',
        albaraka: 'Al Baraka',
        '3t': '3T Hotels',
        hotelbeds: 'Hotelbeds',
        amadeus: 'Amadeus',
    };

    const key = String(provider ?? '').toLowerCase();

    return map[key] ?? (provider ? String(provider) : null);
};

const CardShell = ({ icon: Icon, eyebrow, title, reference, status, total, currency, provider, children, actions }) => {
    const { t } = useTranslation();
    const providerName = providerLabel(provider);

    return (
        <Card className="overflow-hidden border-slate-200 shadow-sm">
            <CardHeader className="border-b bg-slate-50/70 px-4 py-3">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white text-slate-700 ring-1 ring-slate-200">
                                <Icon className="size-4" />
                            </div>
                            <p className="text-xs font-bold uppercase text-slate-500">{eyebrow}</p>
                            <Badge variant={compactStatusVariant(status)} className="px-2 py-0.5 text-[10px] font-bold uppercase">
                                {t(`orders.status_${status ?? 'pending'}`) || (status ?? 'pending')}
                            </Badge>
                            {providerName ? (
                                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500 ring-1 ring-slate-200">
                                    {providerName}
                                </span>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <CardTitle className="text-base font-black text-slate-950">{title}</CardTitle>
                            <span className="text-xs font-semibold text-slate-500">{reference}</span>
                        </div>
                    </div>

                    <div className="flex items-start gap-2 md:items-center">
                        <div className="text-left md:text-right">
                            <p className="text-[10px] font-bold uppercase text-slate-500">{t('orders.total')}</p>
                            <p className="text-lg font-black tabular-nums text-slate-950">{formatMoney(total ?? 0, currency)}</p>
                        </div>
                        {actions}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="p-4">
                {children}
            </CardContent>
        </Card>
    );
};

const Field = ({ label, value, className = '' }) => (
    <div className={className}>
        <p className="text-[10px] font-bold uppercase text-slate-500">{label}</p>
        <p className="mt-1 text-sm font-semibold text-slate-900">{value ?? '-'}</p>
    </div>
);

const flightNumberMatches = (ticketFlightNumber, itinerary) => {
    if (!ticketFlightNumber) {
        return false;
    }

    const compactTicketFlight = String(ticketFlightNumber).replace(/\s+/g, '').toUpperCase();
    const compactItineraryFlight = `${itinerary.airline_id ?? ''}${itinerary.flight_number ?? ''}`.replace(/\s+/g, '').toUpperCase();

    return compactTicketFlight === compactItineraryFlight;
};

const normalizedSegmentNumber = (value) => {
    const number = Number.parseInt(String(value ?? '').replace(/^0+/, ''), 10);

    return Number.isNaN(number) ? null : number;
};

const getTicketsForItinerary = (pnr, itinerary) => {
    const tickets = pnr?.tickets ?? [];

    return tickets.filter((ticket) => {
        const ticketSegmentNumber = normalizedSegmentNumber(ticket.segment_number);
        const itinerarySegmentNumber = normalizedSegmentNumber(itinerary.itinerary_id);

        if (ticketSegmentNumber !== null && itinerarySegmentNumber !== null && ticketSegmentNumber === itinerarySegmentNumber) {
            return true;
        }

        return flightNumberMatches(ticket.flight_number, itinerary)
            && String(ticket.from ?? '') === String(itinerary.from ?? '')
            && String(ticket.to ?? '') === String(itinerary.to ?? '');
    });
};

const normalizeFlightItineraries = (item) => {
    const pnr = item.item_details ?? {};
    const pnrItineraries = Array.isArray(pnr.itineraries) ? pnr.itineraries : [];

    if (pnrItineraries.length > 0) {
        return pnrItineraries.map((itinerary, index) => ({
            key: `${item.id}-pnr-${itinerary.itinerary_id ?? index}`,
            raw: itinerary,
            from: valueOrFallback(itinerary.from, itinerary.origin),
            to: valueOrFallback(itinerary.to, itinerary.destination),
            date: valueOrFallback(itinerary.date, itinerary.departure_date),
            departure: valueOrFallback(itinerary.departure, itinerary.departure_time),
            arrival: valueOrFallback(itinerary.arrival, itinerary.arrival_time),
            airline: valueOrFallback(itinerary.airline_id, pnr.iata, item.item_details?.airline_code, ''),
            flightNumber: valueOrFallback(itinerary.flight_number, ''),
            classLabel: valueOrFallback(itinerary.class_band_display_name, itinerary.class_band, itinerary.class),
            cabin: valueOrFallback(itinerary.cabin, itinerary.class),
            status: valueOrFallback(itinerary.status),
            tickets: getTicketsForItinerary(pnr, itinerary),
        }));
    }

    const segments = item.product_details?.segments ?? pnr.segments ?? [];

    return segments.map((segment, index) => ({
        key: `${item.id}-segment-${index}`,
        raw: segment,
        from: valueOrFallback(segment.departure_airport, segment.origin),
        to: valueOrFallback(segment.arrival_airport, segment.destination),
        date: valueOrFallback(segment.date, segment.departure_date, segment.departure_time),
        departure: valueOrFallback(segment.departure_time),
        arrival: valueOrFallback(segment.arrival_time),
        airline: valueOrFallback(segment.airline_id, pnr.iata, item.item_details?.airline_code, ''),
        flightNumber: valueOrFallback(segment.flight_number),
        classLabel: valueOrFallback(segment.class_band_display_name, segment.class_band, segment.class),
        cabin: valueOrFallback(segment.cabin, segment.class),
        status: valueOrFallback(segment.status),
        tickets: pnr.tickets ?? [],
    }));
};

const passengerNameFromTicket = (ticket, passengers) => {
    const matchedPassenger = passengers.find((passenger) => String(passenger.id ?? '') === String(ticket.passenger_id ?? ''));

    if (matchedPassenger) {
        return `${matchedPassenger.title ?? ''} ${matchedPassenger.first_name ?? ''} ${matchedPassenger.last_name ?? ''}`.trim();
    }

    return `${ticket.title ?? ''} ${ticket.first_name ?? ''} ${ticket.last_name ?? ''}`.trim() || '-';
};

const ticketCouponNumber = (ticket, fallbackIndex) => valueOrFallback(ticket.coupon, ticket.coupon_number, ticket.segment_number, fallbackIndex + 1);

const formattedFlightCode = (itinerary) => `${itinerary.airline === '-' ? '' : itinerary.airline}${itinerary.flightNumber === '-' ? '' : itinerary.flightNumber}`.trim() || '-';

const AirlineLogo = ({ code }) => {
    const airlineCode = String(code ?? '').trim().toUpperCase();

    if (airlineCode === '' || airlineCode === '-') {
        return <Plane className="size-6" />;
    }

    return (
        <>
            <img
                src={route('api.airlines.logo', { code: airlineCode, variant: 'icon-transparent', radius: 8 })}
                alt={`${airlineCode} logo`}
                className="size-8 object-contain"
                onError={(event) => {
                    event.currentTarget.classList.add('hidden');
                    event.currentTarget.nextElementSibling?.classList.remove('hidden');
                }}
            />
            <Plane className="hidden size-6" />
        </>
    );
};

export function FlightOrderItemCard({ item, canManage, onVoid, onPrint, onRefund, onChangeTicket }) {
    const { t } = useTranslation();
    const pnr = item.item_details ?? {};
    const itineraries = normalizeFlightItineraries(item);
    const passengers = pnr.passengers ?? [];
    const pnrCode = valueOrFallback(item.provider_reference, pnr.rloc, pnr.pnr);
    const total = Number(pnr.total_price ?? item.total_amount ?? item.total ?? 0);
    const currency = pnr.currency ?? item.currency;
    const canVoid = item.status === 'issued' && (item.item_details?.is_voidable ?? true);
    const canRefund = item.status === 'issued' && !(item.item_details?.is_voidable ?? true);
    const canChange = item.status === 'issued';
    const canPrint = ['issued', 'paid', 'confirmed'].includes(item.status);

    return (
        <CardShell
            icon={Plane}
            eyebrow={t('orders.product_flight')}
            title={`${t('orders.pnr')}: ${pnrCode}`}
            reference=""
            status={item.status}
            total={total}
            currency={currency}
            provider={item.provider}
            actions={canManage ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm" className="h-8 px-2 text-xs">
                            {t('orders.manage')} <MoreHorizontal className="ms-1 size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-44">
                        <DropdownMenuLabel>{t('orders.manage_ticket')}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            disabled={!canVoid}
                            className={canVoid ? 'text-rose-600 focus:text-rose-700' : ''}
                            onSelect={(event) => {
                                event.preventDefault();
                                onVoid(item, total, currency);
                            }}
                        >
                            {t('orders.void_tickets')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={!canPrint}
                            onSelect={(event) => {
                                event.preventDefault();
                                onPrint(item);
                            }}
                        >
                            {t('orders.print_tickets')}
                        </DropdownMenuItem>
                        <DropdownMenuItem disabled>{t('orders.share_tickets')}</DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={!canRefund}
                            className={canRefund ? 'text-amber-600 focus:text-amber-700' : ''}
                            onSelect={(event) => {
                                event.preventDefault();
                                onRefund(item, total, currency);
                            }}
                        >
                            {t('orders.refund_tickets')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={!canChange}
                            className={canChange ? 'text-sky-600 focus:text-sky-700' : ''}
                            onSelect={(event) => {
                                event.preventDefault();
                                onChangeTicket(item);
                            }}
                        >
                            {t('orders.change_ticket')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        >
            <div className="space-y-3">
                {itineraries.map((itinerary) => {
                    const coupons = itinerary.tickets;
                    const bookedBy = valueOrFallback(pnr.booked_by, item.item_details?.customer?.name, item.item_details?.customer?.full_name, item.item_details?.customer?.email);

                    return (
                        <div key={itinerary.key} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                            <div className="flex items-start gap-3 border-b border-slate-200 px-3 py-3">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-lg bg-slate-50 text-slate-600 ring-1 ring-slate-200">
                                    <AirlineLogo code={itinerary.airline} />
                                </div>

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                        <p className="font-black text-slate-950">
                                            {itinerary.from} → {itinerary.to}
                                        </p>
                                        <span className="text-xs font-semibold text-slate-500">|</span>
                                        <span className="font-black text-slate-950">{formattedFlightCode(itinerary)}</span>
                                        <span>•</span>
                                        <span className="text-xs font-semibold text-slate-600">ETKT: {coupons[0]?.ticket_number ?? item.ticket_number ?? '-'}</span>
                                        <span>•</span>
                                        <span className="text-xs font-semibold text-slate-600">{itinerary.status}</span>
                                        <span>•</span>
                                        <span className="text-xs font-semibold text-slate-600">{itinerary.classLabel}</span>
                                        <span>•</span>
                                        <span className="text-xs font-semibold text-slate-600">{itinerary.cabin}</span>
                                    </div>
                                    <div className="mt-2 space-y-1">
                                        {coupons.length > 0 ? coupons.map((ticket, index) => (
                                            <div key={`${ticket.ticket_number ?? index}-${ticket.passenger_id ?? index}`} className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-700">
                                                <span className="font-black">{t('orders.coupon')} {ticketCouponNumber(ticket, index)}</span>
                                                <span>•</span>
                                                <span>{ticket.ticket_number ?? item.ticket_number ?? '-'}</span>
                                                <span>•</span>
                                                <span>{ticket.status ?? '-'}</span>
                                                <span>•</span>
                                                <span>{passengerNameFromTicket(ticket, passengers)}</span>
                                            </div>
                                        )) : (
                                            <div className="rounded-lg bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-500">
                                                {t('orders.no_passenger_coupon')}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-0 text-sm md:grid-cols-[1fr_1fr_1.1fr]">
                                <div className="border-b border-slate-200 px-3 py-2 md:border-b-0 md:border-e">
                                    <p className="flex items-center gap-2 text-xs font-bold uppercase text-slate-500">
                                        <Plane className="size-4" /> {itinerary.from}
                                    </p>
                                    <p className="mt-1 text-sm font-semibold text-slate-900">
                                        {formatDate(itinerary.date)} · {itinerary.departure}
                                    </p>
                                </div>
                                <div className="border-b border-slate-200 px-3 py-2 md:border-b-0 md:border-e">
                                    <p className="flex items-center gap-2 text-xs font-bold uppercase text-slate-500">
                                        <Plane className="size-4" /> {itinerary.to}
                                    </p>
                                    <p className="mt-1 text-sm font-semibold text-slate-900">
                                        {formatDate(itinerary.date)} · {itinerary.arrival}
                                    </p>
                                </div>
                                <div className="px-3 py-2">
                                    <p className="text-xs font-bold uppercase text-slate-500">{t('orders.booked_by')}</p>
                                    <p className="mt-1 text-sm font-black uppercase text-slate-900">{bookedBy}</p>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </CardShell>
    );
}

const insuranceLabel = (subtype) => {
    const normalized = String(subtype ?? '').toLowerCase();

    if (normalized === 'travel') {
        return 'Travel Insurance';
    }

    if (normalized === 'orange') {
        return 'Orange Insurance';
    }

    return 'Compulsory Insurance';
};

const getBeneficiary = (item) => item.product_details?.beneficiary ?? item.item_details?.beneficiary ?? {};

const getPassengerName = (item) => {
    const directName = String(item.product_details?.passenger_name ?? item.item_details?.passenger_name ?? '').trim();

    if (directName !== '') {
        return directName;
    }

    const passenger = item.product_details?.passenger ?? item.item_details?.insurance?.passenger ?? {};

    return `${passenger.first_name ?? ''} ${passenger.last_name ?? ''}`.trim() || '-';
};

export function InsuranceOrderItemCard({ item, canManage, onCancel, onPrint, isApprovedCancellation }) {
    const { t } = useTranslation();
    const policyDetails = item.product_details?.policy_details ?? {};
    const beneficiary = getBeneficiary(item);
    const subtype = String(item.product_subtype ?? '').toLowerCase();
    const coverageStart = valueOrFallback(policyDetails.policy_date_from, policyDetails.PolicyDateFrom);
    const coverageEnd = valueOrFallback(policyDetails.policy_date_to, policyDetails.PolicyDateTo);
    const durationDays = daysBetween(coverageStart, coverageEnd);
    const zone = valueOrFallback(policyDetails.zone_text, policyDetails.zone, item.product_details?.zone_text, item.product_details?.zone_id, policyDetails.zone_id, policyDetails.country, policyDetails.countries);
    const vehicleSummary = valueOrFallback(
        policyDetails.vehicle_summary,
        [policyDetails.car, policyDetails.metal_plate_number, policyDetails.chassis_number].filter(Boolean).join(' · '),
        [policyDetails.vehicle_owner_name, policyDetails.metalPlateNo].filter(Boolean).join(' · '),
    );
    const canCancel = item.status === 'issued';
    const canPrint = ['issued', 'paid', 'confirmed', 'cancellation'].includes(item.status);

    return (
        <CardShell
            icon={ShieldCheck}
            eyebrow={insuranceLabel(subtype)}
            title={valueOrFallback(policyDetails.policy_number, item.ticket_number, item.provider_reference)}
            reference={`Policy ID ${valueOrFallback(policyDetails.policy_id, policyDetails.Id, item.provider_reference)}`}
            status={item.status}
            total={item.total_amount ?? item.total}
            currency={item.currency}
            provider={item.provider}
            actions={canManage ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm" className="h-8 px-2 text-xs">
                            {t('orders.manage')} <MoreHorizontal className="ms-1 size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuLabel>{t('orders.manage_policy')}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            disabled={!canCancel}
                            className={canCancel ? 'text-rose-600 focus:text-rose-700' : ''}
                            onSelect={(event) => {
                                event.preventDefault();
                                onCancel(item);
                            }}
                        >
                            {t('orders.request_cancellation')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={!canPrint}
                            onSelect={(event) => {
                                event.preventDefault();
                                onPrint(item);
                            }}
                        >
                            {t('orders.print_policy')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        >
            <div className="grid gap-3 text-sm md:grid-cols-4">
                <Field label={t('orders.coverage')} value={`${formatDate(coverageStart)} → ${formatDate(coverageEnd)}`} />
                <Field label={t('orders.duration_zone')} value={`${durationDays ? t('orders.days_count', { count: durationDays }) : valueOrFallback(policyDetails.duration_text, policyDetails.duration_id)} · ${zone}`} />
                <Field label={subtype === 'travel' ? t('orders.passenger') : t('orders.beneficiary')} value={subtype === 'travel' ? getPassengerName(item) : valueOrFallback(beneficiary.name, policyDetails.vehicle_owner_name)} />
                <Field label={subtype === 'travel' ? t('orders.passport_contact') : t('orders.vehicle_cover')} value={subtype === 'travel' ? valueOrFallback(item.product_details?.passenger?.passport_number, beneficiary.phone, beneficiary.email) : vehicleSummary} />

                {item.status === 'cancellation' || item.status === 'cancelled' ? (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950 md:col-span-4">
                        {t('orders.cancellation')}: {isApprovedCancellation(item) ? t('orders.approved_by_insurance_company') : t('orders.waiting_for_insurance_company_approval')}
                    </div>
                ) : null}
            </div>
        </CardShell>
    );
}

const roomTravellers = (bookedRoom, submittedRoom) => {
    const submittedPaxes = Array.isArray(submittedRoom?.paxes) ? submittedRoom.paxes : [];

    if (submittedPaxes.length > 0) {
        return submittedPaxes.map((pax) => `${pax.civility ?? ''} ${pax.first_name ?? pax.firstName ?? ''} ${pax.last_name ?? pax.lastName ?? ''}`.trim()).filter(Boolean);
    }

    const paxes = bookedRoom?.paxes ?? {};
    const adultCount = Number(paxes.adult ?? 0);
    const childCount = Number(paxes.child?.value ?? 0);

    return [`${adultCount} adult${adultCount === 1 ? '' : 's'}`, `${childCount} child${childCount === 1 ? '' : 'ren'}`].filter((value) => !value.startsWith('0 '));
};

export function HotelOrderItemCard({ item, canManage, onCancel, onPrintVoucher }) {
    const { t } = useTranslation();
    const details = item.item_details ?? {};
    const product = item.product_details ?? {};
    const hotel = product.hotel ?? {};
    const stay = product.stay ?? details.provider_booking ?? {};
    const bookedRooms = product.rooms ?? details.provider_booking?.rooms ?? details.rooms ?? [];
    const submittedRooms = details.rooms ?? [];
    const comments = stripHtml(product.comments ?? details.comments ?? '');
    const from = valueOrFallback(stay.from, details.search?.check_in);
    const to = valueOrFallback(stay.to, details.search?.check_out);
    const nights = daysBetween(from, to);
    const canCancel = !['cancelled', 'cancellation', 'refunded'].includes(item.status);

    return (
        <CardShell
            icon={HotelIcon}
            eyebrow={t('orders.hotel_booking')}
            title={valueOrFallback(hotel.name, hotel.hotel_name)}
            reference={`Booking ${valueOrFallback(details.booking_id, item.provider_reference)} · Ref ${valueOrFallback(details.booking_ref, item.ticket_number)}`}
            status={item.status}
            total={item.total_amount ?? item.total}
            currency={item.currency}
            provider={item.provider}
            actions={(
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm" className="h-8 px-2 text-xs">
                            {t('orders.manage')} <MoreHorizontal className="ms-1 size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuLabel>{t('orders.manage_booking')}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {onPrintVoucher ? (
                            <DropdownMenuItem
                                onSelect={(event) => {
                                    event.preventDefault();
                                    onPrintVoucher(item);
                                }}
                            >
                                {t('orders.print_voucher')}
                            </DropdownMenuItem>
                        ) : null}
                        {canManage ? (
                            <DropdownMenuItem
                                disabled={!canCancel}
                                className={canCancel ? 'text-rose-600 focus:text-rose-700' : ''}
                                onSelect={(event) => {
                                    event.preventDefault();
                                    onCancel(item);
                                }}
                            >
                                {item.status === 'cancellation' ? t('orders.cancellation_requested') : t('orders.cancel_booking')}
                            </DropdownMenuItem>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        >
            <div className="space-y-3">
                <div className="grid gap-3 text-sm md:grid-cols-4">
                    <Field label={t('orders.location')} value={`${valueOrFallback(hotel.city_name)} · ${valueOrFallback(hotel.country_name)}`} />
                    <Field label={t('orders.stay')} value={`${formatDate(from)} → ${formatDate(to)}`} />
                    <Field label={t('orders.nights')} value={nights ? t('orders.nights_count', { count: nights }) : '-'} />
                    <Field label={t('orders.deadline')} value={formatDate(stay.deadline)} />
                </div>

                <div className="overflow-hidden rounded-xl border border-slate-200">
                    {bookedRooms.map((bookedRoom, index) => {
                        const travellers = roomTravellers(bookedRoom, submittedRooms[index]);

                        return (
                            <div key={`${bookedRoom.rateKey ?? bookedRoom.ratekey ?? index}`} className="grid gap-3 border-b border-slate-200 px-3 py-2.5 text-sm last:border-b-0 md:grid-cols-[1.4fr_1fr_1.2fr] md:items-center">
                                <div>
                                    <p className="font-black text-slate-950">{t('orders.room')} {bookedRoom.roomIndex ?? index + 1}: {valueOrFallback(bookedRoom.name, bookedRoom.room_name)}</p>
                                    <p className="text-xs text-slate-500">{valueOrFallback(bookedRoom.boardName, bookedRoom.board_name)} · {valueOrFallback(bookedRoom.rateClass)}</p>
                                </div>
                                <div className="text-xs text-slate-600">
                                    <p>{travellers.join(', ') || '-'}</p>
                                    <p>{t('orders.no_show')}: {formatMoney(bookedRoom.noShow ?? 0, bookedRoom.currency ?? item.currency)}</p>
                                </div>
                                <div className="text-xs text-slate-600 md:text-right">
                                    <p className="font-black text-slate-950">{formatMoney(bookedRoom.price ?? 0, bookedRoom.currency ?? item.currency)}</p>
                                    <p>{(bookedRoom.cancellationPolicies ?? []).length > 0 ? t('orders.cancellation_rules_available') : t('orders.no_cancellation_rules_returned')}</p>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {comments ? (
                    <div className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-950">
                        {comments}
                    </div>
                ) : null}
            </div>
        </CardShell>
    );
}

export function ESimOrderItemCard({ item, canManage, onRefund }) {
    const { t } = useTranslation();
    const details = item.item_details ?? {};
    const product = item.product_details ?? {};
    const customer = details.customer ?? {};
    const country = valueOrFallback(details.country, product.country);
    const days = Number(details.validity_days ?? 0);
    const dataMb = Number(details.data_mb ?? 0);
    const activationCode = valueOrFallback(details.activation_code);
    const smdpAddress = valueOrFallback(details.smdp_address);
    const lpaString = valueOrFallback(details.lpa_string);
    const matchingId = activationCode !== '-' ? activationCode : '';

    const iosInstallUrl = lpaString && lpaString !== '-'
        ? `https://esimsetup.apple.com/esim_qrcode_provisioning?carddata=${encodeURIComponent(lpaString)}`
        : null;

    const lpaQrString = smdpAddress !== '-' && matchingId
        ? `LPA:1$${smdpAddress}$${matchingId}$`
        : null;

    const qrCodeUrl = lpaQrString
        ? `https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=${encodeURIComponent(lpaQrString)}`
        : null;

    const [activateOpen, setActivateOpen] = React.useState(false);
    const [guideTab, setGuideTab] = React.useState('ios');

    const canRefund = item.status === 'issued';

    return (
        <>
            <CardShell
                icon={SmartphoneNfc}
                eyebrow={t('orders.product_esim')}
                title={valueOrFallback(details.package_name, product.package_name, details.package_id)}
                reference={t('orders.provider_order_ref') + ': ' + valueOrFallback(details.provider_order_id, item.provider_reference)}
                status={item.status}
                total={item.total_amount ?? item.total}
                currency={item.currency}
                provider={item.provider}
                actions={canManage ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm" className="h-8 px-2 text-xs">
                                {t('orders.manage')} <MoreHorizontal className="ms-1 size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuLabel>{t('orders.manage_esim')}</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                disabled={!canRefund}
                                className={canRefund ? 'text-rose-600 focus:text-rose-700' : ''}
                                onSelect={(event) => {
                                    event.preventDefault();
                                    onRefund(item);
                                }}
                            >
                                {t('orders.esim_refund')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={(event) => {
                                    event.preventDefault();
                                    setActivateOpen(true);
                                }}
                            >
                                {t('orders.esim_activate')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            >
                <div className="space-y-4">
                    {/* Package summary */}
                    <div className="grid gap-3 text-sm md:grid-cols-4">
                        <Field label={t('orders.esim_country')} value={country} />
                        <Field
                            label={t('orders.esim_data')}
                            value={dataMb > 0 ? `${dataMb} MB` : t('orders.esim_unlimited')}
                        />
                        <Field
                            label={t('orders.esim_validity')}
                            value={days > 0 ? t('orders.days_count', { count: days }) : '-'}
                        />
                        <Field
                            label={t('orders.esim_customer')}
                            value={valueOrFallback(customer.name, customer.full_name, customer.email)}
                        />
                    </div>

                    {/* One-click install */}
                    {iosInstallUrl ? (
                        <div className="rounded-xl border border-sky-200 bg-gradient-to-br from-sky-50 to-blue-50 p-4">
                            <p className="text-xs font-bold uppercase text-sky-700">{t('orders.esim_one_click_install')}</p>
                            <p className="mt-1 text-xs text-sky-600">{t('orders.esim_install_ios_hint')}</p>
                            <a
                                href={iosInstallUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-3 inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-sky-700"
                            >
                                <SmartphoneNfc className="size-3.5" />
                                {t('orders.esim_install_on_ios')}
                            </a>
                        </div>
                    ) : null}
                </div>
            </CardShell>

            {/* Activation Modal */}
            {activateOpen ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setActivateOpen(false)}>
                    <div
                        className="w-full max-w-lg rounded-2xl bg-white shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="border-b px-6 py-4">
                            <div className="flex items-center gap-3">
                                <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                    <QrCode className="size-5" />
                                </div>
                                <div>
                                    <h2 className="text-lg font-black text-slate-950">{t('orders.esim_activate_title')}</h2>
                                    <p className="text-xs text-slate-500">{t('orders.esim_activate_subtitle')}</p>
                                </div>
                            </div>
                            <button
                                onClick={() => setActivateOpen(false)}
                                className="absolute right-6 top-4 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                            >
                                <svg className="size-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                </svg>
                            </button>
                        </div>

                        <div className="space-y-5 px-6 py-5">
                            {/* QR Code */}
                            {qrCodeUrl ? (
                                <div className="flex flex-col items-center">
                                    <div className="rounded-xl border-2 border-slate-200 bg-white p-3">
                                        <img
                                            src={qrCodeUrl}
                                            alt="eSIM QR Code"
                                            className="size-56"
                                        />
                                    </div>
                                    <p className="mt-3 text-center text-xs font-medium text-slate-500">
                                        {t('orders.esim_scan_qr_hint')}
                                    </p>
                                </div>
                            ) : null}

                            {/* Setup guide tabs */}
                            <div className="space-y-4">
                                <div className="flex rounded-lg bg-slate-100 p-1">
                                    <button
                                        type="button"
                                        onClick={() => setGuideTab('ios')}
                                        className={`flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-2 text-xs font-bold transition ${
                                            guideTab === 'ios'
                                                ? 'bg-white text-slate-900 shadow-sm'
                                                : 'text-slate-500 hover:text-slate-700'
                                        }`}
                                    >
                                        <svg className="size-3.5" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M18.71 19.5C17.88 20.74 17 21.95 15.66 21.97C14.32 21.99 13.89 21.18 12.37 21.18C10.84 21.18 10.37 21.95 9.099 21.99C7.789 22.03 6.799 20.68 5.959 19.47C4.249 16.97 3.019 12.44 4.789 9.389C5.669 7.829 7.249 6.839 8.969 6.819C10.25 6.799 11.47 7.689 12.25 7.689C13.03 7.689 14.51 6.619 16.05 6.779C16.71 6.809 18.54 7.049 19.72 8.789C19.63 8.849 17.62 10.04 17.64 12.54C17.66 15.46 20.18 16.44 20.25 16.47C20.18 16.67 19.58 18.79 18.71 19.5ZM12.03 6.539C11.17 5.629 11.51 4.189 11.81 3.399C12.53 3.139 13.43 2.939 14.23 3.399C14.69 3.659 14.93 4.049 14.93 4.699C14.93 5.929 14.07 7.149 12.03 6.539Z" />
                                        </svg>
                                        {t('orders.esim_guide_ios')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setGuideTab('android')}
                                        className={`flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-2 text-xs font-bold transition ${
                                            guideTab === 'android'
                                                ? 'bg-white text-slate-900 shadow-sm'
                                                : 'text-slate-500 hover:text-slate-700'
                                        }`}
                                    >
                                        <Smartphone className="size-3.5" />
                                        {t('orders.esim_guide_android')}
                                    </button>
                                </div>

                                {/* Steps */}
                                {guideTab === 'ios' ? (
                                    <ol className="space-y-3">
                                        {[
                                            t('orders.esim_guide_ios_step1'),
                                            t('orders.esim_guide_ios_step2'),
                                            t('orders.esim_guide_ios_step3'),
                                            t('orders.esim_guide_ios_step4'),
                                        ].map((step, i) => (
                                            <li key={i} className="flex gap-3 text-sm">
                                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-200 text-[11px] font-bold text-slate-600">
                                                    {i + 1}
                                                </span>
                                                <span className="text-slate-700">{step}</span>
                                            </li>
                                        ))}
                                    </ol>
                                ) : (
                                    <ol className="space-y-3">
                                        {[
                                            t('orders.esim_guide_android_step1'),
                                            t('orders.esim_guide_android_step2'),
                                            t('orders.esim_guide_android_step3'),
                                            t('orders.esim_guide_android_step4'),
                                        ].map((step, i) => (
                                            <li key={i} className="flex gap-3 text-sm">
                                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-200 text-[11px] font-bold text-slate-600">
                                                    {i + 1}
                                                </span>
                                                <span className="text-slate-700">{step}</span>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </div>
                        </div>

                        <div className="border-t bg-slate-50/50 px-6 py-4">
                            <Button
                                variant="outline"
                                className="w-full"
                                onClick={() => setActivateOpen(false)}
                            >
                                {t('orders.close')}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : null}
        </>
    );
}

export function OrderItemsSection({ order, canManageItems, canManageInsurance, canManageHotels, onVoid, onRefund, onChangeTicket, onPrintTickets, onInsuranceCancel, onPrintPolicy, onHotelCancel, onPrintHotelVoucher, isInsuranceCancellationApproved, onEsimRefund }) {
    return (
        <div className="space-y-3">
            {(order.items ?? []).map((item) => {
                if (item.type === 'insurance') {
                    return (
                        <InsuranceOrderItemCard
                            key={item.id}
                            item={item}
                            canManage={canManageInsurance}
                            onCancel={onInsuranceCancel}
                            onPrint={onPrintPolicy}
                            isApprovedCancellation={isInsuranceCancellationApproved}
                        />
                    );
                }

                if (item.type === 'hotel' || item.product_type === 'hotel') {
                    return (
                        <HotelOrderItemCard
                            key={item.id}
                            item={item}
                            canManage={canManageHotels}
                            onCancel={onHotelCancel}
                            onPrintVoucher={onPrintHotelVoucher}
                        />
                    );
                }

                if (item.type === 'esim' || item.product_type === 'esim') {
                    return (
                        <ESimOrderItemCard
                            key={item.id}
                            item={item}
                            canManage={canManageItems}
                            onRefund={onEsimRefund}
                        />
                    );
                }

                return (
                    <FlightOrderItemCard
                        key={item.id}
                        item={item}
                        canManage={canManageItems}
                        onVoid={onVoid}
                        onRefund={onRefund}
                        onChangeTicket={onChangeTicket}
                        onPrint={onPrintTickets}
                    />
                );
            })}
        </div>
    );
}
