import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/Tabs';
import { Armchair, Briefcase, CheckCircle2, ChevronLeft, ChevronRight, Loader2, Settings2, Users } from 'lucide-react';

export default function PassengerInfo({ uuid, provider_id, flight, reservation_type, is_round_trip = false, outbound_provider_id = null, return_provider_id = null, passportRequired = false, searchParams, ancillaryCatalog = [], ancillaryCatalogByOffer = {} }) {
    const flash = usePage().props.flash ?? {};
    const issueCommandPreview = flash.issue_command_preview || '';

    const initialPassengers = [];
    const types = [
        { type: 'adult', count: searchParams?.adults || 1 },
        { type: 'child', count: searchParams?.children || 0 },
        { type: 'infant', count: searchParams?.infants || 0 },
    ];

    types.forEach(({ type, count }) => {
        for (let index = 0; index < count; index += 1) {
            initialPassengers.push({
                type,
                first_name: '',
                last_name: '',
                dob: '',
                gender: 'M',
                passport_number: '',
                passport_expiry: '',
                passport_issue_country: 'LBY',
                nationality: 'LBY',
            });
        }
    });

    const { data, setData, post, processing, errors } = useForm({
        uuid,
        provider_id,
        flight,
        reservation_type,
        is_round_trip,
        outbound_provider_id,
        return_provider_id,
        customer: {
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
        },
        passengers: initialPassengers,
        extras: {
            selected_services: [],
            seats: {},
        },
    });

    const [activeTab, setActiveTab] = useState('passengers');
    const [isSeatMapOpen, setIsSeatMapOpen] = useState(false);
    const [seatMapByOffer, setSeatMapByOffer] = useState({});
    const [loadingSeatMap, setLoadingSeatMap] = useState(false);
    const [activeOfferKeyForSeat, setActiveOfferKeyForSeat] = useState('oneway');
    const [activePaxIndexForSeat, setActivePaxIndexForSeat] = useState(0);
    const [localErrors, setLocalErrors] = useState({});

    const offerContexts = useMemo(() => {
        if (is_round_trip && flight?.round_trip) {
            const outboundFlight = flight.round_trip.outbound_flight;
            const returnFlight = flight.round_trip.return_flight;

            const contexts = [];

            if (outboundFlight) {
                contexts.push({
                    key: 'outbound',
                    label: 'Outbound Offer',
                    providerId: Number(outbound_provider_id || provider_id),
                    flight: outboundFlight,
                    segments: outboundFlight.segments || [outboundFlight],
                });
            }

            if (returnFlight) {
                contexts.push({
                    key: 'return',
                    label: 'Return Offer',
                    providerId: Number(return_provider_id || provider_id),
                    flight: returnFlight,
                    segments: returnFlight.segments || [returnFlight],
                });
            }

            if (contexts.length > 0) {
                return contexts;
            }
        }

        return [
            {
                key: 'oneway',
                label: 'Offer',
                providerId: Number(provider_id),
                flight,
                segments: flight?.segments || [flight],
            },
        ];
    }, [flight, is_round_trip, outbound_provider_id, provider_id, return_provider_id]);

    const isRoundTripBooking = offerContexts.length > 1;

    useEffect(() => {
        if (!offerContexts.some((offer) => offer.key === activeOfferKeyForSeat)) {
            setActiveOfferKeyForSeat(offerContexts[0]?.key || 'oneway');
        }
    }, [activeOfferKeyForSeat, offerContexts]);

    const ancillaryCatalogByOfferMap = useMemo(() => {
        const map = {};

        offerContexts.forEach((offer) => {
            const offerCatalog = ancillaryCatalogByOffer?.[offer.key] ?? ancillaryCatalog;
            map[offer.key] = Array.isArray(offerCatalog)
                ? offerCatalog.filter((service) => service.enabled)
                : [];
        });

        return map;
    }, [ancillaryCatalog, ancillaryCatalogByOffer, offerContexts]);

    const passportFields = ['passport_number', 'passport_expiry', 'passport_issue_country', 'nationality'];
    const hasPartialPassportDetails = useMemo(() => {
        return data.passengers.some((passenger) => {
            const values = passportFields.map((field) => (passenger[field] ?? '').toString().trim());
            const hasAny = values.some((value) => value !== '');
            const hasMissing = values.some((value) => value === '');

            return hasAny && hasMissing;
        });
    }, [data.passengers]);

    const validateStep = (step) => {
        const errors = {};
        if (step === 'passengers') {
            data.passengers.forEach((p, i) => {
                if (!p.first_name) errors[`passengers.${i}.first_name`] = 'Required';
                if (!p.last_name) errors[`passengers.${i}.last_name`] = 'Required';
                if (!p.dob) errors[`passengers.${i}.dob`] = 'Required';
                if (!p.gender) errors[`passengers.${i}.gender`] = 'Required';

                if (p.first_name && !/^[A-Za-z]+$/.test(p.first_name)) {
                    errors[`passengers.${i}.first_name`] = 'Letters only';
                }

                if (p.last_name && !/^[A-Za-z]+$/.test(p.last_name)) {
                    errors[`passengers.${i}.last_name`] = 'Letters only';
                }

                const passportValues = passportFields.map((field) => (p[field] ?? '').toString().trim());
                const hasAnyPassportDetail = passportValues.some((value) => value !== '');
                const needsPassportDetails = passportRequired || hasAnyPassportDetail;

                if (needsPassportDetails) {
                    const passportMessage = passportRequired
                        ? 'Required for international flights'
                        : 'Complete all passport fields or clear all passport fields';

                    if (!p.passport_number) errors[`passengers.${i}.passport_number`] = passportMessage;
                    if (!p.passport_expiry) errors[`passengers.${i}.passport_expiry`] = passportMessage;
                    if (!p.nationality) errors[`passengers.${i}.nationality`] = passportMessage;
                    if (!p.passport_issue_country) errors[`passengers.${i}.passport_issue_country`] = passportMessage;
                }
            });
            if (!data.customer.email) errors['customer.email'] = 'Required';
            if (!data.customer.phone) errors['customer.phone'] = 'Required';
        }
        setLocalErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const nextStep = (current, next) => {
        if (validateStep(current)) {
            setActiveTab(next);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const prevStep = (prev) => {
        setActiveTab(prev);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const getSelectedService = (offerKey, code) => {
        return data.extras.selected_services.find((service) => service.offer_key === offerKey && service.code === code) ?? null;
    };

    const replaceSelectedServices = (services) => {
        setData('extras', {
            ...data.extras,
            selected_services: services,
        });
    };

    const upsertService = (offerKey, code, updater) => {
        const currentService = getSelectedService(offerKey, code) ?? { offer_key: offerKey, code, quantity: 0, passengers: [] };
        const nextService = updater(currentService);
        const nextServices = data.extras.selected_services.filter((service) => !(service.offer_key === offerKey && service.code === code));
        const normalizedPassengers = [...new Set((nextService.passengers ?? []).map((value) => Number(value)))];
        const normalizedQuantity = Math.max(0, Number(nextService.quantity ?? 0));

        if (normalizedQuantity === 0 && normalizedPassengers.length === 0) {
            replaceSelectedServices(nextServices);

            return;
        }

        replaceSelectedServices([
            ...nextServices,
            {
                code,
                offer_key: offerKey,
                quantity: normalizedQuantity,
                passengers: normalizedPassengers,
            },
        ]);
    };

    const togglePassengerService = (offerKey, service, passengerIndex) => {
        upsertService(offerKey, service.code, (currentService) => {
            const passengers = new Set((currentService.passengers ?? []).map((value) => Number(value)));

            if (passengers.has(passengerIndex)) {
                passengers.delete(passengerIndex);
            } else {
                passengers.add(passengerIndex);
            }

            return {
                ...currentService,
                quantity: service.pricing_mode === 'per_booking' ? Number(passengers.size > 0) : currentService.quantity || 1,
                passengers: [...passengers],
            };
        });
    };

    const toggleBookingService = (offerKey, service) => {
        upsertService(offerKey, service.code, (currentService) => {
            const isSelected = Number(currentService.quantity ?? 0) > 0;

            return {
                ...currentService,
                quantity: isSelected ? 0 : 1,
                passengers: [],
            };
        });
    };

    const setQuantityService = (offerKey, service, quantity) => {
        upsertService(offerKey, service.code, (currentService) => ({
            ...currentService,
            quantity: Math.min(
                service.max_quantity || quantity,
                Math.max(service.min_quantity || 0, quantity),
            ),
            passengers: currentService.passengers ?? [],
        }));
    };

    const applyServiceToAllOffers = (sourceOfferKey, service) => {
        const sourceSelection = getSelectedService(sourceOfferKey, service.code);
        const defaultPassengers = data.passengers.map((_, index) => index);
        const nextQuantity = sourceSelection
            ? Number(sourceSelection.quantity ?? 0)
            : Math.max(service.min_quantity || 0, service.default_quantity || 1);
        const nextPassengers = sourceSelection
            ? [...new Set((sourceSelection.passengers ?? []).map((value) => Number(value)))]
            : (service.pricing_mode === 'per_booking' || service.pricing_mode === 'per_kg' ? [] : defaultPassengers);

        const nextServices = data.extras.selected_services.filter((selectedService) => selectedService.code !== service.code);

        const appliedToOffers = offerContexts.map((offer) => ({
            offer_key: offer.key,
            code: service.code,
            quantity: nextQuantity,
            passengers: nextPassengers,
        }));

        replaceSelectedServices([...nextServices, ...appliedToOffers]);
    };

    const ancillaryLines = useMemo(() => {
        return offerContexts.reduce((lines, offer) => {
            const services = ancillaryCatalogByOfferMap[offer.key] ?? [];

            services.forEach((service) => {
                const selection = getSelectedService(offer.key, service.code);

                if (!selection) {
                    return;
                }

                const passengerCount = selection.passengers?.length > 0 ? selection.passengers.length : data.passengers.length;
                const segmentCount = offer.segments?.length || 1;
                const quantity = Number(selection.quantity ?? 0);

                let multiplier = 1;

                if (service.pricing_mode === 'per_kg') {
                    multiplier = quantity;
                } else if (service.pricing_mode === 'per_passenger') {
                    multiplier = passengerCount;
                } else if (service.pricing_mode === 'per_segment') {
                    multiplier = segmentCount;
                } else if (service.pricing_mode === 'per_passenger_per_segment') {
                    multiplier = passengerCount * segmentCount;
                }

                const total = Number(service.unit_price || 0) * multiplier;

                lines.push({
                    offer_key: offer.key,
                    offer_label: offer.label,
                    code: service.code,
                    label: service.label,
                    quantity,
                    total,
                });
            });

            return lines;
        }, []);
    }, [ancillaryCatalogByOfferMap, data.extras.selected_services, data.passengers.length, offerContexts]);

    const ancillaryTotal = ancillaryLines.reduce((total, line) => total + line.total, 0);

    const fetchSeatMap = async () => {
        setIsSeatMapOpen(true);

        const offersToLoad = offerContexts.filter((offer) => !seatMapByOffer[offer.key]);
        if (offersToLoad.length === 0) {
            return;
        }

        setLoadingSeatMap(true);

        try {
            const responses = await Promise.all(
                offersToLoad.map(async (offer) => {
                    const firstSegment = offer.segments?.[0] || offer.flight;
                    const flightCode = firstSegment?.flight_number;
                    const flightDate = firstSegment?.departure_time || firstSegment?.date;

                    if (!offer.providerId || !flightCode || !flightDate) {
                        return { key: offer.key, data: null };
                    }

                    const response = await axios.post(route('flights.seatmap'), {
                        provider_id: offer.providerId,
                        flight_number: flightCode,
                        date: flightDate,
                    });

                    return { key: offer.key, data: response.data };
                })
            );

            setSeatMapByOffer((previous) => {
                const next = { ...previous };

                responses.forEach(({ key, data }) => {
                    next[key] = data;
                });

                return next;
            });
        } catch (error) {
            console.error('Failed to fetch seat map', error);
        } finally {
            setLoadingSeatMap(false);
        }
    };

    const handleSeatSelection = (offerKey, seatCode) => {
        const nextSeats = { ...data.extras.seats };
        const offerSeats = { ...(nextSeats[offerKey] ?? {}) };
        const existingPaxIndex = Object.keys(offerSeats).find((index) => offerSeats[index] === seatCode);

        if (existingPaxIndex !== undefined) {
            if (Number(existingPaxIndex) === activePaxIndexForSeat) {
                delete offerSeats[activePaxIndexForSeat];
            } else {
                delete offerSeats[existingPaxIndex];
                offerSeats[activePaxIndexForSeat] = seatCode;
            }
        } else {
            offerSeats[activePaxIndexForSeat] = seatCode;
        }

        if (Object.keys(offerSeats).length === 0) {
            delete nextSeats[offerKey];
        } else {
            nextSeats[offerKey] = offerSeats;
        }

        setData('extras', {
            ...data.extras,
            seats: nextSeats,
        });

        if (activePaxIndexForSeat < data.passengers.length - 1 && !offerSeats[activePaxIndexForSeat + 1]) {
            setActivePaxIndexForSeat(activePaxIndexForSeat + 1);
        }
    };

    const generateGrid = (offerKey) => {
        const seatMapData = seatMapByOffer[offerKey];

        if (!seatMapData || !seatMapData.grid) {
            return [];
        }

        const { max_row, max_col } = seatMapData.grid;
        const grid = Array(max_row)
            .fill(null)
            .map(() => Array(max_col).fill(null));

        seatMapData.seats.forEach((seat) => {
            if (seat.row > 0 && seat.col > 0) {
                grid[seat.row - 1][max_col - seat.col] = seat;
            }
        });

        return grid;
    };

    const handleCustomerChange = (field, value) => {
        setData('customer', { ...data.customer, [field]: value });
    };

    const handlePassengerChange = (index, field, value) => {
        const updatedPassengers = [...data.passengers];
        let nextValue = value;

        if (field === 'first_name' || field === 'last_name') {
            nextValue = value.replace(/[^A-Za-z]/g, '');
        }

        updatedPassengers[index][field] = nextValue;
        
        const newData = { passengers: updatedPassengers };
        
        if (index === 0 && (field === 'first_name' || field === 'last_name')) {
            newData.customer = {
                ...data.customer,
                [field]: nextValue,
            };
        }
        
        setData((prev) => ({ ...prev, ...newData }));
    };

    const submitBooking = (event) => {
        event.preventDefault();

        post(route('flights.store'), {
            transform: (formData) => {
                const normalizedSeats = {};

                offerContexts.forEach((offer, offerIndex) => {
                    const offerSeats = formData.extras?.seats?.[offer.key] ?? {};
                    const segmentNumber = offerIndex + 1;

                    Object.entries(offerSeats).forEach(([passengerIndex, seatCode]) => {
                        if (!seatCode) {
                            return;
                        }

                        normalizedSeats[passengerIndex] = {
                            ...(normalizedSeats[passengerIndex] ?? {}),
                            [segmentNumber]: seatCode,
                        };
                    });
                });

                return {
                    ...formData,
                    extras: {
                        ...(formData.extras ?? {}),
                        seats: normalizedSeats,
                    },
                };
            },
        });
    };

    const providerPrice = Number(flight.pricing?.total || 0);
    const currency = flight.pricing?.currency || 'USD';
    const grandTotal = providerPrice + ancillaryTotal;
    const selectedSeatLabels = offerContexts.flatMap((offer) => {
        const offerSeats = data.extras.seats?.[offer.key] ?? {};

        return Object.entries(offerSeats)
            .map(([index, seatCode]) => (seatCode ? `${offer.label} - Pax ${Number(index) + 1}: ${seatCode}` : null))
            .filter(Boolean);
    });

    const formatSegmentDateTime = (value) => {
        if (!value) {
            return '--';
        }

        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return String(value);
        }

        return parsed.toLocaleString(undefined, {
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const firstSegment = offerContexts[0]?.segments?.[0] || null;
    const lastOffer = offerContexts[offerContexts.length - 1] || null;
    const lastSegment = lastOffer?.segments?.[lastOffer.segments.length - 1] || null;

    return (
        <TenantNavbarLayout>
            <Head title="Passenger Details" />

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 px-4 py-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">
                    <div>
                        <Link href={route('flights.results', { uuid })} className="mb-4 flex items-center text-sm font-bold text-muted-foreground hover:text-primary">
                            <ChevronLeft className="mr-1 h-4 w-4" /> Back to Flights
                        </Link>
                        <h2 className="text-3xl font-black tracking-tight">Complete your Booking</h2>
                        <p className="mt-1 font-medium text-muted-foreground">Fill the passenger details, seats, and airline services for your selected itinerary.</p>
                    </div>

                    {flash.error && (
                        <Card className="border border-destructive/40 bg-destructive/5">
                            <CardContent className="py-4 text-sm font-semibold text-destructive">
                                {flash.error}
                            </CardContent>
                        </Card>
                    )}

                    {flash.success && !issueCommandPreview && (
                        <Card className="border border-emerald-300 bg-emerald-50">
                            <CardContent className="py-4 text-sm font-semibold text-emerald-700">
                                {flash.success}
                            </CardContent>
                        </Card>
                    )}

                    {issueCommandPreview && (
                        <Card className="border border-primary/30 bg-primary/5">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-bold text-primary">Issuance Command Preview</CardTitle>
                                <CardDescription>This command is generated for validation only and has not been sent to the airline API.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre className="overflow-x-auto rounded-md border bg-background p-3 text-xs font-mono text-foreground">
                                    {issueCommandPreview}
                                </pre>
                            </CardContent>
                        </Card>
                    )}

                    <form onSubmit={submitBooking} className="space-y-8">
                        <Tabs value={activeTab} className="w-full">
                            <TabsList className="mb-8 grid w-full grid-cols-3 rounded-2xl border bg-muted/30 p-1">
                                <TabsTrigger value="passengers" disabled className="rounded-xl font-bold">1. Passengers</TabsTrigger>
                                <TabsTrigger value="extras" disabled className="rounded-xl font-bold">2. Extras</TabsTrigger>
                                <TabsTrigger value="review" disabled className="rounded-xl font-bold">3. Review & Confirm</TabsTrigger>
                            </TabsList>

                            <TabsContent value="passengers" className="space-y-6">
                                <Card className="border bg-muted/10">
                                    <CardContent className="pt-6">
                                        <p className="text-sm font-medium text-muted-foreground">
                                            Passport fields are {passportRequired ? 'required for this international route' : 'optional for this domestic route'}.
                                        </p>
                                        {hasPartialPassportDetails && (
                                            <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                                                You entered some passport details. Please complete all passport fields or clear them all.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>

                                {data.passengers.map((passenger, index) => (
                                    <Card key={index} className="overflow-hidden border-2 shadow-sm">
                                        <CardHeader className="border-b bg-primary/5 pb-4">
                                            <CardTitle className="flex items-center gap-2 text-lg">
                                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground">{index + 1}</span>
                                                <span className="capitalize">{passenger.type}</span> Passenger
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="grid grid-cols-1 gap-6 pt-6 md:grid-cols-3">
                                            <div className="space-y-2">
                                                <Label>First Name</Label>
                                                <Input required value={passenger.first_name} onChange={(event) => handlePassengerChange(index, 'first_name', event.target.value)} />
                                                {(localErrors[`passengers.${index}.first_name`] || errors[`passengers.${index}.first_name`]) && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.first_name`] || errors[`passengers.${index}.first_name`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Last Name</Label>
                                                <Input required value={passenger.last_name} onChange={(event) => handlePassengerChange(index, 'last_name', event.target.value)} />
                                                {(localErrors[`passengers.${index}.last_name`] || errors[`passengers.${index}.last_name`]) && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.last_name`] || errors[`passengers.${index}.last_name`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Gender</Label>
                                                <select
                                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background"
                                                    value={passenger.gender}
                                                    onChange={(event) => handlePassengerChange(index, 'gender', event.target.value)}
                                                >
                                                    <option value="M">Male</option>
                                                    <option value="F">Female</option>
                                                </select>
                                                {localErrors[`passengers.${index}.gender`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.gender`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Date of Birth</Label>
                                                <Input type="date" required value={passenger.dob} onChange={(event) => handlePassengerChange(index, 'dob', event.target.value)} />
                                                {localErrors[`passengers.${index}.dob`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.dob`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Passport Number</Label>
                                                <Input required={passportRequired} value={passenger.passport_number} onChange={(event) => handlePassengerChange(index, 'passport_number', event.target.value)} />
                                                {localErrors[`passengers.${index}.passport_number`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.passport_number`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Passport Expiry</Label>
                                                <Input type="date" required={passportRequired} value={passenger.passport_expiry} onChange={(event) => handlePassengerChange(index, 'passport_expiry', event.target.value)} />
                                                {localErrors[`passengers.${index}.passport_expiry`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.passport_expiry`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Nationality (3-letter)</Label>
                                                <Input required={passportRequired} maxLength={3} value={passenger.nationality} onChange={(event) => handlePassengerChange(index, 'nationality', event.target.value.toUpperCase())} placeholder="LBY" />
                                                {(localErrors[`passengers.${index}.nationality`] || errors[`passengers.${index}.nationality`]) && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.nationality`] || errors[`passengers.${index}.nationality`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Passport Issue Country (3-letter)</Label>
                                                <Input required={passportRequired} maxLength={3} value={passenger.passport_issue_country} onChange={(event) => handlePassengerChange(index, 'passport_issue_country', event.target.value.toUpperCase())} placeholder="LBY" />
                                                {(localErrors[`passengers.${index}.passport_issue_country`] || errors[`passengers.${index}.passport_issue_country`]) && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.passport_issue_country`] || errors[`passengers.${index}.passport_issue_country`]}</p>}
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}

                                <Card className="border-2 shadow-sm">
                                    <CardHeader className="border-b bg-muted/10 pb-4">
                                        <CardTitle className="flex items-center gap-2">
                                            <div className="rounded-full bg-primary/10 p-2"><Users className="h-5 w-5 text-primary" /></div>
                                            Contact Information
                                        </CardTitle>
                                        <CardDescription>Enter the email and phone number for the primary contact.</CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid grid-cols-1 gap-6 pt-6 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Email Address</Label>
                                            <Input required type="email" value={data.customer.email} onChange={(event) => handleCustomerChange('email', event.target.value)} />
                                            {(localErrors['customer.email'] || errors['customer.email']) && <p className="text-xs text-destructive">{localErrors['customer.email'] || errors['customer.email']}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Phone Number</Label>
                                            <Input required value={data.customer.phone} onChange={(event) => handleCustomerChange('phone', event.target.value)} />
                                            {(localErrors['customer.phone'] || errors['customer.phone']) && <p className="text-xs text-destructive">{localErrors['customer.phone'] || errors['customer.phone']}</p>}
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="flex justify-end">
                                    <Button type="button" size="lg" className="rounded-full px-8 shadow-md" onClick={() => nextStep('passengers', 'extras')}>
                                        Continue to Extras <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </div>
                            </TabsContent>

                            <TabsContent value="extras" className="space-y-6">
                                <div className="space-y-6">
                                    <div>
                                        <h3 className="text-lg font-bold">Airline Services</h3>
                                        <p className="text-sm text-muted-foreground">Select services per offer, or apply a service to all offers in this booking.</p>
                                    </div>

                                    {offerContexts.map((offer) => {
                                        const offerServices = ancillaryCatalogByOfferMap[offer.key] ?? [];
                                        const offerAirline = offer.flight?.airline_name || 'Airline';

                                        return (
                                            <div key={offer.key} className="space-y-4 rounded-2xl border bg-muted/5 p-4">
                                                <div className="flex items-center justify-between gap-3">
                                                    <div>
                                                        <p className="text-xs font-black uppercase tracking-widest text-primary">{offer.label}</p>
                                                        <h4 className="text-base font-bold">{offerAirline}</h4>
                                                    </div>
                                                    <p className="text-xs font-semibold text-muted-foreground">Provider extras</p>
                                                </div>

                                                {offerServices.length === 0 ? (
                                                    <Card className="border-dashed">
                                                        <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                                            No airline-specific services were returned for this offer.
                                                        </CardContent>
                                                    </Card>
                                                ) : (
                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                                        {offerServices.map((service) => {
                                                            const selection = getSelectedService(offer.key, service.code);
                                                            const quantity = Number(selection?.quantity ?? service.default_quantity ?? 0);
                                                            const selectedPassengers = new Set((selection?.passengers ?? []).map((value) => Number(value)));
                                                            const isQuantityService = service.type === 'baggage_increment' || service.pricing_mode === 'per_kg';
                                                            const isBookingService = service.pricing_mode === 'per_booking';

                                                            return (
                                                                <Card key={`${offer.key}-${service.code}`} className="border-2 shadow-sm">
                                                                    <CardContent className="space-y-4 p-5">
                                                                        <div className="flex items-start gap-3">
                                                                            <div className="rounded-full bg-primary/10 p-3">
                                                                                {isQuantityService ? <Briefcase className="h-5 w-5 text-primary" /> : <Settings2 className="h-5 w-5 text-primary" />}
                                                                            </div>
                                                                            <div className="min-w-0 flex-1">
                                                                                <h4 className="text-base font-bold leading-tight">{service.label}</h4>
                                                                                <p className="mt-1 text-xs text-muted-foreground">{service.description}</p>
                                                                                <p className="mt-2 text-xs font-semibold text-muted-foreground">Unit price</p>
                                                                                <p className="text-lg font-black text-primary">{Number(service.unit_price || 0).toFixed(2)} {currency}</p>
                                                                            </div>
                                                                        </div>

                                                                        {isQuantityService && (
                                                                            <div className="flex items-center justify-between rounded-xl border bg-muted/20 px-3 py-2">
                                                                                <span className="text-sm font-semibold">{service.unit_label || 'unit'} quantity</span>
                                                                                <div className="flex items-center gap-2">
                                                                                    <Button type="button" variant="outline" onClick={() => setQuantityService(offer.key, service, quantity - 1)} disabled={quantity <= (service.min_quantity || 0)}>-</Button>
                                                                                    <span className="min-w-16 text-center text-sm font-black">{quantity}</span>
                                                                                    <Button type="button" variant="outline" onClick={() => setQuantityService(offer.key, service, quantity + 1)} disabled={service.max_quantity > 0 && quantity >= service.max_quantity}>+</Button>
                                                                                </div>
                                                                            </div>
                                                                        )}

                                                                        {isBookingService && !isQuantityService && (
                                                                            <Button
                                                                                type="button"
                                                                                variant={quantity > 0 ? 'default' : 'outline'}
                                                                                className="w-full rounded-full"
                                                                                onClick={() => toggleBookingService(offer.key, service)}
                                                                            >
                                                                                {quantity > 0 ? 'Selected for this offer' : 'Add to this offer'}
                                                                            </Button>
                                                                        )}

                                                                        {!isQuantityService && !isBookingService && (
                                                                            <div className="space-y-2">
                                                                                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Select passengers</p>
                                                                                <div className="flex flex-wrap gap-2">
                                                                                    {data.passengers.map((passenger, passengerIndex) => {
                                                                                        const isSelected = selectedPassengers.has(passengerIndex);

                                                                                        return (
                                                                                            <Button
                                                                                                key={`${offer.key}-${service.code}-${passengerIndex}`}
                                                                                                type="button"
                                                                                                variant={isSelected ? 'default' : 'outline'}
                                                                                                className="rounded-full"
                                                                                                onClick={() => togglePassengerService(offer.key, service, passengerIndex)}
                                                                                            >
                                                                                                Pax {passengerIndex + 1}
                                                                                            </Button>
                                                                                        );
                                                                                    })}
                                                                                </div>
                                                                            </div>
                                                                        )}

                                                                        {isRoundTripBooking && (
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                className="h-8 px-0 text-xs font-bold text-primary"
                                                                                onClick={() => applyServiceToAllOffers(offer.key, service)}
                                                                            >
                                                                                Apply to all offers
                                                                            </Button>
                                                                        )}
                                                                    </CardContent>
                                                                </Card>
                                                            );
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>

                                <Card onClick={fetchSeatMap} className="relative cursor-pointer overflow-hidden border-2 transition-all hover:border-primary/50">
                                    <CardContent className="flex items-start gap-4 p-6">
                                        <div className="rounded-full bg-muted p-3">
                                            <Armchair className="h-6 w-6" />
                                        </div>
                                        <div>
                                            <h3 className="mb-1 text-lg font-bold">Seat Selection</h3>
                                            <p className="mb-1 text-sm text-muted-foreground">
                                                {selectedSeatLabels.length > 0 ? `${selectedSeatLabels.length} seat(s) selected across offers` : 'Standard auto-assignment applied.'}
                                            </p>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                {selectedSeatLabels.map((label) => (
                                                    <span key={label} className="rounded bg-primary/10 px-2 py-1 text-xs font-bold text-primary">
                                                        {label}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="mt-8 flex items-center justify-between border-t pt-8">
                                    <Button type="button" variant="ghost" className="font-bold" onClick={() => prevStep('passengers')}>
                                        <ChevronLeft className="mr-2 h-4 w-4" /> Back to Passengers
                                    </Button>
                                    <Button type="button" size="lg" className="rounded-full px-8 shadow-md" onClick={() => nextStep('extras', 'review')}>
                                        Continue to Review <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </div>
                            </TabsContent>

                            <TabsContent value="review" className="space-y-6">
                                <Card className="border-2 shadow-sm overflow-hidden">
                                    <CardHeader className="border-b bg-muted/10 pb-4">
                                        <CardTitle className="flex items-center gap-2">
                                            <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                                            Review your Details
                                        </CardTitle>
                                        <CardDescription>Double-check everything before finalizing the booking.</CardDescription>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <div className="p-6 space-y-8">
                                            <div>
                                                <p className="text-xs font-black uppercase tracking-widest text-primary mb-4">Passenger Details</p>
                                                <div className="grid gap-4">
                                                    {data.passengers.map((p, i) => (
                                                        <div key={i} className="flex justify-between items-center p-4 rounded-xl border bg-muted/5">
                                                            <div>
                                                                <p className="font-bold">{p.first_name} {p.last_name}</p>
                                                                <p className="text-xs text-muted-foreground uppercase font-black tracking-tighter">{p.type} • {p.gender} • DOB: {p.dob}</p>
                                                            </div>
                                                            <div className="text-right">
                                                                <p className="text-xs font-bold text-muted-foreground">Passport</p>
                                                                <p className="text-sm font-black">{p.passport_number}</p>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-2 gap-8 border-t pt-8">
                                                <div>
                                                    <p className="text-xs font-black uppercase tracking-widest text-primary mb-2">Primary Contact</p>
                                                    <p className="font-bold">{data.customer.first_name} {data.customer.last_name}</p>
                                                    <p className="text-sm text-muted-foreground">{data.customer.email}</p>
                                                    <p className="text-sm text-muted-foreground">{data.customer.phone}</p>
                                                </div>
                                                <div>
                                                    <p className="text-xs font-black uppercase tracking-widest text-primary mb-2">Selected Extras</p>
                                                    {ancillaryLines.length > 0 || selectedSeatLabels.length > 0 ? (
                                                        <ul className="space-y-1">
                                                            {ancillaryLines.map(l => (
                                                                <li key={`${l.offer_key}-${l.code}`} className="text-sm font-medium">{l.offer_label}: {l.label} ({l.quantity})</li>
                                                            ))}
                                                            {selectedSeatLabels.map(s => (
                                                                <li key={s} className="text-sm font-medium">Seat: {s}</li>
                                                            ))}
                                                        </ul>
                                                    ) : (
                                                        <p className="text-sm text-muted-foreground italic">No extras selected.</p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="mt-8 flex items-center justify-between border-t pt-8">
                                    <Button type="button" variant="ghost" className="font-bold" onClick={() => prevStep('extras')}>
                                        <ChevronLeft className="mr-2 h-4 w-4" /> Back to Extras
                                    </Button>

                                    <Button type="submit" size="lg" className="rounded-full bg-emerald-600 px-12 text-lg font-black text-white shadow-xl hover:bg-emerald-700" disabled={processing}>
                                        {processing ? 'Processing...' : 'Confirm, Pay & Issue Ticket'}
                                    </Button>
                                </div>
                            </TabsContent>
                        </Tabs>
                    </form>
                </div>

                <div className="hidden lg:block">
                    <div className="sticky top-8">
                        <Card className="overflow-hidden border-2 shadow-lg">
                            <div className="bg-primary p-6 text-primary-foreground">
                                <h3 className="mb-1 text-xl font-black">Trip Summary</h3>
                                <p className="text-sm font-medium text-primary-foreground/80">
                                    {firstSegment?.departure_airport || firstSegment?.origin || '--'} <ChevronRight className="inline h-3 w-3" /> {lastSegment?.arrival_airport || lastSegment?.destination || '--'}
                                </p>
                            </div>
                            <CardContent className="p-0">
                                <div className="space-y-4 border-b bg-muted/10 p-6">
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Booking Type</span>
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-black uppercase tracking-wider text-primary">
                                            {isRoundTripBooking ? 'Round Trip' : 'One Way'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Reservation Type</span>
                                        <span className={`rounded-full px-3 py-0.5 text-[10px] font-black uppercase tracking-widest ${reservation_type === 'NN' ? 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-600/20' : 'bg-amber-100 text-amber-700 ring-1 ring-amber-600/20'}`}>
                                            {reservation_type === 'NN' ? 'Confirmed' : 'Open'}
                                        </span>
                                    </div>
                                    <div className="mt-2 flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Passengers</span>
                                        <span className="text-right">
                                            {searchParams?.adults > 0 && <span>{searchParams.adults} Adult(s)<br /></span>}
                                            {searchParams?.children > 0 && <span>{searchParams.children} Child(ren)<br /></span>}
                                            {searchParams?.infants > 0 && <span>{searchParams.infants} Infant(s)</span>}
                                        </span>
                                    </div>

                                    <div className="space-y-3 border-t pt-4">
                                        <p className="text-xs font-black uppercase tracking-widest text-primary">Flight Itineraries</p>
                                        {offerContexts.map((offer) => (
                                            <div key={offer.key} className="rounded-xl border bg-background/80 p-3">
                                                <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">{offer.label}</p>
                                                <p className="text-sm font-black">{offer.flight?.airline_name || 'Airline'}</p>
                                                <div className="mt-2 space-y-1">
                                                    {offer.segments.map((segment, index) => (
                                                        <p key={`${offer.key}-${index}`} className="text-xs font-medium text-muted-foreground">
                                                            {segment.departure_airport || segment.origin} → {segment.arrival_airport || segment.destination} · {formatSegmentDateTime(segment.departure_time || segment.date)}
                                                        </p>
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-3 p-6">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Base Flight Fare</span>
                                        <span>{providerPrice.toFixed(2)} {currency}</span>
                                    </div>
                                    {ancillaryLines.map((line) => (
                                        <div key={`${line.offer_key}-${line.code}`} className="flex justify-between text-sm font-medium text-muted-foreground">
                                            <span>{line.offer_label}: {line.label}{line.quantity > 1 ? ` x${line.quantity}` : ''}</span>
                                            <span>+{line.total.toFixed(2)} {currency}</span>
                                        </div>
                                    ))}
                                </div>

                                <div className="flex items-end justify-between border-t bg-muted/30 p-6">
                                    <span className="font-bold text-muted-foreground">Total to Pay</span>
                                    <span className="text-3xl font-black text-primary">{grandTotal.toFixed(2)} <span className="text-sm">{currency}</span></span>
                                </div>
                            </CardContent>
                        </Card>

                        <div className="mt-6 flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50/50 p-4 text-blue-800">
                            <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" />
                            <p className="text-xs font-semibold leading-relaxed">
                                By continuing, you confirm the passenger names match the travel documents exactly and any ancillary request without an airline command code may need manual airline processing.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <Dialog open={isSeatMapOpen} onOpenChange={setIsSeatMapOpen}>
                <DialogContent className="max-h-[90vh] max-w-4xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Select Your Seats</DialogTitle>
                        <DialogDescription>Select seats for each passenger across all selected offers.</DialogDescription>
                    </DialogHeader>

                    {loadingSeatMap ? (
                        <div className="flex flex-col items-center justify-center space-y-4 py-20">
                            <Loader2 className="h-10 w-10 animate-spin text-primary" />
                            <p className="font-medium text-muted-foreground">Loading interactive seat map from airline...</p>
                        </div>
                    ) : !offerContexts.some((offer) => seatMapByOffer[offer.key]) ? (
                        <div className="py-10 text-center text-muted-foreground">Could not load seat map.</div>
                    ) : (
                        <Tabs value={activeOfferKeyForSeat} onValueChange={setActiveOfferKeyForSeat} className="mt-4 w-full">
                            <TabsList className={`grid w-full ${offerContexts.length > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
                                {offerContexts.map((offer) => (
                                    <TabsTrigger key={offer.key} value={offer.key}>{offer.label}</TabsTrigger>
                                ))}
                            </TabsList>

                            {offerContexts.map((offer) => (
                                <TabsContent key={offer.key} value={offer.key} className="mt-4">
                                    {!seatMapByOffer[offer.key] ? (
                                        <div className="py-10 text-center text-muted-foreground">Could not load seat map for this offer.</div>
                                    ) : (
                                        <div className="flex flex-col gap-8 lg:flex-row">
                                            <div className="flex flex-1 justify-center overflow-x-auto pb-8">
                                                <div className="flex flex-col gap-1 md:gap-2">
                                                    {generateGrid(offer.key).map((rowArray, rowIndex) => (
                                                        <div key={`${offer.key}-row-${rowIndex}`} className="flex min-w-max items-center justify-center gap-1 md:gap-2">
                                                            {rowArray.map((seat, colIndex) => {
                                                                if (!seat) {
                                                                    return <div key={`empty-${offer.key}-${rowIndex}-${colIndex}`} className="h-8 w-8 opacity-0" />;
                                                                }

                                                                const description = seat.description || '';
                                                                const isTextHeader = description.length === 1 && /[A-Z]/.test(description);

                                                                if (isTextHeader) {
                                                                    return (
                                                                        <div key={`header-${offer.key}-${rowIndex}-${colIndex}`} className="w-10 pb-2 text-center font-bold text-slate-500 md:w-11">
                                                                            {description}
                                                                        </div>
                                                                    );
                                                                }

                                                                if (seat.is_aisle || description.includes('WidthMarker') || description.includes('Door') || description.includes('Wing')) {
                                                                    return <div key={`spacer-${offer.key}-${rowIndex}-${colIndex}`} className="h-8 w-6 select-none text-transparent">.</div>;
                                                                }

                                                                const bookedCabin = offer.flight?.pricing?.cabin_type || offer.segments?.[0]?.cabin_type || 'Y';
                                                                const isOccupied = seat.is_occupied;
                                                                const isWrongCabin = seat.cabinType && seat.cabinType !== bookedCabin;
                                                                const assignedSeats = Object.entries(data.extras.seats?.[offer.key] ?? {});
                                                                const selectedAssignment = assignedSeats.find(([, assignedSeatCode]) => assignedSeatCode === seat.code);
                                                                const paxNumberAssigned = selectedAssignment ? Number(selectedAssignment[0]) + 1 : null;
                                                                const isSelected = Boolean(selectedAssignment);
                                                                const activePassenger = data.passengers[activePaxIndexForSeat];
                                                                const disableForInfant = seat.no_infant && activePassenger?.type === 'infant';
                                                                const isDisabled = isOccupied || disableForInfant || isWrongCabin;

                                                                let buttonClasses = 'bg-white border-slate-300 hover:border-primary text-slate-700 hover:shadow-sm';

                                                                if (isSelected) {
                                                                    buttonClasses = 'bg-primary border-primary text-primary-foreground shadow-md scale-105 z-10';
                                                                } else if (isDisabled && isWrongCabin) {
                                                                    buttonClasses = 'bg-red-50/50 border-red-200 text-red-300 cursor-not-allowed opacity-50';
                                                                } else if (isDisabled) {
                                                                    buttonClasses = 'bg-slate-200 border-slate-300 text-slate-400 cursor-not-allowed opacity-60';
                                                                }

                                                                return (
                                                                    <button
                                                                        key={`seat-${offer.key}-${rowIndex}-${colIndex}-${seat.code}`}
                                                                        type="button"
                                                                        disabled={isDisabled}
                                                                        onClick={() => handleSeatSelection(offer.key, seat.code)}
                                                                        className={`flex h-10 w-10 flex-col items-center justify-center rounded-b-sm rounded-t-lg border-2 transition-all focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-1 md:h-12 md:w-11 ${buttonClasses}`}
                                                                        title={seat.code}
                                                                    >
                                                                        {isSelected && <span className="mb-0.5 text-[10px] font-black leading-none opacity-80">P{paxNumberAssigned}</span>}
                                                                        <span className={`text-xs font-bold leading-none ${isSelected ? 'text-white' : ''}`}>{seat.code}</span>
                                                                    </button>
                                                                );
                                                            })}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="flex w-full flex-col gap-4 lg:w-1/3">
                                                <h4 className="border-b pb-2 text-lg font-bold">Assign Passengers</h4>
                                                <div className="flex flex-col gap-2">
                                                    {data.passengers.map((passenger, index) => {
                                                        const assignedSeat = data.extras.seats?.[offer.key]?.[index];
                                                        const isActive = activePaxIndexForSeat === index;

                                                        return (
                                                            <button
                                                                key={`${offer.key}-pax-${index}`}
                                                                type="button"
                                                                className={`rounded-2xl border px-4 py-3 text-left transition ${isActive ? 'border-primary bg-primary/5 shadow-sm' : 'hover:border-primary/40'}`}
                                                                onClick={() => setActivePaxIndexForSeat(index)}
                                                            >
                                                                <div className="flex items-center justify-between gap-3">
                                                                    <div>
                                                                        <p className="font-bold">Pax {index + 1}</p>
                                                                        <p className="text-sm text-muted-foreground capitalize">{passenger.type}</p>
                                                                    </div>
                                                                    <div className="text-right">
                                                                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Seat</p>
                                                                        <p className="font-black text-primary">{assignedSeat || 'Auto'}</p>
                                                                    </div>
                                                                </div>
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </TabsContent>
                            ))}
                        </Tabs>
                    )}
                </DialogContent>
            </Dialog>
        </TenantNavbarLayout>
    );
}
