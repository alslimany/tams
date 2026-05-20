import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import {
    Plane,
    Loader2,
    ChevronRight,
    Info,
    Clock,
    Briefcase,
    User,
    ReceiptText,
    ArrowLeft,
    ArrowRightLeft,
    Search,
    AlertTriangle,
    CheckCircle2,
} from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/Dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { formatMoney, formatMoneyValue } from '@/lib/currency';
import { useTranslation } from '@/hooks/useTranslation';
import { AsyncAirportSelect } from '@/Components/ui/AsyncAirportSelect';
import FlightGroupCard from '@/Components/FlightGroupCard';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * Build the Videcom segment code from a FlightOption.
 * Format: 0{IATA}{fltNo4}{class}{ddMMM}{origin}{dest}{suffix}
 * suffix: QQ1 = open reservation, NN1 = confirmed seat (default)
 * e.g.   0YL0800Y30MayMJIBENNN1  (confirmed)
 *        0YL0800Y30MayMJIBENQQ1  (open reservation)
 */
function buildSegmentCode(flight, reservationType = 'NN') {
    const iata = (flight.airline_code ?? '').toUpperCase();
    const fltNo = String(flight.flight_number ?? '').replace(/\D/g, '').padStart(4, '0');
    const classCode = (flight.segments?.[0]?.class ?? 'Y').toUpperCase().charAt(0);

    // departure_time is "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DD HH:MM"
    const datePart = (flight.departure_time ?? '').split(' ')[0]; // "YYYY-MM-DD"
    const [, mm, dd] = datePart.split('-');
    const dateToken = String(parseInt(dd, 10)).padStart(2, '0') + (MONTHS[parseInt(mm, 10) - 1] ?? 'Jan');

    const origin = (flight.departure_airport ?? '').toUpperCase();
    const dest = (flight.arrival_airport ?? '').toUpperCase();

    // QQ = open/waitlist reservation, NN = confirmed seat
    const suffix = reservationType === 'QQ' ? 'QQ1' : 'NN1';

    return `0${iata}${fltNo}${classCode}${dateToken}${origin}${dest}${suffix}`;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function ChangeOffers({ providers, providerSources = {}, query, uuid, searchDisplayMode, order, item, segment }) {
    const { t, locale } = useTranslation();

    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(true);
    const [providerErrors, setProviderErrors] = useState([]);
    const [activeSearchDate, setActiveSearchDate] = useState(query?.date);

    // Editable search fields
    const [editOrigin, setEditOrigin] = useState(query?.origin ?? '');
    const [editDestination, setEditDestination] = useState(query?.destination ?? '');
    const [editDate, setEditDate] = useState(query?.date ?? '');

    // Reservation type modal state (per_offer mode)
    const [reservationModalFlight, setReservationModalFlight] = useState(null);
    const [openReservationAvailability, setOpenReservationAvailability] = useState({});
    const [openReservationAvailabilityLoading, setOpenReservationAvailabilityLoading] = useState({});

    // Offer summary dialog state (per_flight / grouped mode)
    const [openOfferSummaryKey, setOpenOfferSummaryKey] = useState(null);

    const offersFoundCount = results.length;
    const isOffersLoading = loading;

    const offersIndicatorState = isOffersLoading
        ? 'loading'
        : offersFoundCount > 0
            ? 'loaded'
            : 'empty';

    const offersIndicatorStyles = {
        loading: 'border-primary/30 bg-primary/5 text-primary',
        loaded: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
        empty: 'border-destructive/30 bg-destructive/5 text-destructive',
    };

    const formatDuration = (minutes) => {
        if (!minutes) {
            return 'N/A';
        }

        const h = Math.floor(minutes / 60);
        const m = minutes % 60;

        return `${h > 0 ? `${h}h ` : ''}${m}m`;
    };

    const providerSourcePayload = (providerId) => {
        const source = providerSources?.[providerId] || providerSources?.[String(providerId)] || {};

        return Object.fromEntries(
            Object.entries({
                provider_selector: source.provider_selector,
                provider_source_type: source.source_type,
                source_agency_tenant_id: source.source_agency_tenant_id,
                merchant_tenant_id: source.merchant_tenant_id,
                network_membership_id: source.network_membership_id,
                provider_allocation_id: source.provider_allocation_id,
                source_provider_model: source.source_provider_model,
                source_provider_id: source.source_provider_id,
            }).filter(([, value]) => value !== null && value !== undefined && value !== '')
        );
    };

    const findProviderForFlight = (flight) => {
        if (!flight) {
            return null;
        }

        return providers.find((p) => Number(p.id) === Number(flight.provider_id))
            || providers.find((p) => (flight.airline_name || '').includes(p.airline_name));
    };

    // Per-flight grouping (mirrors SearchResults logic)
    const groupedFlights = useMemo(() => {
        if (searchDisplayMode !== 'per_flight') {
            return [];
        }

        const groups = {};

        results.forEach((flight) => {
            const key = `${flight.airline_code}-${flight.flight_number}-${flight.departure_time}`;

            if (!groups[key]) {
                groups[key] = { ...flight, offers: [] };
            }

            groups[key].offers.push(flight);
        });

        Object.values(groups).forEach((group) => {
            group.offers.sort((a, b) => (a.pricing?.total || 0) - (b.pricing?.total || 0));
        });

        return Object.values(groups);
    }, [results, searchDisplayMode]);

    // For per_flight mode the FlightGroupCard calls handleOfferSelection(offer, providerId, reservationType)
    // The card already shows the offer summary dialog and picks the reservation type — post directly.
    const handleOfferSelectionForChange = (offer, providerId, reservationType) => {
        const segmentCode = buildSegmentCode(offer, reservationType);
        router.post(
            route('tickets.changeReview', { booking: order.id, ticket: item.id }),
            {
                segment_line: segment.line,
                new_segment_code: segmentCode,
                reservation_type: reservationType,
                flight: offer,
            },
        );
    };

    const openReservationKey = (flight, providerId) => {
        const classCode = flight?.pricing?.class_code || flight?.segments?.[0]?.class || flight?.class || 'Y';
        const origin = flight?.departure_airport || flight?.segments?.[0]?.departure_airport || '';
        const destination = flight?.arrival_airport || flight?.segments?.[0]?.arrival_airport || '';
        return [providerId || flight?.provider_id || 'unknown', origin, destination, classCode].join(':');
    };

    const checkOpenReservationAvailability = async (flight, providerId) => {
        const key = openReservationKey(flight, providerId);
        if (openReservationAvailability[key] !== undefined || openReservationAvailabilityLoading[key]) {
            return;
        }
        setOpenReservationAvailabilityLoading((prev) => ({ ...prev, [key]: true }));
        try {
            const provider = providerId
                ? providers.find((p) => Number(p.id) === Number(providerId))
                : findProviderForFlight(flight);
            const response = await axios.post(route('flights.open-reservation-availability'), {
                provider_id: provider?.id,
                flight,
                ...providerSourcePayload(provider?.id),
            });
            setOpenReservationAvailability((prev) => ({ ...prev, [key]: Boolean(response.data?.allowed) }));
        } catch {
            setOpenReservationAvailability((prev) => ({ ...prev, [key]: false }));
        } finally {
            setOpenReservationAvailabilityLoading((prev) => ({ ...prev, [key]: false }));
        }
    };

    // -----------------------------------------------------------------------
    // Fetch offers
    // -----------------------------------------------------------------------
    useEffect(() => {
        setResults([]);
        setLoading(true);
        setProviderErrors([]);
        let completed = 0;

        if (!providers || providers.length === 0) {
            setLoading(false);

            return;
        }

        providers.forEach((provider) => {
            axios.post(route('flights.fetch-flights'), {
                uuid,
                provider_id: provider.id,
                ...providerSourcePayload(provider.id),
            }).then((response) => {
                const newFlights = response.data?.flights || [];

                if (newFlights.length > 0) {
                    setResults((prev) => [...prev, ...newFlights].sort((a, b) => new Date(a.departure_time) - new Date(b.departure_time)));
                }
            }).catch((err) => {
                const message = err?.response?.data?.error || err?.message || t('common.failed_to_load_flights');

                setProviderErrors((prev) => [...prev, { provider: provider.airline_name, message }]);
            }).finally(() => {
                completed++;

                if (completed === providers.length) {
                    setLoading(false);
                }
            });
        });
    }, [uuid, providers]);

    // -----------------------------------------------------------------------
    // Re-search
    // -----------------------------------------------------------------------
    const handleReSearch = () => {
        if (!editOrigin || !editDestination || !editDate) {
            return;
        }

        router.visit(route('flights.change-offers', { booking: order.id, ticket: item.id }), {
            data: {
                segment_line: segment.line,
                origin: editOrigin.toUpperCase(),
                destination: editDestination.toUpperCase(),
                date: editDate,
            },
        });
    };

    // -----------------------------------------------------------------------
    // Phase 2: Select offer → reservation type modal → navigate to review
    // -----------------------------------------------------------------------
    const handleSelectOffer = (flight) => {
        const segmentCode = buildSegmentCode(flight, 'NN'); // placeholder; real type chosen in modal
        setReservationModalFlight({ ...flight, segment_code: segmentCode });
        checkOpenReservationAvailability(flight);
    };

    const handleReservationTypeSelected = (reservationType) => {
        if (!reservationModalFlight) {
            return;
        }
        // Rebuild segment code with the actual reservation type chosen in the modal.
        const segmentCode = buildSegmentCode(reservationModalFlight, reservationType);
        router.post(
            route('tickets.changeReview', { booking: order.id, ticket: item.id }),
            {
                segment_line: segment.line,
                new_segment_code: segmentCode,
                reservation_type: reservationType,
                flight: reservationModalFlight,
            },
        );
    };

    const closeReservationModal = () => {
        setReservationModalFlight(null);
    };

    // -----------------------------------------------------------------------
    // Render helpers
    // -----------------------------------------------------------------------
    const renderFlightDetailsDialog = (flight, triggerLabel = 'Flight Details') => {
        if (!flight) {
            return null;
        }

        return (
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="outline" size="sm" className="h-9 gap-2 rounded-full border-primary/20 px-4 font-bold transition-all hover:bg-primary/5">
                        <Info className="h-4 w-4 text-primary" />
                        {triggerLabel}
                    </Button>
                </DialogTrigger>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="text-2xl font-black">{t('common.flight_information')}</DialogTitle>
                        <DialogDescription className="font-medium">
                            {flight.airline_name} {flight.airline_code}{flight.flight_number}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-6 py-4">
                        {(flight.segments || []).map((seg, idx) => (
                            <div key={idx} className="rounded-2xl border border-dashed border-primary/20 bg-muted/30 p-6">
                                <div className="mb-6 flex justify-between items-start">
                                    <div>
                                        <p className="mb-1 text-xs font-bold uppercase tracking-widest text-primary">{t('common.carrier')}</p>
                                        <p className="text-lg font-black">{flight.airline_name.split(' (')[0]}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="mb-1 text-xs font-bold uppercase tracking-widest text-primary">{t('common.aircraft')}</p>
                                        <p className="text-lg font-black">{seg.aircraft || 'Standard'}</p>
                                    </div>
                                </div>
                                <div className="grid grid-cols-3 items-center gap-4">
                                    <div className="text-left">
                                        <p className="mb-1 text-sm font-bold text-muted-foreground">{t('common.departure')}</p>
                                        <p className="text-2xl font-black">{seg.departure_airport}</p>
                                        <p className="text-xs font-medium text-muted-foreground">{seg.departure_time}</p>
                                    </div>
                                    <div className="flex flex-col items-center gap-2">
                                        <div className="flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-bold uppercase text-primary">
                                            <Clock className="h-3 w-3" />
                                            {formatDuration(seg.duration)}
                                        </div>
                                        <div className="relative h-px w-full bg-primary/20">
                                            <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full border border-primary/20 bg-background p-1">
                                                <Plane className="h-3 w-3 text-primary" />
                                            </div>
                                        </div>
                                        <p className="text-[10px] font-bold uppercase tracking-tighter text-muted-foreground">{t('common.non_stop')}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="mb-1 text-sm font-bold text-muted-foreground">{t('common.arrival')}</p>
                                        <p className="text-2xl font-black">{seg.arrival_airport}</p>
                                        <p className="text-xs font-medium text-muted-foreground">{seg.arrival_time}</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        );
    };

    const renderPriceDetailsDialog = (flight, triggerLabel = 'Price Details') => {
        if (!flight?.pricing) {
            return null;
        }

        return (
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="outline" size="sm" className="h-9 gap-2 rounded-full border-primary/20 px-4 font-bold transition-all hover:bg-primary/5">
                        <ReceiptText className="h-4 w-4 text-primary" />
                        {triggerLabel}
                    </Button>
                </DialogTrigger>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="text-2xl font-black">{t('common.offer_details')}</DialogTitle>
                        <DialogDescription className="font-medium">
                            {t('common.pricing_breakdown_for')} <strong>{flight.pricing.brand_name || t('common.selected_fare')}</strong>
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-8 py-4">
                        {flight.pricing.brand_details ? (
                            <div className="rounded-2xl border-l-4 border-primary bg-primary/5 p-6">
                                <div className="mb-3 flex items-center gap-3">
                                    <Briefcase className="h-5 w-5 text-primary" />
                                    <p className="text-sm font-black uppercase tracking-widest text-primary">{t('common.fare_features')}</p>
                                </div>
                                <p className="whitespace-pre-line text-sm font-medium leading-relaxed text-muted-foreground">
                                    {flight.pricing.brand_details}
                                </p>
                            </div>
                        ) : null}
                        <div>
                            <div className="mb-6 flex items-center gap-3">
                                <ReceiptText className="h-5 w-5 text-primary" />
                                <p className="text-sm font-black uppercase tracking-widest">{t('common.passenger_breakdown')}</p>
                            </div>
                            <div className="overflow-hidden rounded-2xl border bg-muted/10 shadow-sm">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/30">
                                            <TableHead className="font-bold text-foreground">{t('common.type')}</TableHead>
                                            <TableHead className="text-right font-bold text-foreground">{t('common.fare')}</TableHead>
                                            <TableHead className="text-right font-bold text-foreground">{t('common.tax')}</TableHead>
                                            <TableHead className="text-right font-bold text-foreground">{t('common.total')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {(flight.pricing.breakdown || []).map((pax, i) => (
                                            <TableRow key={i}>
                                                <TableCell className="py-4 font-bold">
                                                    <div className="flex items-center gap-2">
                                                        <User className="h-4 w-4 text-muted-foreground" />
                                                        {pax.label}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right font-medium">{formatMoney(pax.fare, flight.pricing.currency)}</TableCell>
                                                <TableCell className="text-right font-medium">{formatMoney(pax.tax, flight.pricing.currency)}</TableCell>
                                                <TableCell className="text-right font-black text-primary">{formatMoney(pax.amount, flight.pricing.currency)}</TableCell>
                                            </TableRow>
                                        ))}
                                        <TableRow className="bg-primary/10 transition-colors hover:bg-primary/20">
                                            <TableCell colSpan={3} className="py-4 text-lg font-black">{t('common.grand_total')}</TableCell>
                                            <TableCell className="text-right text-2xl font-black text-primary">{formatMoney(flight.pricing.total, flight.pricing.currency)}</TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        );
    };

    const renderOfferCard = (flight) => {
        const isSoldOut = Number(flight.available_seats || 0) <= 0;

        return (
            <Card key={flight.id} className="overflow-hidden shadow-sm transition-colors hover:border-primary/50 hover:shadow-md">
                <CardContent className="p-0">
                    <div className="flex flex-col md:flex-row">
                        <div className="flex flex-1 flex-col gap-6 p-6 md:flex-row md:items-center">
                            <div className="flex min-w-37.5 items-center gap-4">
                                <div className="flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-sm bg-primary/5 p-1">
                                    {flight.airline_code ? (
                                        <img
                                            src={route('api.airlines.logo', { code: flight.airline_code, variant: 'icon', radius: 4 })}
                                            alt={flight.airline_name}
                                            className="h-6 w-6 object-contain mix-blend-multiply dark:mix-blend-normal"
                                            onError={(e) => {
                                                e.target.style.display = 'none';
                                                e.target.nextSibling.style.display = 'block';
                                            }}
                                        />
                                    ) : null}
                                    <Plane className="h-5 w-5 text-primary" style={{ display: flight.airline_code ? 'none' : 'block' }} />
                                </div>
                                <div>
                                    <p className="text-lg font-bold">{flight.airline_name}</p>
                                    <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{flight.airline_code}{flight.flight_number}</p>
                                </div>
                            </div>

                            <div className="grid flex-1 grid-cols-2 gap-8 py-2">
                                <div className="border-r pr-4 text-center md:text-left">
                                    <p className="text-2xl font-black">{flight.departure_time.split(' ')[1].substring(0, 5)}</p>
                                    <p className="text-sm font-bold text-muted-foreground">{flight.departure_airport}</p>
                                </div>
                                <div className="text-center md:text-right">
                                    <p className="text-2xl font-black">{flight.arrival_time.split(' ')[1].substring(0, 5)}</p>
                                    <p className="text-sm font-bold text-muted-foreground">{flight.arrival_airport}</p>
                                </div>
                            </div>

                            <div className="flex items-center gap-6 text-muted-foreground">
                                {renderFlightDetailsDialog(flight, t('common.flight_info'))}
                            </div>
                        </div>

                        <div className="flex flex-col items-center justify-center space-y-3 border-t bg-muted/30 p-6 md:w-64 md:border-l md:border-t-0">
                            <div className="text-center">
                                <p className="text-xs font-semibold uppercase text-muted-foreground">{t('common.from')}</p>
                                <p className="text-2xl font-black text-primary">
                                    {formatMoneyValue(flight?.pricing?.total || 0)} <span className="text-sm">{flight?.pricing?.currency || 'LYD'}</span>
                                </p>
                                {renderPriceDetailsDialog(flight, t('common.view_price_details'))}
                            </div>
                            <Button
                                className="w-full font-bold shadow-sm"
                                size="lg"
                                disabled={isSoldOut}
                                onClick={() => handleSelectOffer(flight)}
                            >
                                {isSoldOut ? t('common.sold_out') : t('orders.change_select_offer')}
                                {!isSoldOut ? <ChevronRight className="ml-2 h-4 w-4" /> : null}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        );
    };

    // -----------------------------------------------------------------------
    // Reservation type modal (mirrors SearchResults offer summary dialog)
    // -----------------------------------------------------------------------
    const renderReservationModal = () => {
        const flight = reservationModalFlight;

        if (!flight) {
            return null;
        }

        const key = openReservationKey(flight);
        const openAllowed = openReservationAvailability[key];
        const openLoading = openReservationAvailabilityLoading[key];

        return (
            <Dialog open={Boolean(reservationModalFlight)} onOpenChange={(open) => { if (!open) { closeReservationModal(); } }}>
                <DialogContent className="max-w-2xl overflow-hidden rounded-3xl p-0 sm:rounded-3xl">
                    <div className="border-b bg-primary/5 p-6">
                        <DialogTitle className="text-2xl font-black leading-tight tracking-normal">{t('common.offer_summary')}</DialogTitle>
                        <DialogDescription className="mt-2 text-sm font-medium text-muted-foreground">{t('common.review_selected_flight')}</DialogDescription>
                    </div>

                    <div className="max-h-[60vh] space-y-6 overflow-y-auto p-6">
                        {/* Flight summary */}
                        <div className="flex items-center justify-between rounded-2xl border bg-card p-4 shadow-sm">
                            <div>
                                <p className="text-sm font-bold text-muted-foreground">{t('common.itinerary_fare')}</p>
                                <p className="text-xl font-black">
                                    {flight.departure_airport} <ArrowRightLeft className="mx-1 inline h-4 w-4 shrink-0" /> {flight.arrival_airport}
                                </p>
                                <p className="text-sm font-medium">{flight.airline_name} · {flight.airline_code}{flight.flight_number}</p>
                            </div>
                            <div className="text-right">
                                <p className="text-sm font-bold text-muted-foreground">{t('common.grand_total')}</p>
                                <p className="text-2xl font-black text-primary">
                                    {formatMoneyValue(flight.pricing?.total || 0)} <span className="text-sm">{flight.pricing?.currency || 'LYD'}</span>
                                </p>
                            </div>
                        </div>

                        {/* Segments */}
                        <div>
                            <p className="mb-3 text-xs font-bold uppercase tracking-widest text-muted-foreground">{t('common.flight_segments')}</p>
                            <div className="space-y-3">
                                {(flight.segments || []).map((seg, i) => (
                                    <div key={i} className="flex items-center gap-4 rounded-xl border bg-muted/10 p-4">
                                        <Plane className="h-5 w-5 text-primary" />
                                        <div className="flex-1">
                                            <div className="flex justify-between text-sm font-black">
                                                <span>{seg.departure_airport} ({(seg.departure_time || '').split(' ')[1]?.substring(0, 5)})</span>
                                                <span>{seg.arrival_airport} ({(seg.arrival_time || '').split(' ')[1]?.substring(0, 5)})</span>
                                            </div>
                                            <p className="mt-1 text-xs font-medium text-muted-foreground">
                                                {seg.aircraft || t('common.standard')}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Reservation type buttons */}
                    <div className="flex flex-col gap-3 border-t bg-muted/30 p-6 sm:flex-row">
                        {openLoading ? (
                            <Button variant="outline" size="lg" className="flex-1 rounded-full px-4 text-xs font-bold shadow-sm" disabled>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                {t('common.checking_open_reservation')}
                            </Button>
                        ) : openAllowed ? (
                            <Button
                                variant="outline"
                                size="lg"
                                className="flex-1 rounded-full px-4 text-xs font-bold shadow-sm"
                                onClick={() => handleReservationTypeSelected('QQ')}
                            >
                                {t('common.open_reservation')}
                            </Button>
                        ) : null}
                        <Button
                            size="lg"
                            className="flex-1 rounded-full px-4 text-xs font-bold shadow-md"
                            onClick={() => handleReservationTypeSelected('NN')}
                        >
                            {t('common.confirmed_reservation')}
                            <ChevronRight className="ml-2 h-4 w-4" />
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        );
    };

    // -----------------------------------------------------------------------
    // Page
    // -----------------------------------------------------------------------
    return (
        <TenantNavbarLayout>
            <Head title={t('orders.change_offers_title', { origin: segment.origin, destination: segment.destination })} />

            <div className="mx-auto max-w-7xl px-4 py-8">
                {/* Back link */}
                <div className="mb-6">
                    <Link
                        href={route('orders.show', { order: order.id })}
                        className="inline-flex items-center gap-2 text-sm font-semibold text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        {t('orders.back_to_order', { number: order.number })}
                    </Link>
                </div>

                {/* Context banner */}
                <div className="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2 text-sm text-sky-900">
                        <ArrowRightLeft className="h-4 w-4 shrink-0" />
                        <span className="font-bold">{t('orders.change_ticket')}</span>
                        <span className="text-sky-600">·</span>
                        <span>{t('orders.pnr')}: <strong>{item.provider_reference ?? item.ticket_number ?? '-'}</strong></span>
                        <span className="text-sky-600">·</span>
                        <span>{t('orders.change_segment_line')}: <strong>{segment.line}</strong></span>
                        <span className="text-sky-600">·</span>
                        <span>{segment.origin} → {segment.destination}</span>
                    </div>
                </div>

                {/* Editable search form */}
                <div className="mb-8 rounded-xl border bg-card p-4 shadow-sm">
                    <p className="mb-3 text-xs font-bold uppercase tracking-widest text-muted-foreground">{t('orders.change_adjust_search')}</p>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="space-y-1">
                            <label className="text-xs font-bold uppercase text-muted-foreground">{t('common.origin')}</label>
                            <AsyncAirportSelect
                                value={editOrigin}
                                onChange={(e) => setEditOrigin(e.target.value)}
                                placeholder={t('common.origin')}
                                className="w-56"
                            />
                        </div>
                        <div className="space-y-1">
                            <label className="text-xs font-bold uppercase text-muted-foreground">{t('common.destination')}</label>
                            <AsyncAirportSelect
                                value={editDestination}
                                onChange={(e) => setEditDestination(e.target.value)}
                                placeholder={t('common.destination')}
                                className="w-56"
                                isDestination
                            />
                        </div>
                        <div className="space-y-1">
                            <label className="text-xs font-bold uppercase text-muted-foreground">{t('common.date')}</label>
                            <input
                                type="date"
                                value={editDate}
                                onChange={(e) => setEditDate(e.target.value)}
                                className="h-9 rounded-md border bg-background px-3 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                        <Button
                            type="button"
                            onClick={handleReSearch}
                            disabled={!editOrigin || !editDestination || !editDate}
                            className="h-9 gap-2"
                        >
                            <Search className="h-4 w-4" />
                            {t('common.search')}
                        </Button>
                    </div>
                </div>

                {/* Header */}
                <div className="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h2 className="flex items-center gap-3 text-4xl font-black tracking-tight">
                            {query.origin} <ChevronRight className="h-8 w-8 text-muted-foreground/30" /> {query.destination}
                        </h2>
                        <p className="mt-1 font-medium text-muted-foreground">
                            {new Date(activeSearchDate).toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}
                        </p>
                    </div>

                    {/* Date strip */}
                    <div className="mt-4 flex w-full overflow-hidden rounded-xl border bg-card shadow-sm md:mt-0 md:w-auto">
                        {[-3, -2, -1, 0, 1, 2, 3].map((offset) => {
                            const date = new Date(activeSearchDate);
                            date.setDate(date.getDate() + offset);
                            const isSelected = offset === 0;
                            const isPast = date < new Date(new Date().setHours(0, 0, 0, 0));
                            const localDateStr = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().split('T')[0];
                            const dayName = date.toLocaleDateString(locale, { weekday: 'short' });
                            const dayNum = date.getDate();
                            const monthName = date.toLocaleDateString(locale, { month: 'short' });

                            if (isPast && !isSelected) {
                                return (
                                    <div key={offset} className="min-w-15 flex-1 cursor-not-allowed border-r py-2 text-center opacity-40 last:border-r-0 md:min-w-20">
                                        <div className="text-[10px] font-bold uppercase text-muted-foreground">{dayName}</div>
                                        <div className="text-sm font-black text-muted-foreground md:text-base">{monthName} {dayNum}</div>
                                    </div>
                                );
                            }

                            return (
                                <button
                                    key={offset}
                                    type="button"
                                    className={`min-w-15 flex-1 border-r px-1 py-2 text-center transition-colors last:border-r-0 focus:outline-none hover:bg-primary/5 md:min-w-20 md:px-4 ${isSelected ? 'bg-primary text-primary-foreground hover:bg-primary' : 'bg-transparent text-foreground'}`}
                                    onClick={() => {
                                        router.visit(route('flights.change-offers', { booking: order.id, ticket: item.id }), {
                                            data: {
                                                segment_line: segment.line,
                                                origin: query.origin,
                                                destination: query.destination,
                                                date: localDateStr,
                                            },
                                        });
                                    }}
                                >
                                    <div className={`text-[10px] font-bold uppercase ${isSelected ? 'text-primary-foreground/80' : 'text-muted-foreground'}`}>{dayName}</div>
                                    <div className="whitespace-nowrap text-sm font-black md:text-base">{monthName} {dayNum}</div>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Offers indicator */}
                <div className={`mb-6 rounded-xl border px-4 py-3 ${offersIndicatorStyles[offersIndicatorState]}`}>
                    <div className="flex items-center gap-3">
                        {isOffersLoading ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Plane className="h-4 w-4" />
                        )}
                        <p className="text-sm font-bold">
                            {isOffersLoading
                                ? t('common.loading_flight_offers')
                                : offersFoundCount > 0
                                    ? t(offersFoundCount === 1 ? 'common.flight_offer_found' : 'common.flight_offers_found', { count: offersFoundCount })
                                    : t('common.no_flight_offers_found')}
                        </p>
                    </div>
                </div>

                {/* Provider errors */}
                {providerErrors.length > 0 ? (
                    <div className="mb-6 rounded-2xl border border-destructive/40 bg-destructive/5 p-4">
                        <p className="text-sm font-bold text-destructive">
                            {t('common.some_airlines_failed')}
                        </p>
                        <p className="mt-1 text-xs text-destructive/90">
                            {providerErrors.map((entry) => `${entry.provider}: ${entry.message}`).join(' | ')}
                        </p>
                    </div>
                ) : null}

                {/* Offer list */}
                <div className="space-y-4">
                    {results.length > 0
                        ? searchDisplayMode === 'per_flight'
                            ? groupedFlights.map((flightGroup) => (
                                <FlightGroupCard
                                    key={`${flightGroup.airline_code}-${flightGroup.flight_number}-${flightGroup.departure_time}`}
                                    flightGroup={flightGroup}
                                    providers={providers}
                                    openReservationAvailability={openReservationAvailability}
                                    openReservationAvailabilityLoading={openReservationAvailabilityLoading}
                                    openOfferSummaryKey={openOfferSummaryKey}
                                    setOpenOfferSummaryKey={setOpenOfferSummaryKey}
                                    checkOpenReservationAvailability={checkOpenReservationAvailability}
                                    handleOfferSelection={handleOfferSelectionForChange}
                                />
                            ))
                            : results.map(renderOfferCard)
                        : !loading && (
                            <div className="rounded-3xl border bg-card py-24 text-center shadow-sm">
                                <Plane className="mx-auto mb-4 h-16 w-16 text-muted-foreground/20" />
                                <h3 className="text-xl font-bold">{t('common.no_flights_found')}</h3>
                                <p className="mx-auto max-w-xs text-muted-foreground">
                                    {t('common.no_flights_found_description')}
                                </p>
                            </div>
                        )}
                </div>
            </div>

            {/* Reservation type modal */}
            {renderReservationModal()}
        </TenantNavbarLayout>
    );
}
