import React, { useEffect, useState, useMemo } from 'react';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Button } from "@/Components/ui/Button";
import { Card, CardContent } from "@/Components/ui/Card";
import { Badge } from "@/Components/ui/Badge";
import { Plane, Loader2, ChevronRight, Info, Clock, Briefcase, User, ReceiptText, ArrowRightLeft, ChevronDown, ChevronUp } from "lucide-react";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/Dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/Table";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/Components/ui/Tabs";
import { formatMoney, formatMoneyValue } from '@/lib/currency';
import FlightGroupCard from '@/Components/FlightGroupCard';
import { useTranslation } from '@/hooks/useTranslation';

export default function SearchResults({ providers, providerSources = {}, query, uuid, searchDisplayMode }) {
    const { t, loading: translationsLoading, locale } = useTranslation();

    const isRoundTripSearch = Boolean(query?.is_return);
    const initialActiveSearchDate = query?.date;
    const initialReturnDate = query?.return_date || query?.date;
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(true);
    const [providerErrors, setProviderErrors] = useState([]);
    const [openReservationAvailability, setOpenReservationAvailability] = useState({});
    const [openReservationAvailabilityLoading, setOpenReservationAvailabilityLoading] = useState({});
    const [selectedOutboundFlight, setSelectedOutboundFlight] = useState(null);
    const [selectedOutboundProviderId, setSelectedOutboundProviderId] = useState(null);
    const [selectedOutboundReservationType, setSelectedOutboundReservationType] = useState('NN');
    const [returnOptions, setReturnOptions] = useState([]);
    const [loadingReturnOptions, setLoadingReturnOptions] = useState(false);
    const [selectedReturnFlight, setSelectedReturnFlight] = useState(null);
    const [selectedReturnProviderId, setSelectedReturnProviderId] = useState(null);
    const [selectedOneWayFlight, setSelectedOneWayFlight] = useState(null);
    const [selectedOneWayProviderId, setSelectedOneWayProviderId] = useState(null);
    const [selectedOneWayReservationType, setSelectedOneWayReservationType] = useState('NN');
    const [openOfferSummaryKey, setOpenOfferSummaryKey] = useState(null);
    const [activeSearchDate, setActiveSearchDate] = useState(initialActiveSearchDate);
    const [activeReturnDate, setActiveReturnDate] = useState(initialReturnDate);

    useEffect(() => {
        setActiveSearchDate(query?.date);
    }, [query?.date]);

    useEffect(() => {
        setActiveReturnDate(initialReturnDate);
    }, [initialReturnDate]);

    const formatDuration = (minutes) => {
        if (!minutes) return "N/A";
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return `${h > 0 ? `${h}h ` : ""}${m}m`;
    };

    const openReservationKey = (flight, providerId) => {
        const classCode = flight?.pricing?.class_code || flight?.segments?.[0]?.class || flight?.class || 'Y';
        const origin = flight?.departure_airport || flight?.segments?.[0]?.departure_airport || '';
        const destination = flight?.arrival_airport || flight?.segments?.[0]?.arrival_airport || '';

        return [providerId || 'unknown', origin, destination, classCode].join(':');
    };

    const offerSummaryKey = (flight, providerId) => {
        const flightIdentifier = flight?.id || `${flight?.airline_code || 'XX'}-${flight?.flight_number || '000'}-${flight?.departure_time || 'time'}`;

        return [providerId || 'unknown', flightIdentifier].join(':');
    };

    const checkOpenReservationAvailability = async (flight, providerId) => {
        const key = openReservationKey(flight, providerId);

        if (!providerId || openReservationAvailability[key] !== undefined || openReservationAvailabilityLoading[key]) {
            return;
        }

        setOpenReservationAvailabilityLoading((prev) => ({ ...prev, [key]: true }));

        const payload = {
            provider_id: providerId,
            flight,
        };

        try {
            const response = await axios.post(route('flights.open-reservation-availability'), payload);

            setOpenReservationAvailability((prev) => ({
                ...prev,
                [key]: Boolean(response.data?.allowed),
            }));
        } catch (error) {
            if (error?.response?.status === 419) {
                try {
                    await axios.get(route('sanctum.csrf-cookie'));
                    const retryResponse = await axios.post(route('flights.open-reservation-availability'), payload);

                    setOpenReservationAvailability((prev) => ({
                        ...prev,
                        [key]: Boolean(retryResponse.data?.allowed),
                    }));

                    return;
                } catch (_retryError) {
                    // Fall through to mark as unavailable.
                }
            }

            setOpenReservationAvailability((prev) => ({
                ...prev,
                [key]: false,
            }));
        } finally {
            setOpenReservationAvailabilityLoading((prev) => ({ ...prev, [key]: false }));
        }
    };

    useEffect(() => {
        setResults([]);
        setLoading(true);
        setProviderErrors([]);
        let completed = 0;

        if (!providers || providers.length === 0) {
            setLoading(false);
            return;
        }

        providers.forEach(provider => {
            axios.post(route('flights.fetch-flights'), {
                uuid: uuid,
                provider_id: provider.id
            }).then(response => {
                const newFlights = response.data?.flights || [];

                if (newFlights.length > 0) {
                    setResults(prev => {
                        return [...prev, ...newFlights].sort((a, b) => {
                            const timeA = new Date(a.departure_time);
                            const timeB = new Date(b.departure_time);
                            return timeA - timeB;
                        });
                    });
                }
            }).catch(err => {
                const message = err?.response?.data?.error || err?.message || t('common.failed_to_load_flights');

                setProviderErrors((prev) => ([
                    ...prev,
                    {
                        provider: provider.airline_name,
                        message,
                    },
                ]));

                console.error(`Failed to load flights for ${provider.airline_name}`);
            }).finally(() => {
                completed++;
                if (completed === providers.length) {
                    setLoading(false);
                }
            });
        });
    }, [uuid, providers]);

    const activeResults = selectedOutboundFlight ? returnOptions : results;
    const isSelectingReturnStep = Boolean(selectedOutboundFlight);
    const activeOrigin = isSelectingReturnStep
        ? (selectedOutboundFlight?.arrival_airport || query.origin)
        : query.origin;
    const activeDestination = isSelectingReturnStep
        ? (selectedOutboundFlight?.departure_airport || query.destination)
        : query.destination;
    const activeDate = isSelectingReturnStep ? activeReturnDate : activeSearchDate;
    const isOffersLoading = isSelectingReturnStep ? loadingReturnOptions : loading;
    const offersFoundCount = activeResults.length;
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

    const findProviderForFlight = (flight) => {
        if (!flight) {
            return null;
        }

        return providers.find((p) => Number(p.id) === Number(flight.provider_id))
            || providers.find((p) => (flight.airline_name || '').includes(p.airline_name));
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

    const buildRoundTripFlightPayload = (outboundFlight, returnFlight) => {
        const outboundSegments = outboundFlight?.segments || [outboundFlight];
        const returnSegments = returnFlight?.segments || [returnFlight];
        const outboundTotal = Number(outboundFlight?.pricing?.total || 0);
        const returnTotal = Number(returnFlight?.pricing?.total || 0);

        return {
            ...outboundFlight,
            segments: [...outboundSegments, ...returnSegments],
            pricing: {
                ...(outboundFlight?.pricing || {}),
                total: outboundTotal + returnTotal,
                currency: outboundFlight?.pricing?.currency || returnFlight?.pricing?.currency || 'LYD',
                method: returnFlight?.pricing_method || 'oneway',
            },
            round_trip: {
                outbound_flight: outboundFlight,
                return_flight: returnFlight,
            },
        };
    };

    const proceedToPassengerInfo = (flight, providerId, reservationType, extraPayload = {}) => {
        router.post(route('flights.select'), {
            uuid,
            provider_id: providerId,
            reservation_type: reservationType,
            flight,
            ...providerSourcePayload(providerId),
            ...extraPayload,
        });
    };

    const loadReturnOptions = async (flight, providerId, reservationType, returnDate, forceRefresh = false) => {
        if (!flight || !providerId) {
            setProviderErrors((prev) => [
                ...prev,
                {
                    provider: 'Round-trip pricing',
                    message: 'Could not determine the outbound flight provider for return search.',
                },
            ]);

            return;
        }

        setActiveReturnDate(returnDate);
        setReturnOptions([]);
        setSelectedReturnFlight(null);
        setSelectedReturnProviderId(null);
        setLoadingReturnOptions(true);

        try {
            const response = await axios.post(route('flights.return-options'), {
                uuid,
                outbound_provider_id: providerId,
                outbound_flight: flight,
                reservation_type: reservationType,
                return_date: returnDate,
                force_refresh: forceRefresh,
            });

            setReturnOptions(response.data?.return_options || []);
            setActiveReturnDate(response.data?.return_date || returnDate);
        } catch (error) {
            const message = error?.response?.data?.error || t('common.failed_to_load_return_flights');
            setProviderErrors((prev) => [
                ...prev,
                {
                    provider: 'Round-trip pricing',
                    message,
                },
            ]);
        } finally {
            setLoadingReturnOptions(false);
        }
    };

    const handleOfferSelection = async (flight, providerId, reservationType) => {
        if (!providerId) {
            return;
        }

        setOpenOfferSummaryKey(null);

        if (!isRoundTripSearch) {
            setSelectedOneWayFlight(flight);
            setSelectedOneWayProviderId(providerId);
            setSelectedOneWayReservationType(reservationType);

            return;
        }

        if (!selectedOutboundFlight) {
            setSelectedOutboundFlight(flight);
            setSelectedOutboundProviderId(providerId);
            setSelectedOutboundReservationType(reservationType);
            await loadReturnOptions(flight, providerId, reservationType, activeReturnDate || initialReturnDate);

            return;
        }

        setSelectedReturnFlight(flight);
        setSelectedReturnProviderId(providerId);
    };

    const continueOneWay = () => {
        if (!selectedOneWayFlight || !selectedOneWayProviderId) {
            return;
        }

        proceedToPassengerInfo(selectedOneWayFlight, selectedOneWayProviderId, selectedOneWayReservationType);
    };

    const resetOneWaySelection = () => {
        setSelectedOneWayFlight(null);
        setSelectedOneWayProviderId(null);
        setSelectedOneWayReservationType('NN');
    };

    const continueRoundTrip = () => {
        if (!selectedOutboundFlight || !selectedReturnFlight || !selectedOutboundProviderId || !selectedReturnProviderId) {
            return;
        }

        const combinedFlight = buildRoundTripFlightPayload(selectedOutboundFlight, selectedReturnFlight);

        proceedToPassengerInfo(
            combinedFlight,
            selectedOutboundProviderId,
            selectedOutboundReservationType,
            {
                is_round_trip: true,
                outbound_provider_id: selectedOutboundProviderId,
                return_provider_id: selectedReturnProviderId,
            },
        );
    };

    // Grouping logic for "Per Flight" mode
    const groupedFlights = useMemo(() => {
        if (searchDisplayMode !== 'per_flight') return [];

        const groups = {};
        activeResults.forEach(flight => {
            // Uniquely identify a flight by airline, number, and times
            const key = `${flight.airline_code}-${flight.flight_number}-${flight.departure_time}`;
            if (!groups[key]) {
                groups[key] = {
                    ...flight,
                    offers: []
                };
            }
            groups[key].offers.push(flight);
        });

        // Sort offers within each flight by price
        Object.values(groups).forEach(group => {
            group.offers.sort((a, b) => (a.pricing?.total || 0) - (b.pricing?.total || 0));
        });

        return Object.values(groups);
    }, [activeResults, searchDisplayMode]);

    const selectedRoundTripTotal = Number(selectedOutboundFlight?.pricing?.total || 0) + Number(selectedReturnFlight?.pricing?.total || 0);
    const selectedRoundTripCurrency = selectedOutboundFlight?.pricing?.currency || selectedReturnFlight?.pricing?.currency || 'LYD';
    const selectedOneWayTotal = Number(selectedOneWayFlight?.pricing?.total || 0);
    const selectedOneWayCurrency = selectedOneWayFlight?.pricing?.currency || 'LYD';

    const bestOffer = activeResults.length > 0 ? activeResults.reduce((cheapest, flight) => {
        const currentPrice = flight?.pricing?.total || 0;
        const cheapestPrice = cheapest?.pricing?.total || 0;
        return currentPrice < cheapestPrice ? flight : cheapest;
    }) : null;

    const renderFlightDetailsDialog = (flight, triggerLabel = 'Flight Details') => {
        if (!flight) {
            return null;
        }

        return (
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="outline" size="sm" className="h-9 gap-2 font-bold px-4 rounded-full border-primary/20 hover:bg-primary/5 transition-all">
                        <Info className="h-4 w-4 text-primary" />
                        {triggerLabel}
                    </Button>
                </DialogTrigger>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="text-2xl font-black">{t('common.flight_information')}</DialogTitle>
                        <DialogDescription className="font-medium">
                            Detailed itinerary for {flight.airline_name} {flight.airline_code}{flight.flight_number}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-6 py-4">
                        {(flight.segments || []).map((segment, idx) => (
                            <div key={idx} className="bg-muted/30 rounded-2xl p-6 border border-dashed border-primary/20">
                                <div className="flex justify-between items-start mb-6">
                                    <div>
                                        <p className="text-xs font-bold uppercase tracking-widest text-primary mb-1">{t('common.carrier')}</p>
                                        <p className="text-lg font-black">{flight.airline_name.split(' (')[0]}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xs font-bold uppercase tracking-widest text-primary mb-1">{t('common.aircraft')}</p>
                                        <p className="text-lg font-black">{segment.aircraft || 'Standard'}</p>
                                    </div>
                                </div>
                                <div className="grid grid-cols-3 items-center gap-4">
                                    <div className="text-left">
                                        <p className="text-sm font-bold text-muted-foreground mb-1">{t('common.departure')}</p>
                                        <p className="text-2xl font-black">{segment.departure_airport}</p>
                                        <p className="text-xs font-medium text-muted-foreground">{segment.departure_time}</p>
                                    </div>
                                    <div className="flex flex-col items-center gap-2">
                                        <div className="flex items-center gap-2 text-primary font-bold text-xs uppercase bg-primary/10 px-3 py-1 rounded-full">
                                            <Clock className="h-3 w-3" />
                                            {formatDuration(segment.duration)}
                                        </div>
                                        <div className="w-full h-px bg-primary/20 relative">
                                            <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-background p-1 rounded-full border border-primary/20">
                                                <Plane className="h-3 w-3 text-primary" />
                                            </div>
                                        </div>
                                        <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-tighter">{t('common.non_stop')}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-sm font-bold text-muted-foreground mb-1">{t('common.arrival')}</p>
                                        <p className="text-2xl font-black">{segment.arrival_airport}</p>
                                        <p className="text-xs font-medium text-muted-foreground">{segment.arrival_time}</p>
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
                    <Button variant="outline" size="sm" className="h-9 gap-2 font-bold px-4 rounded-full border-primary/20 hover:bg-primary/5 transition-all">
                        <ReceiptText className="h-4 w-4 text-primary" />
                        {triggerLabel}
                    </Button>
                </DialogTrigger>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="text-2xl font-black">{t('common.offer_details')}</DialogTitle>
                        <DialogDescription className="font-medium">
                            Pricing breakdown and fare conditions for <strong>{flight.pricing.brand_name || 'Selected fare'}</strong>
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-8 py-4">
                        {flight.pricing.brand_details && (
                            <div className="bg-primary/5 rounded-2xl p-6 border-l-4 border-primary">
                                <div className="flex items-center gap-3 mb-3">
                                    <Briefcase className="h-5 w-5 text-primary" />
                                    <p className="text-sm font-black uppercase tracking-widest text-primary">{t('common.fare_features')}</p>
                                </div>
                                <p className="text-sm font-medium leading-relaxed whitespace-pre-line text-muted-foreground">
                                    {flight.pricing.brand_details}
                                </p>
                            </div>
                        )}

                        <div>
                            <div className="flex items-center gap-3 mb-6">
                                <ReceiptText className="h-5 w-5 text-primary" />
                                <p className="text-sm font-black uppercase tracking-widest">{t('common.passenger_breakdown')}</p>
                            </div>
                            <div className="border rounded-2xl overflow-hidden bg-muted/10 shadow-sm">
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
                                                <TableCell className="font-bold py-4">
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
                                        <TableRow className="bg-primary/10 hover:bg-primary/20 transition-colors">
                                            <TableCell colSpan={3} className="font-black text-lg py-4">{t('common.grand_total')}</TableCell>
                                            <TableCell className="text-right font-black text-2xl text-primary">{formatMoney(flight.pricing.total, flight.pricing.currency)}</TableCell>
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

    const renderSelectedOneWaySummary = () => {
        if (!selectedOneWayFlight) {
            return null;
        }

        return (
            <div className="rounded-2xl border bg-muted/10 p-4">
                <p className="text-sm font-bold text-foreground">{t('common.selected_offer')}</p>
                <div className="mt-3 space-y-3">
                    <div className="rounded-xl border bg-background/70 p-3">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <img src={route('api.airlines.logo', { code: selectedOneWayFlight.airline_code, variant: 'icon', radius: 4 })} alt={selectedOneWayFlight.airline_name} className="h-7 w-7 object-contain" />
                                <div>
                                    <p className="text-sm font-bold">{selectedOneWayFlight.departure_airport} → {selectedOneWayFlight.arrival_airport}</p>
                                    <p className="text-xs text-muted-foreground">{selectedOneWayFlight.airline_code}{selectedOneWayFlight.flight_number} • {selectedOneWayFlight.pricing?.brand_name || 'Standard'}</p>
                                </div>
                            </div>
                            <p className="text-sm font-black text-primary">{formatMoney(selectedOneWayFlight?.pricing?.total || 0, selectedOneWayFlight?.pricing?.currency || 'LYD')}</p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {renderFlightDetailsDialog(selectedOneWayFlight, 'Show Flight Details')}
                        {renderPriceDetailsDialog(selectedOneWayFlight, 'Show Price Details')}
                        <Button type="button" variant="outline" size="sm" onClick={resetOneWaySelection}>
                            Change offer
                        </Button>
                    </div>
                </div>
            </div>
        );
    };

    const renderOfferCard = (flight) => {
        const provider = findProviderForFlight(flight);
        const isSoldOut = Number(flight.available_seats || 0) <= 0;
        const openReservationStatusKey = openReservationKey(flight, provider?.id);
        const openReservationAllowed = Boolean(openReservationAvailability[openReservationStatusKey]);
        const openReservationLoadingState = Boolean(openReservationAvailabilityLoading[openReservationStatusKey]);

        return (
            <Card key={flight.id} className="overflow-hidden hover:border-primary/50 transition-colors shadow-sm hover:shadow-md">
                <CardContent className="p-0">
                    <div className="flex flex-col md:flex-row">
                        <div className="p-6 flex-1 flex flex-col md:flex-row md:items-center gap-6">
                            <div className="flex gap-4 items-center min-w-37.5">
                                <div className="bg-primary/5 p-1 rounded-sm shrink-0 flex items-center justify-center min-w-8 min-h-8">
                                    {flight.airline_code ? (
                                        <img src={route('api.airlines.logo', { code: flight.airline_code, variant: 'icon', radius: 4 })} alt={flight.airline_name} className="h-6 w-6 object-contain mix-blend-multiply dark:mix-blend-normal" onError={(e) => { e.target.style.display = 'none'; e.target.nextSibling.style.display = 'block'; }} />
                                    ) : null}
                                    <Plane className="h-5 w-5 text-primary" style={{ display: flight.airline_code ? 'none' : 'block' }} />
                                </div>
                                <div>
                                    <p className="font-bold text-lg">{flight.airline_name}</p>
                                    <p className="text-xs text-muted-foreground font-medium uppercase tracking-wider">{flight.airline_code}{flight.flight_number}</p>
                                </div>
                            </div>

                            <div className="flex-1 grid grid-cols-2 gap-8 py-2">
                                <div className="text-center md:text-left border-r pr-4">
                                    <p className="text-2xl font-black">{flight.departure_time.split(' ')[1].substring(0, 5)}</p>
                                    <p className="text-sm font-bold text-muted-foreground">{flight.departure_airport}</p>
                                </div>
                                <div className="text-center md:text-right">
                                    <p className="text-2xl font-black">{flight.arrival_time.split(' ')[1].substring(0, 5)}</p>
                                    <p className="text-sm font-bold text-muted-foreground">{flight.arrival_airport}</p>
                                </div>
                            </div>

                            <div className="flex items-center gap-6 text-muted-foreground">
                                {renderFlightDetailsDialog(flight, 'Flight Info')}
                            </div>
                        </div>

                        <div className="bg-muted/30 md:w-64 p-6 flex flex-col items-center justify-center border-t md:border-t-0 md:border-l space-y-3">
                            <div className="text-center">
                                <p className="text-xs text-muted-foreground font-semibold uppercase">{t('common.from')}</p>
                                <p className="text-2xl font-black text-primary">
                                    {formatMoneyValue(flight?.pricing?.total || 0)} <span className="text-sm">{flight?.pricing?.currency || 'LYD'}</span>
                                </p>
                                {renderPriceDetailsDialog(flight, 'View Price Details')}
                            </div>
                            <Dialog
                                open={openOfferSummaryKey === offerSummaryKey(flight, provider?.id)}
                                onOpenChange={(open) => {
                                    setOpenOfferSummaryKey(open ? offerSummaryKey(flight, provider?.id) : null);

                                if (open) {
                                    checkOpenReservationAvailability(flight, provider?.id);
                                }
                            }}>
                                <DialogTrigger asChild>
                                    <Button className="w-full font-bold shadow-sm" size="lg" disabled={isSoldOut}>
                                        {isSoldOut ? 'Sold Out' : 'Select Flight'}
                                        {!isSoldOut && <ChevronRight className="ml-2 h-4 w-4" />}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="max-w-2xl sm:rounded-3xl p-0 overflow-hidden">
                                    <div className="bg-primary/5 p-6 border-b">
                                        <DialogTitle className="text-2xl font-black tracking-normal leading-tight">{t('common.offer_summary')}</DialogTitle>
                                        <DialogDescription className="text-muted-foreground font-medium text-sm mt-2">{t('common.review_selected_flight')}</DialogDescription>
                                    </div>
                                    <div className="p-6 space-y-6 max-h-[60vh] overflow-y-auto">
                                        <div className="flex justify-between items-center bg-card border rounded-2xl p-4 shadow-sm">
                                            <div>
                                                <p className="text-sm font-bold text-muted-foreground">{t('common.itinerary_fare')}</p>
                                                <p className="text-xl font-black">{flight.departure_airport} <ArrowRightLeft className="inline shrink-0 h-4 w-4 mx-1" /> {flight.arrival_airport}</p>
                                                <p className="text-sm font-medium">{flight.airline_name} • {flight.airline_code}{flight.flight_number}</p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-bold text-muted-foreground">{t('common.grand_total')}</p>
                                                <p className="text-2xl font-black text-primary">{formatMoneyValue(flight.pricing.total)} <span className="text-sm">{flight.pricing.currency}</span></p>
                                            </div>
                                        </div>

                                        <div>
                                            <p className="font-bold mb-3 uppercase tracking-widest text-xs text-muted-foreground">{t('common.flight_segments')}</p>
                                            <div className="space-y-3">
                                                {flight.segments.map((seg, i) => (
                                                    <div key={i} className="flex gap-4 p-4 border rounded-xl bg-muted/10 items-center">
                                                        <Plane className="h-5 w-5 text-primary" />
                                                        <div className="flex-1">
                                                            <div className="flex justify-between font-black text-sm">
                                                                <span>{seg.departure_airport} ({seg.departure_time.split(' ')[1].substring(0, 5)})</span>
                                                                <span>{seg.arrival_airport} ({seg.arrival_time.split(' ')[1].substring(0, 5)})</span>
                                                            </div>
                                                            <p className="text-xs text-muted-foreground font-medium mt-1">{t('common.duration')}: {formatDuration(seg.duration)} • {seg.aircraft || t('common.standard')}</p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="p-6 border-t bg-muted/30 flex flex-col sm:flex-row gap-3">
                                        {openReservationAllowed ? (
                                            <div className="flex-1">
                                                <Button variant="outline" size="lg" className="w-full font-bold shadow-sm rounded-full px-4 text-xs" onClick={() => handleOfferSelection(flight, provider?.id, 'QQ')}>
                                                    Open Reservation
                                                </Button>
                                            </div>
                                        ) : null}
                                        {openReservationLoadingState ? (
                                            <Button variant="outline" size="lg" className="flex-1 font-bold shadow-sm rounded-full px-4 text-xs" disabled>
                                                Checking Open Reservation...
                                            </Button>
                                        ) : null}
                                        <div className="flex-1">
                                            <Button size="lg" className="w-full font-bold shadow-md rounded-full px-4 text-xs" onClick={() => handleOfferSelection(flight, provider?.id, 'NN')}>
                                                Confirmed Reservation
                                                <ChevronRight className="ml-2 h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </div>
                </CardContent>
            </Card>
        );
    };

    return (
        <TenantNavbarLayout>
            <Head title={`Flights to ${activeDestination}`} />

            <div className={`max-w-7xl mx-auto py-8 px-4 ${((isRoundTripSearch && selectedOutboundFlight && selectedReturnFlight) || (!isRoundTripSearch && selectedOneWayFlight)) ? 'pb-28' : ''}`}>
                <div className="flex flex-col md:flex-row md:items-end justify-between mb-10 gap-6">
                    <div>
                        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold uppercase tracking-wider mb-2">
                            <div className="size-1.5 rounded-full bg-primary animate-pulse" />
                            Search Results
                        </div>
                        <h2 className="text-4xl font-black tracking-tight flex items-center gap-3">
                            {activeOrigin} <ChevronRight className="h-8 w-8 text-muted-foreground/30" /> {activeDestination}
                        </h2>
                        <p className="text-muted-foreground font-medium mt-1">
                            {new Date(activeDate).toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })} • {
                                [
                                    query.adults > 0 ? `${query.adults} Adult${query.adults > 1 ? 's' : ''}` : null,
                                    query.children > 0 ? `${query.children} Child${query.children > 1 ? 'ren' : ''}` : null,
                                    query.infants > 0 ? `${query.infants} Infant${query.infants > 1 ? 's' : ''}` : null
                                ].filter(Boolean).join(', ')
                            }
                        </p>
                    </div>
                    <div className="flex flex-1 md:flex-none bg-card border rounded-xl shadow-sm overflow-hidden w-full md:w-auto mt-4 md:mt-0">
                        {[-3, -2, -1, 0, 1, 2, 3].map(offset => {
                            const date = new Date(activeDate);
                            date.setDate(date.getDate() + offset);
                            const isSelected = offset === 0;
                            const isPast = date < new Date(new Date().setHours(0, 0, 0, 0));

                            // Format date for the POST payload (YYYY-MM-DD)
                            const localDateStr = new Date(date.getTime() - (date.getTimezoneOffset() * 60000)).toISOString().split('T')[0];
                            const dayName = date.toLocaleDateString(locale, { weekday: 'short' });
                            const dayNum = date.getDate();
                            const monthName = date.toLocaleDateString(locale, { month: 'short' });

                            if (isPast && !isSelected) {
                                return (
                                    <div key={offset} className="flex-1 min-w-15 md:min-w-20 py-2 border-r last:border-r-0 text-center opacity-40 cursor-not-allowed bg-muted/20">
                                        <div className="text-[10px] font-bold uppercase text-muted-foreground">{dayName}</div>
                                        <div className="text-sm md:text-base font-black text-muted-foreground">{monthName} {dayNum}</div>
                                    </div>
                                );
                            }

                            return (
                                isSelectingReturnStep ? (
                                    <button
                                        key={offset}
                                        type="button"
                                        className={`flex-1 min-w-15 md:min-w-20 py-2 px-1 md:px-4 border-r last:border-r-0 text-center transition-colors focus:outline-none hover:bg-primary/5 ${isSelected ? 'bg-primary text-primary-foreground hover:bg-primary' : 'bg-transparent text-foreground'}`}
                                        onClick={() => {
                                            setActiveReturnDate(localDateStr);
                                            loadReturnOptions(selectedOutboundFlight, selectedOutboundProviderId || findProviderForFlight(selectedOutboundFlight)?.id, selectedOutboundReservationType, localDateStr, true);
                                        }}
                                    >
                                        <div className={`text-[10px] font-bold uppercase ${isSelected ? 'text-primary-foreground/80' : 'text-muted-foreground'}`}>{dayName}</div>
                                        <div className="text-sm md:text-base font-black whitespace-nowrap">{monthName} {dayNum}</div>
                                    </button>
                                ) : (
                                    <Link
                                        key={offset}
                                        href={route('flights.search', { ...query, date: localDateStr })}
                                        as="a"
                                        onClick={() => setActiveSearchDate(localDateStr)}
                                        className={`flex-1 min-w-15 md:min-w-20 py-2 px-1 md:px-4 border-r last:border-r-0 text-center transition-colors focus:outline-none hover:bg-primary/5 ${isSelected ? 'bg-primary text-primary-foreground hover:bg-primary' : 'bg-transparent text-foreground'}`}
                                    >
                                        <div className={`text-[10px] font-bold uppercase ${isSelected ? 'text-primary-foreground/80' : 'text-muted-foreground'}`}>{dayName}</div>
                                        <div className="text-sm md:text-base font-black whitespace-nowrap">{monthName} {dayNum}</div>
                                    </Link>
                                )
                            );
                        })}
                    </div>
                </div>

                <div className={`mb-6 rounded-xl border px-4 py-3 ${offersIndicatorStyles[offersIndicatorState]}`}>
                    <div className="flex items-center gap-3">
                        {isOffersLoading ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Plane className="h-4 w-4" />
                        )}
                        <p className="text-sm font-bold">
                            {isOffersLoading
                                ? (isSelectingReturnStep ? t('common.loading_return_flight_offers') : t('common.loading_flight_offers'))
                                : offersFoundCount > 0
                                    ? t(offersFoundCount === 1 ? 'common.flight_offer_found' : 'common.flight_offers_found', {
                                        count: offersFoundCount
                                    })
                                    : t('common.no_flight_offers_found')}
                        </p>
                    </div>
                </div>

                <div className="space-y-6">
                    {!isRoundTripSearch && renderSelectedOneWaySummary()}

                    {isRoundTripSearch && (
                        <div className="rounded-2xl border bg-muted/10 p-4">
                            <p className="text-sm font-bold text-foreground">
                                {selectedOutboundFlight ? 'Step 2 of 2: Select return flight' : 'Step 1 of 2: Select outbound flight'}
                            </p>
                            {selectedOutboundFlight ? (
                                <div className="mt-3 space-y-3">
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="rounded-xl border bg-background/70 p-3">
                                            <p className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{t('common.outbound')}</p>
                                            <div className="mt-2 flex items-center justify-between gap-3">
                                                <div className="flex items-center gap-2">
                                                    <img src={route('api.airlines.logo', { code: selectedOutboundFlight.airline_code, variant: 'icon', radius: 4 })} alt={selectedOutboundFlight.airline_name} className="h-7 w-7 object-contain" />
                                                    <div>
                                                        <p className="text-sm font-bold">{selectedOutboundFlight.departure_airport} → {selectedOutboundFlight.arrival_airport}</p>
                                                        <p className="text-xs text-muted-foreground">{selectedOutboundFlight.airline_code}{selectedOutboundFlight.flight_number}</p>
                                                    </div>
                                                </div>
                                                <p className="text-sm font-black text-primary">{formatMoney(selectedOutboundFlight?.pricing?.total || 0, selectedOutboundFlight?.pricing?.currency || 'LYD')}</p>
                                            </div>
                                        </div>

                                        <div className="rounded-xl border bg-background/70 p-3">
                                            <p className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{t('common.return')}</p>
                                            {selectedReturnFlight ? (
                                                <div className="mt-2 flex items-center justify-between gap-3">
                                                    <div className="flex items-center gap-2">
                                                        <img src={route('api.airlines.logo', { code: selectedReturnFlight.airline_code, variant: 'icon', radius: 4 })} alt={selectedReturnFlight.airline_name} className="h-7 w-7 object-contain" />
                                                        <div>
                                                            <p className="text-sm font-bold">{selectedReturnFlight.departure_airport} → {selectedReturnFlight.arrival_airport}</p>
                                                            <p className="text-xs text-muted-foreground">{selectedReturnFlight.airline_code}{selectedReturnFlight.flight_number}</p>
                                                        </div>
                                                    </div>
                                                    <p className="text-sm font-black text-primary">{formatMoney(selectedReturnFlight?.pricing?.total || 0, selectedReturnFlight?.pricing?.currency || 'LYD')}</p>
                                                </div>
                                            ) : (
                                                <p className="mt-2 text-xs text-muted-foreground">{t('common.select_return_offer')}</p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setSelectedOutboundFlight(null);
                                                setSelectedOutboundProviderId(null);
                                                setSelectedOutboundReservationType('NN');
                                                setSelectedReturnFlight(null);
                                                setSelectedReturnProviderId(null);
                                                setReturnOptions([]);
                                                setActiveReturnDate(initialReturnDate);
                                            }}
                                        >
                                            Change outbound
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    )}

                    {providerErrors.length > 0 && (
                        <div className="rounded-2xl border border-destructive/40 bg-destructive/5 p-4">
                            <p className="text-sm font-bold text-destructive">
                                Some airlines timed out or failed to respond. You can adjust the search and try again.
                            </p>
                            <p className="mt-1 text-xs text-destructive/90">
                                {providerErrors.map((entry) => `${entry.provider}: ${entry.message}`).join(' | ')}
                            </p>
                        </div>
                    )}

                    {bestOffer && !selectedOneWayFlight && !selectedOutboundFlight && (
                        <div className="space-y-4">
                            <div className="flex items-center gap-3">
                                <div className="h-2 w-2 rounded-full bg-primary"></div>
                                <h3 className="text-lg font-bold">{t('common.best_offer')}</h3>
                                <div className="h-px bg-border flex-1"></div>
                            </div>
                            {renderOfferCard(bestOffer)}
                        </div>
                    )}

                    {!isRoundTripSearch && selectedOneWayFlight ? null : activeResults.length > 0 ? (
                        searchDisplayMode === 'per_flight'
                            ? groupedFlights.map((flightGroup) => (
                                <FlightGroupCard
                                    key={`${flightGroup.airline_code}-${flightGroup.flight_number}`}
                                    flightGroup={flightGroup}
                                    providers={providers}
                                    openReservationAvailability={openReservationAvailability}
                                    openReservationAvailabilityLoading={openReservationAvailabilityLoading}
                                    openOfferSummaryKey={openOfferSummaryKey}
                                    setOpenOfferSummaryKey={setOpenOfferSummaryKey}
                                    checkOpenReservationAvailability={checkOpenReservationAvailability}
                                    handleOfferSelection={handleOfferSelection}
                                />
                            ))
                            : activeResults.map(renderOfferCard)
                    ) : (
                        !loading && !loadingReturnOptions && (
                            <div className="text-center py-24 border rounded-3xl bg-card shadow-sm">
                                <Plane className="h-16 w-16 text-muted-foreground/20 mx-auto mb-4" />
                                <h3 className="text-xl font-bold">{selectedOutboundFlight ? 'No Return Flights Found' : 'No Flights Found'}</h3>
                                <p className="text-muted-foreground max-w-xs mx-auto">{selectedOutboundFlight ? 'No return options were found for your selected outbound flight. Change outbound flight or adjust dates.' : "We couldn't find any flights for your selected route and date. Try adjusting your search."}</p>
                                <Link href={route('flights.index')} data={query} className="mt-6 inline-block">
                                    <Button variant="outline" className="font-bold">{t('common.modify_search')}</Button>
                                </Link>
                            </div>
                        )
                    )}
                </div>

                {isRoundTripSearch && selectedOutboundFlight && selectedReturnFlight ? (
                    <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/85">
                        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4">
                            <div className="min-w-0">
                                <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">{t('common.selected_itinerary')}</p>
                                <p className="truncate text-sm font-semibold">
                                    {selectedOutboundFlight.departure_airport} → {selectedOutboundFlight.arrival_airport} • {selectedReturnFlight.departure_airport} → {selectedReturnFlight.arrival_airport}
                                </p>
                            </div>
                            <div className="flex items-center gap-4">
                                <div className="text-right">
                                    <p className="text-xs text-muted-foreground">{t('common.total')}</p>
                                    <p className="text-xl font-black text-primary">{formatMoney(selectedRoundTripTotal, selectedRoundTripCurrency)}</p>
                                </div>
                                <Button type="button" size="lg" className="font-bold" onClick={continueRoundTrip}>
                                    Continue to Passengers
                                    <ChevronRight className="ml-2 h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                ) : null}

                {!isRoundTripSearch && selectedOneWayFlight ? (
                    <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/85">
                        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4">
                            <div className="min-w-0">
                                <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">{t('common.selected_itinerary')}</p>
                                <p className="truncate text-sm font-semibold">
                                    {selectedOneWayFlight.departure_airport} → {selectedOneWayFlight.arrival_airport} • {selectedOneWayFlight.airline_code}{selectedOneWayFlight.flight_number}
                                </p>
                            </div>
                            <div className="flex items-center gap-4">
                                <div className="text-right">
                                    <p className="text-xs text-muted-foreground">{t('common.total')}</p>
                                    <p className="text-xl font-black text-primary">{formatMoney(selectedOneWayTotal, selectedOneWayCurrency)}</p>
                                </div>
                                <Button type="button" variant="outline" size="lg" className="font-bold" onClick={resetOneWaySelection}>
                                    Change Offer
                                </Button>
                                <Button type="button" size="lg" className="font-bold" onClick={continueOneWay}>
                                    Continue to Passengers
                                    <ChevronRight className="ml-2 h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                ) : null}
            </div>
        </TenantNavbarLayout>
    );
}
