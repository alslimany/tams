import React, { useState } from "react";
import axios from "axios";
import { route } from "ziggy-js";
import { useTranslation } from "@/hooks/useTranslation";
import { Button } from "@/Components/ui/Button";
import { Card, CardContent } from "@/Components/ui/Card";
import { Badge } from "@/Components/ui/Badge";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/Dialog";
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    Plane,
    Info,
    Clock,
    Briefcase,
    User,
    ReceiptText,
    ArrowRightLeft,
    ChevronDownIcon,
    ChevronUpIcon,
    Loader2,
} from "lucide-react";

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/Table";
import { formatMoney, formatMoneyValue } from "@/lib/currency";

export default function FlightGroupCard({
    flightGroup,
    providers,
    showSoldoutClasses = true,
    openReservationAvailability,
    openReservationAvailabilityLoading,
    openOfferSummaryKey,
    setOpenOfferSummaryKey,
    checkOpenReservationAvailability,
    handleOfferSelection,
}) {
    const { t, getAirlineName, getCurrencyName, getCabinName } = useTranslation();

    const provider =
        providers.find(
            (p) => Number(p.id) === Number(flightGroup.provider_id),
        ) ||
        providers.find((p) =>
            (flightGroup.airline_name || "").includes(p.airline_name),
        );

    const segments = flightGroup.segments || [];
    const totalDuration = segments.reduce(
        (acc, s) => acc + (s.duration || 0),
        0,
    );
    const [expandedCabin, setExpandedCabin] = useState(null);

    // No default expansion - user must click to expand
    const isSoldOut = (offer) => Number(offer.available_seats || 0) <= 0;

    const cabins = {};
    flightGroup.offers.forEach((offer) => {
        if (!showSoldoutClasses && isSoldOut(offer)) {
            return;
        }
        const cabinType = offer.pricing?.cabin_type || "Y";
        const normalizedCabin =
            cabinType === "C" || cabinType === "J" ? "Business" : "Economy";
        if (!cabins[normalizedCabin]) {
            cabins[normalizedCabin] = [];
        }
        cabins[normalizedCabin].push(offer);
    });

    Object.values(cabins).forEach((offers) => {
        offers.sort(
            (a, b) => (a.pricing?.total || 0) - (b.pricing?.total || 0),
        );
    });

    const cabinKeys = Object.keys(cabins);
    const lowestPriceByCabin = {};
    cabinKeys.forEach((cabin) => {
        const available = cabins[cabin].filter((o) => !isSoldOut(o));
        lowestPriceByCabin[cabin] =
            available.length > 0
                ? available[0].pricing?.total || 0
                : cabins[cabin][0]?.pricing?.total || 0;
    });
    const currency = flightGroup.offers[0]?.pricing?.currency || "LYD";

    const [fareRulesModal, setFareRulesModal] = useState({ open: false, loading: false, text: '', error: '' });

    const fetchFareRules = (offer) => {
        const fareId = offer?.pricing?.fare_id;
        if (!fareId) return;
        setFareRulesModal({ open: true, loading: true, text: '', error: '' });
        axios
            .post(route('flights.fare-rules'), {
                provider_id: flightGroup.provider_id,
                fare_id: fareId,
            })
            .then((res) => setFareRulesModal({ open: true, loading: false, text: res.data.rules || '', error: '' }))
            .catch(() => setFareRulesModal({ open: true, loading: false, text: '', error: t('common.error_loading_fare_rules') || 'Failed to load fare rules.' }));
    };

    /**
     * Inline fare rules block for the Offer Summary modal.
     * Fetches once when isOpen becomes true, then caches in local state.
     */
    const FareRulesSection = ({ fareId, providerId, isOpen }) => {
        const [state, setState] = useState({ loaded: false, loading: false, text: '', error: '' });

        React.useEffect(() => {
            if (!isOpen || state.loaded || state.loading) return;
            setState((s) => ({ ...s, loading: true }));
            axios
                .post(route('flights.fare-rules'), { provider_id: providerId, fare_id: fareId })
                .then((res) => setState({ loaded: true, loading: false, text: res.data.rules || '', error: '' }))
                .catch(() => setState({ loaded: true, loading: false, text: '', error: t('common.error_loading_fare_rules') || 'Failed to load fare rules.' }));
        }, [isOpen]);

        return (
            <div>
                <p className="font-bold mb-3 tracking-widest text-xs text-muted-foreground">
                    {t('common.fare_rules') || 'Fare Rules'}
                </p>
                {state.loading && (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground py-2">
                        <Loader2 className="h-4 w-4 animate-spin text-primary" />
                        {t('common.loading') || 'Loading…'}
                    </div>
                )}
                {state.error && (
                    <p className="text-xs text-destructive">{state.error}</p>
                )}
                {!state.loading && !state.error && state.text && (
                    <pre className="whitespace-pre-wrap text-xs leading-relaxed font-sans text-foreground bg-muted/20 rounded-xl p-4 border">
                        {state.text}
                    </pre>
                )}
            </div>
        );
    };

    const parseBrandDetails = (details) => {
        if (!details) return {};
        const features = {};
        details.split(/\n|,|•/).forEach((line) => {
            const trimmed = line.trim();
            if (!trimmed) return;
            const parts = trimmed.split(":");
            if (parts.length >= 2) {
                features[parts[0].trim()] = parts.slice(1).join(":").trim();
            } else {
                features[trimmed] = "";
            }
        });
        return features;
    };

    const formatDuration = (minutes) => {
        if (!minutes) return "N/A";
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return `${h > 0 ? `${h}h ` : ""}${m}m`;
    };

    const openReservationKey = (flight, providerId) => {
        const classCode =
            flight?.pricing?.class_code ||
            flight?.segments?.[0]?.class ||
            flight?.class ||
            "Y";
        const origin =
            flight?.departure_airport ||
            flight?.segments?.[0]?.departure_airport ||
            "";
        const destination =
            flight?.arrival_airport ||
            flight?.segments?.[0]?.arrival_airport ||
            "";

        return [providerId || "unknown", origin, destination, classCode].join(
            ":",
        );
    };

    const offerSummaryKey = (flight, providerId) => {
        const flightIdentifier =
            flight?.id ||
            `${flight?.airline_code || "XX"}-${flight?.flight_number || "000"}-${flight?.departure_time || "time"}`;

        return [providerId || "unknown", flightIdentifier].join(":");
    };

    const renderFlightDetailsDialog = (
        flight,
        triggerLabel = "Flight Details",
    ) => {
        if (!flight) {
            return null;
        }

        return (
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 gap-2 font-bold px-4 rounded-full border-primary/20 hover:bg-primary/5 transition-all"
                    >
                        <Info className="h-4 w-4 text-primary" />
                        {triggerLabel}
                    </Button>
                </DialogTrigger>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="text-2xl font-black">
                            {t('common.flight_information')}
                        </DialogTitle>
                        <DialogDescription className="font-medium">
                            {t('common.detailed_itinerary')} {getAirlineName(flight.airline_code) || flight.airline_name}{" "}
                            {flight.airline_code}
                            {flight.flight_number}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-6 py-4">
                        {(flight.segments || []).map((segment, idx) => (
                            <div
                                key={idx}
                                className="bg-muted/30 rounded-2xl p-6 border border-dashed border-primary/20"
                            >
                                <div className="flex justify-between items-start mb-6">
                                    <div>
                                        <p className="text-xs font-bold  tracking-widest text-primary mb-1">
                                            {t('common.carrier')}
                                        </p>
                                        <p className="text-lg font-black">
                                            {flight.airline_name.split(" (")[0]}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xs font-bold  tracking-widest text-primary mb-1">
                                            {t('common.aircraft')}
                                        </p>
                                        <p className="text-lg font-black">
                                            {segment.aircraft || "Standard"}
                                        </p>
                                    </div>
                                </div>
                                <div className="grid grid-cols-3 items-center gap-4">
                                    <div className="text-left">
                                        <p className="text-sm font-bold text-muted-foreground mb-1">
                                            {t('common.departure')}
                                        </p>
                                        <p className="text-2xl font-black">
                                            {segment.departure_airport}
                                        </p>
                                        <p className="text-xs font-medium text-muted-foreground">
                                            {segment.departure_time}
                                        </p>
                                    </div>
                                    <div className="flex flex-col items-center gap-2">
                                        <div className="flex items-center gap-2 text-primary font-bold text-xs  bg-primary/10 px-3 py-1 rounded-full">
                                            <Clock className="h-3 w-3" />
                                            {formatDuration(segment.duration)}
                                        </div>
                                        <div className="w-full h-px bg-primary/20 relative">
                                            <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-background p-1 rounded-full border border-primary/20">
                                                <Plane className="h-3 w-3 text-primary" />
                                            </div>
                                        </div>
                                        <p className="text-[10px] font-bold text-muted-foreground  tracking-tighter">
                                            Non-stop
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-sm font-bold text-muted-foreground mb-1">
                                            {t('common.arrival')}
                                        </p>
                                        <p className="text-2xl font-black">
                                            {segment.arrival_airport}
                                        </p>
                                        <p className="text-xs font-medium text-muted-foreground">
                                            {segment.arrival_time}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        );
    };

    const renderPriceDetailsDialog = (
        flight,
        triggerLabel = t('common.price_details'),
    ) => {
        if (!flight?.pricing) {
            return null;
        }

        return (
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 gap-2 font-bold px-4 rounded-full border-primary/20 hover:bg-primary/5 transition-all"
                    >
                        <ReceiptText className="h-4 w-4 text-primary" />
                        {triggerLabel}
                    </Button>
                </DialogTrigger>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="text-2xl font-black">
                            {t('common.offer_details')}
                        </DialogTitle>
                        <DialogDescription className="font-medium">
                            {t('common.pricing_breakdown')}{" "}
                            <strong>
                                {flight.pricing.brand_name || t('common.selected_fare')}
                            </strong>
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-8 py-4">
                        {flight.pricing.brand_details && (
                            <div className="bg-primary/5 rounded-2xl p-6 border-l-4 border-primary">
                                <div className="flex items-center gap-3 mb-3">
                                    <Briefcase className="h-5 w-5 text-primary" />
                                    <p className="text-sm font-black  tracking-widest text-primary">
                                        {t('common.fare_features')}
                                    </p>
                                </div>
                                <p className="text-sm font-medium leading-relaxed whitespace-pre-line text-muted-foreground">
                                    {flight.pricing.brand_details}
                                </p>
                            </div>
                        )}

                        <div>
                            <div className="flex items-center gap-3 mb-6">
                                <ReceiptText className="h-5 w-5 text-primary" />
                                <p className="text-sm font-black  tracking-widest">
                                    {t('common.passenger_breakdown')}
                                </p>
                            </div>
                            <div className="border rounded-2xl overflow-hidden bg-muted/10 shadow-sm">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/30">
                                            <TableHead className="font-bold text-foreground">
                                                {t('common.type')}
                                            </TableHead>
                                            <TableHead className="text-right font-bold text-foreground">
                                                {t('common.fare')}
                                            </TableHead>
                                            <TableHead className="text-right font-bold text-foreground">
                                                {t('common.tax')}
                                            </TableHead>
                                            <TableHead className="text-right font-bold text-foreground">
                                                {t('common.total')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {(flight.pricing.breakdown || []).map(
                                            (pax, i) => (
                                                <TableRow key={i}>
                                                    <TableCell className="font-bold py-4">
                                                        <div className="flex items-center gap-2">
                                                            <User className="h-4 w-4 text-muted-foreground" />
                                                            {pax.label}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right font-medium">
                                                        {formatMoney(
                                                            pax.fare,
                                                            flight.pricing
                                                                .currency,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right font-medium">
                                                        {formatMoney(
                                                            pax.tax,
                                                            flight.pricing
                                                                .currency,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right font-black text-primary">
                                                        {formatMoney(
                                                            pax.amount,
                                                            flight.pricing
                                                                .currency,
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ),
                                        )}
                                        <TableRow className="bg-primary/10 hover:bg-primary/20 transition-colors">
                                            <TableCell
                                                colSpan={3}
                                                className="font-black text-lg py-4"
                                            >
                                                {t('common.grand_total')}
                                            </TableCell>
                                            <TableCell className="text-right font-black text-2xl text-primary">
                                                {formatMoney(
                                                    flight.pricing.total,
                                                    flight.pricing.currency,
                                                )}
                                            </TableCell>
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

    const renderComparisonTable = (offers) => {
        const offersFeatures = offers.map((offer) =>
            parseBrandDetails(offer.pricing?.brand_details),
        );

        // Parse "20K" → "20 kg" (translated), "0K" → null (skip zeros)
        const formatWeight = (raw) => {
            if (!raw) return null;
            const match = String(raw).match(/(\d+)/);
            if (!match) return null;
            const num = parseInt(match[1], 10);
            if (num === 0) return null;
            return `${num} ${t('common.kg') || 'kg'}`;
        };

        // Fixed feature rows: Checked baggage, Cabin baggage, Refundable
        const featureRows = [
            {
                key: 'checked_baggage',
                label: t('common.checked_baggage') || 'Checked',
                extract: (features, offer) => {
                    const holdWt = offer?.pricing?.hold_weight;
                    if (holdWt) return formatWeight(holdWt);
                    // Fallback: brand_details text
                    const found = Object.keys(features).find(
                        (k) =>
                            k.toLowerCase().includes('bag') ||
                            k.toLowerCase().includes('weight') ||
                            k.toLowerCase().includes('kg'),
                    );
                    return found ? features[found] : null;
                },
            },
            {
                key: 'hand_baggage',
                label: t('common.hand_baggage') || 'Cabin',
                extract: (_features, offer) => {
                    const handWt = offer?.pricing?.hand_weight;
                    return formatWeight(handWt);
                },
            },
            {
                key: 'refundable',
                label: t('common.refundable') || 'Refundable',
                extract: (features) => {
                    const found = Object.keys(features).find(
                        (k) => k.toLowerCase().includes('refund'),
                    );
                    return found ? features[found] : null;
                },
            },
        ];

        return (
            <div className="overflow-x-auto rounded-xl border bg-muted/10">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b bg-muted/30">
                            <th className=" py-3 px-4 font-bold text-xs  tracking-wider text-muted-foreground min-w-[120px]">
                                {t('common.feature')}
                            </th>
                            {offers.map((offer) => (
                                <th
                                    key={offer.id}
                                    className="text-center py-3 px-4 font-bold min-w-[140px]"
                                >
                                    <div className="flex flex-col items-center gap-1">
                                        <Badge
                                            variant="outline"
                                            className="text-[11px] font-bold px-2.5 py-0.5 bg-background shadow-sm"
                                        >
                                            {offer.pricing?.brand_details ||
                                                `${t('common.class')} ${offer.pricing?.class_code || 'Y'}`}
                                        </Badge>
                                        <span className="text-[10px] text-muted-foreground font-semibold ">
                                            ({offer.pricing?.brand_name || 'Y'})
                                        </span>
                                        {offer.pricing?.fare_id && (
                                            <button
                                                type="button"
                                                onClick={() => fetchFareRules(offer)}
                                                className="text-[10px] text-primary underline underline-offset-2 hover:opacity-75 transition-opacity"
                                            >
                                                {t('common.fare_rules') || 'Fare Rules'}
                                            </button>
                                        )}
                                    </div>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {featureRows.map((row, idx) => (
                            <tr
                                key={row.key}
                                className={`border-b ${idx % 2 === 0 ? 'bg-muted/5' : ''} hover:bg-muted/15 transition-colors`}
                            >
                                <td className="py-2.5 px-4 font-semibold text-xs text-muted-foreground whitespace-nowrap">
                                    {row.label}
                                </td>
                                {offersFeatures.map((features, i) => {
                                    const value = row.extract(features, offers[i]);
                                    return (
                                        <td
                                            key={offers[i].id}
                                            className="py-2.5 px-4 text-center text-xs font-medium"
                                        >
                                            {value ? (
                                                <span
                                                    className={
                                                        value.toLowerCase() === 'no' ||
                                                        value.toLowerCase().includes('not avail')
                                                            ? 'text-destructive/70'
                                                            : 'text-foreground'
                                                    }
                                                >
                                                    {value}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground/40">
                                                    &mdash;
                                                </span>
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}

                        <tr className="border-b bg-muted/5">
                            <td className="py-2.5 px-4 font-bold text-xs  tracking-wider text-muted-foreground">
                                {t('common.price')}
                            </td>
                            {offers.map((offer) => (
                                <td
                                    key={offer.id}
                                    className="py-2.5 px-4 text-center"
                                >
                                    <p className="text-base font-black text-primary">
                                        {formatMoneyValue(
                                            offer.pricing?.total || 0,
                                        )}{" "}
                                        <span className="text-[10px] font-bold text-muted-foreground">
                                            {getCurrencyName(offer.pricing?.currency) || "LYD"}
                                        </span>
                                    </p>
                                </td>
                            ))}
                        </tr>

                        <tr className="border-b bg-muted/5">
                            <td className="py-2.5 px-4 font-bold text-xs  tracking-wider text-muted-foreground">
                                {t('common.seats')}
                            </td>
                            {offers.map((offer) => (
                                <td
                                    key={offer.id}
                                    className="py-2.5 px-4 text-center"
                                >
                                    {isSoldOut(offer) ? (
                                                        <Badge
                                                            variant="destructive"
                                                            className="text-[10px] font-bold text-white"
                                                        >
                                            {t('common.sold_out')}
                                        </Badge>
                                    ) : (
                                        <span className="text-xs font-semibold text-emerald-600">
                                            {offer.available_seats} {t('common.left')}
                                        </span>
                                    )}
                                </td>
                            ))}
                        </tr>

                        <tr className="bg-primary/5">
                            <td className="py-3 px-4 font-bold text-xs  tracking-wider text-muted-foreground">
                                {t('common.action')}
                            </td>
                            {offers.map((offer) => {
                                const soldOut = isSoldOut(offer);
                                const openReservationStatusKey =
                                    openReservationKey(offer, provider?.id);
                                const openReservationAllowed = Boolean(
                                    openReservationAvailability[
                                        openReservationStatusKey
                                    ],
                                );
                                const openReservationLoadingState = Boolean(
                                    openReservationAvailabilityLoading[
                                        openReservationStatusKey
                                    ],
                                );

                                return (
                                    <td
                                        key={offer.id}
                                        className="py-3 px-4 text-center"
                                    >
                                        <Dialog
                                            open={
                                                openOfferSummaryKey ===
                                                offerSummaryKey(
                                                    offer,
                                                    provider?.id,
                                                )
                                            }
                                            onOpenChange={(open) => {
                                                setOpenOfferSummaryKey(
                                                    open
                                                        ? offerSummaryKey(
                                                              offer,
                                                              provider?.id,
                                                          )
                                                        : null,
                                                );
                                                if (open) {
                                                    checkOpenReservationAvailability(
                                                        offer,
                                                        provider?.id,
                                                    );
                                                }
                                            }}
                                        >
                                            <DialogTrigger asChild>
                                                <Button
                                                    size="sm"
                                                    className="font-bold shadow-sm px-4"
                                                    disabled={soldOut}
                                                >
                                                    {soldOut
                                                        ? t('common.sold_out')
                                                        : t('common.select')}
                                                    {!soldOut && (
                                                        <ChevronRightIcon className="ml-1 h-3 w-3" />
                                                    )}
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent className="max-w-2xl sm:rounded-3xl p-0 overflow-hidden">
                                                <div className="bg-primary/5 p-6 border-b">
                                                    <DialogTitle className="text-2xl font-black tracking-normal leading-tight">
                                                        {t('common.offer_summary')}
                                                    </DialogTitle>
                                                    <DialogDescription className="text-muted-foreground font-medium text-sm mt-2">
                                                        {t('common.review_selected')}
                                                        {t('common.class')} {t('common.before_proceeding')}
                                                    </DialogDescription>
                                                </div>
                                                <div className="p-6 space-y-6 max-h-[60vh] overflow-y-auto">
                                                    <div className="flex justify-between items-center bg-card border rounded-2xl p-4 shadow-sm">
                                                        <div>
                                                            <p className="text-sm font-bold text-muted-foreground">
                                                                {t('common.itinerary_fare')}
                                                            </p>
                                                            <p className="text-xl font-black">
                                                                {
                                                                    flightGroup.departure_airport
                                                                }{" "}
                                                                <ArrowRightLeft className="inline shrink-0 h-4 w-4 mx-1" />{" "}
                                                                {
                                                                    flightGroup.arrival_airport
                                                                }
                                                            </p>
                                                            <p className="text-sm font-medium">
                                                                {offer.pricing
                                                                    ?.brand_name ||
                                                                    t('common.standard')}{" "}
                                                                &bull; {t('common.class')}{" "}
                                                                {
                                                                    offer
                                                                        .pricing
                                                                        ?.class_code
                                                                }
                                                            </p>
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="text-sm font-bold text-muted-foreground">
                                                                {t('common.grand_total')}
                                                            </p>
                                                            <p className="text-2xl font-black text-primary">
                                                                {formatMoneyValue(
                                                                    offer
                                                                        .pricing
                                                                        ?.total ||
                                                                        0,
                                                                )}{" "}
                                                                <span className="text-sm">
                                                                    {offer
                                                                        .pricing
                                                                        ?.currency ||
                                                                        "LYD"}
                                                                </span>
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <p className="font-bold mb-3  tracking-widest text-xs text-muted-foreground">
                                                            {t('common.flight_segments')}
                                                        </p>
                                                        <div className="space-y-3">
                                                            {segments.map(
                                                                (seg, i) => (
                                                                    <div
                                                                        key={i}
                                                                        className="flex gap-4 p-4 border rounded-xl bg-muted/10 items-center"
                                                                    >
                                                                        <Plane className="h-5 w-5 text-primary" />
                                                                        <div className="flex-1">
                                                                            <div className="flex justify-between font-black text-sm">
                                                                                <span>
                                                                                    {
                                                                                        seg.departure_airport
                                                                                    }{" "}
                                                                                    (
                                                                                    {seg.departure_time
                                                                                        ?.split(
                                                                                            " ",
                                                                                        )[1]
                                                                                        ?.substring(
                                                                                            0,
                                                                                            5,
                                                                                        )}
                                                                                    )
                                                                                </span>
                                                                                <span>
                                                                                    {
                                                                                        seg.arrival_airport
                                                                                    }{" "}
                                                                                    (
                                                                                    {seg.arrival_time
                                                                                        ?.split(
                                                                                            " ",
                                                                                        )[1]
                                                                                        ?.substring(
                                                                                            0,
                                                                                            5,
                                                                                        )}
                                                                                    )
                                                                                </span>
                                                                            </div>
                                                                            <p className="text-xs text-muted-foreground font-medium mt-1">
                                                                                {t('common.duration')}:{" "}
                                                                                {formatDuration(
                                                                                    seg.duration,
                                                                                )}{" "}
                                                                                &bull;{" "}
                                                                                {seg.aircraft ||
                                                                                    t('common.standard')}{" "}
                                                                                &bull;
                                                                                {t('common.class')}{" "}
                                                                                {
                                                                                    offer
                                                                                        .pricing
                                                                                        ?.class_code
                                                                                }
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Fare Rules */}
                                                    {offer.pricing?.fare_id && (
                                                        <FareRulesSection
                                                            fareId={offer.pricing.fare_id}
                                                            providerId={provider?.id ?? flightGroup.provider_id}
                                                            isOpen={openOfferSummaryKey === offerSummaryKey(offer, provider?.id)}
                                                        />
                                                    )}
                                                </div>
                                                <div className="p-6 border-t bg-muted/30 flex flex-col sm:flex-row gap-3">
                                                    {openReservationAllowed ? (
                                                        <div className="flex-1">
                                                            <Button
                                                                variant="outline"
                                                                size="lg"
                                                                className="w-full font-bold shadow-sm rounded-full px-4 text-xs"
                                                                onClick={() =>
                                                                    handleOfferSelection(
                                                                        offer,
                                                                        provider?.id,
                                                                        "QQ",
                                                                    )
                                                                }
                                                            >
                                                                {t('common.open_reservation')}
                                                            </Button>
                                                        </div>
                                                    ) : null}
                                                    {openReservationLoadingState ? (
                                                        <Button
                                                            variant="outline"
                                                            size="lg"
                                                            className="flex-1 font-bold shadow-sm rounded-full px-4 text-xs"
                                                            disabled
                                                        >
                                                            {t('common.checking_open_reservation')}
                                                        </Button>
                                                    ) : null}
                                                    <div className="flex-1">
                                                        <Button
                                                            size="lg"
                                                            className="w-full font-bold shadow-md rounded-full px-4 text-xs"
                                                            onClick={() =>
                                                                handleOfferSelection(
                                                                    offer,
                                                                    provider?.id,
                                                                    "NN",
                                                                )
                                                            }
                                                        >
                                                            {t('common.confirmed_reservation')}
                                                            <ChevronRightIcon className="ml-2 h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </DialogContent>
                                        </Dialog>
                                    </td>
                                );
                            })}
                        </tr>
                    </tbody>
                </table>
            </div>
        );
    };

    const offerId = `${flightGroup.airline_code}-${flightGroup.flight_number}`;

    return (
        <Card
            key={offerId}
            className="overflow-hidden shadow-md border-2 border-muted/50 hover:border-primary/30 transition-all"
        >
            {/* Row 1: Airline + Itinerary */}
            <div className="p-4 sm:p-6 bg-muted/5">
                <div className="flex flex-col gap-4">
                    <div className="flex items-center justify-between gap-1">
                        <div className="flex items-center gap-1">
                            <div className="bg-primary/5 p-1.5 rounded-lg shrink-0 flex items-center justify-center h-10 w-10 border border-primary/10">
                                {flightGroup.airline_code ? (
                                    <img
                                        src={route("api.airlines.logo", {
                                            code: flightGroup.airline_code,
                                            variant: "icon-transparent",
                                            radius: 8,
                                        })}
                                        alt={flightGroup.airline_name}
                                        className="h-7 w-7 object-contain"
                                        onError={(e) => {
                                            e.target.style.display = "none";
                                            e.target.nextSibling.style.display =
                                                "block";
                                        }}
                                    />
                                ) : null}
                                <Plane
                                    className="h-6 w-6 text-primary"
                                    style={{
                                        display: flightGroup.airline_code
                                            ? "none"
                                            : "block",
                                    }}
                                />
                            </div>
                            <div>
                                <p className="font-bold text-sm sm:text-lg">
                                    {getAirlineName(flightGroup.airline_code) || flightGroup.airline_name?.split(" (")[0]}
                                </p>
                                <p className="text-[11px] text-muted-foreground font-semibold  tracking-widest">
                                    {flightGroup.airline_code}
                                    {flightGroup.flight_number}
                                </p>
                            </div>
                        </div>
                        {/*  */}
                        <div className="relative flex flex-1 mx-4 items-center gap-2 sm:gap-6 py-1">
                            <div className="text-left min-w-[70px] sm:min-w-[90px]">
                                <p className="text-xl sm:text-3xl font-black tabular-nums">
                                    {flightGroup.departure_time
                                        ?.split(" ")[1]
                                        ?.substring(0, 5)}
                                </p>
                                <p className="text-xs sm:text-sm font-bold text-muted-foreground">
                                    {flightGroup.departure_airport}
                                </p>
                            </div>

                            <div className="flex-1 flex flex-col items-center gap-1 min-w-0 px-1">
                                <p className="text-[10px] sm:text-xs font-bold text-muted-foreground">
                                    {formatDuration(totalDuration)}
                                </p>
                                <div className="w-full flex items-center gap-1">
                                    <div className="h-2 w-2 rounded-full border-2 border-primary shrink-0" />
                                    <div className="flex-1 h-px bg-primary/30" />
                                    <div className="shrink-0 bg-primary/10 rounded-full p-0.5">
                                        <Plane className="h-3 w-3 text-primary" />
                                    </div>
                                    <div className="flex-1 h-px bg-primary/30" />
                                    <div className="h-2 w-2 rounded-full border-2 border-primary shrink-0" />
                                </div>
                                {segments.length <= 1 ? (
                                        <p className="text-[10px] font-semibold text-primary ">
                                            {t('common.non_stop')}
                                        </p>
                                ) : (
                                    <p className="text-[10px] font-semibold text-amber-600 ">
                                        {segments.length - 1} {segments.length - 1 === 1 ? t('common.stop') : t('common.stops')}
                                    </p>
                                )}
                            </div>

                            <div className="text-right min-w-[70px] sm:min-w-[90px]">
                                <p className="text-xl sm:text-3xl font-black tabular-nums">
                                    {flightGroup.arrival_time
                                        ?.split(" ")[1]
                                        ?.substring(0, 5)}
                                </p>
                                <p className="text-xs sm:text-sm font-bold text-muted-foreground">
                                    {flightGroup.arrival_airport}
                                </p>
                            </div>
                        </div>
                        {/*  */}
                        <div className="flex flex-col items-center gap-2 pt-2 border-t border-dashed border-muted/50">
                           
                            {cabinKeys.map((cabin) => {
                                const isExpanded = expandedCabin === cabin;
                                const isAvailable = cabins[cabin].some(
                                    (o) => !isSoldOut(o),
                                );
                                return (
                                    <button
                                        key={cabin}
                                        type="button"
                                        onClick={() =>
                                            setExpandedCabin(
                                                isExpanded ? null : cabin,
                                            )
                                        }
                                        className={`
                                        inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs sm:text-sm font-bold  tracking-wider
                                        transition-all duration-200 border
                                        ${
                                            isExpanded
                                                ? "bg-primary text-primary-foreground border-primary shadow-md scale-105"
                                                : isAvailable
                                                  ? "bg-background text-foreground border-primary/30 hover:border-primary hover:bg-primary/5"
                                                  : "bg-muted text-muted-foreground border-muted cursor-not-allowed"
                                        }
                                    `}
                                    >
                                        
                                        <span className="text-[10px] sm:text-xs font-black opacity-80">
                                            {getCabinName(cabin)} {t('common.cabin_from')} {formatMoneyValue(
                                                lowestPriceByCabin[cabin],
                                            )} {getCurrencyName(currency)}
                                        </span>
                                        {/* {isExpanded ? (
                                            <ChevronUpIcon className="h-3 w-3 sm:h-4 sm:w-4" />
                                        ) : (
                                            <ChevronDownIcon className="h-3 w-3 sm:h-4 sm:w-4" />
                                        )} */}
                                    </button>
                                );
                            })}
                        </div>
                        {/*  */}
                        {/* <div className="flex items-center gap-1">
                            {renderFlightDetailsDialog(flightGroup, 'Details')}
                        </div> */}
                    </div>
                </div>
            </div>

            {/* Row 2: Comparison Table (shown when cabin selected) */}
            {expandedCabin && (
                <div className="bg-muted/5 p-4 sm:p-6 animate-in slide-in-from-top-2 duration-200">
                    {/* hide using if statement */}
                    {false && (
                    <p className="text-xs font-bold  tracking-widest text-muted-foreground mb-4">
                        {getCabinName(expandedCabin)} {t('common.class_comparison')} &mdash; {cabins[expandedCabin].length} {t('common.offer')}{cabins[expandedCabin].length !== 1 ? t('common.plural_suffix') : ''}
                    </p>
                    )}
                    {renderComparisonTable(cabins[expandedCabin])}
                </div>
            )}

            {/* Fare Rules Modal */}
            <Dialog open={fareRulesModal.open} onOpenChange={(open) => setFareRulesModal((s) => ({ ...s, open }))}>
                <DialogContent className="max-w-lg" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('common.fare_rules') || 'Fare Rules'}</DialogTitle>
                    </DialogHeader>
                    {fareRulesModal.loading && (
                        <div className="flex items-center justify-center py-8 text-muted-foreground text-sm gap-2">
                            <Loader2 className="h-5 w-5 animate-spin text-primary" />
                            {t('common.loading') || 'Loading…'}
                        </div>
                    )}
                    {fareRulesModal.error && (
                        <p className="text-destructive text-sm">{fareRulesModal.error}</p>
                    )}
                    {!fareRulesModal.loading && !fareRulesModal.error && fareRulesModal.text && (
                        <pre className="whitespace-pre-wrap text-sm leading-relaxed font-sans text-foreground max-h-[60vh] overflow-y-auto">
                            {fareRulesModal.text}
                        </pre>
                    )}
                </DialogContent>
            </Dialog>
        </Card>
    );
}
