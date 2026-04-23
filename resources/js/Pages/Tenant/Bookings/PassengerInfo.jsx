import React, { useMemo, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/Tabs';
import { Armchair, Briefcase, CheckCircle2, ChevronLeft, ChevronRight, Loader2, Settings2, Users } from 'lucide-react';

const PRIMARY_SEGMENT = 1;

export default function PassengerInfo({ uuid, provider_id, flight, reservation_type, is_round_trip = false, outbound_provider_id = null, return_provider_id = null, passportRequired = false, searchParams, ancillaryCatalog = [] }) {
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
    const [seatMapData, setSeatMapData] = useState(null);
    const [loadingSeatMap, setLoadingSeatMap] = useState(false);
    const [activePaxIndexForSeat, setActivePaxIndexForSeat] = useState(0);
    const [localErrors, setLocalErrors] = useState({});

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

    const enabledAncillaries = useMemo(
        () => ancillaryCatalog.filter((service) => service.enabled),
        [ancillaryCatalog],
    );

    const getSelectedService = (code) => {
        return data.extras.selected_services.find((service) => service.code === code) ?? null;
    };

    const replaceSelectedServices = (services) => {
        setData('extras', {
            ...data.extras,
            selected_services: services,
        });
    };

    const upsertService = (code, updater) => {
        const currentService = getSelectedService(code) ?? { code, quantity: 0, passengers: [] };
        const nextService = updater(currentService);
        const nextServices = data.extras.selected_services.filter((service) => service.code !== code);
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
                quantity: normalizedQuantity,
                passengers: normalizedPassengers,
            },
        ]);
    };

    const togglePassengerService = (service, passengerIndex) => {
        upsertService(service.code, (currentService) => {
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

    const toggleBookingService = (service) => {
        upsertService(service.code, (currentService) => {
            const isSelected = Number(currentService.quantity ?? 0) > 0;

            return {
                ...currentService,
                quantity: isSelected ? 0 : 1,
                passengers: [],
            };
        });
    };

    const setQuantityService = (service, quantity) => {
        upsertService(service.code, (currentService) => ({
            ...currentService,
            quantity: Math.min(
                service.max_quantity || quantity,
                Math.max(service.min_quantity || 0, quantity),
            ),
            passengers: currentService.passengers ?? [],
        }));
    };

    const ancillaryLines = useMemo(() => {
        return enabledAncillaries.reduce((lines, service) => {
            const selection = getSelectedService(service.code);

            if (!selection) {
                return lines;
            }

            const passengerCount = selection.passengers?.length > 0 ? selection.passengers.length : data.passengers.length;
            const segmentCount = flight.segments?.length || 1;
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
                code: service.code,
                label: service.label,
                quantity,
                total,
            });

            return lines;
        }, []);
    }, [data.extras.selected_services, data.passengers.length, enabledAncillaries, flight.segments]);

    const ancillaryTotal = ancillaryLines.reduce((total, line) => total + line.total, 0);

    const fetchSeatMap = async () => {
        setIsSeatMapOpen(true);

        if (seatMapData) {
            return;
        }

        setLoadingSeatMap(true);

        try {
            const flightCode = flight.segments?.[0]?.flight_number || flight.flight_number;
            const flightDate = flight.segments?.[0]?.departure_time || flight.departure_time;
            const response = await axios.post(route('flights.seatmap'), {
                provider_id,
                flight_number: flightCode,
                date: flightDate,
            });
            setSeatMapData(response.data);
        } catch (error) {
            console.error('Failed to fetch seat map', error);
        } finally {
            setLoadingSeatMap(false);
        }
    };

    const handleSeatSelection = (seatCode) => {
        const nextSeats = { ...data.extras.seats };
        const existingPaxIndex = Object.keys(nextSeats).find((index) => nextSeats[index]?.[PRIMARY_SEGMENT] === seatCode);

        if (existingPaxIndex !== undefined) {
            if (Number(existingPaxIndex) === activePaxIndexForSeat) {
                const currentSeatSelection = { ...(nextSeats[activePaxIndexForSeat] ?? {}) };
                delete currentSeatSelection[PRIMARY_SEGMENT];

                if (Object.keys(currentSeatSelection).length === 0) {
                    delete nextSeats[activePaxIndexForSeat];
                } else {
                    nextSeats[activePaxIndexForSeat] = currentSeatSelection;
                }
            } else {
                delete nextSeats[existingPaxIndex];
                nextSeats[activePaxIndexForSeat] = {
                    ...(nextSeats[activePaxIndexForSeat] ?? {}),
                    [PRIMARY_SEGMENT]: seatCode,
                };
            }
        } else {
            nextSeats[activePaxIndexForSeat] = {
                ...(nextSeats[activePaxIndexForSeat] ?? {}),
                [PRIMARY_SEGMENT]: seatCode,
            };
        }

        setData('extras', {
            ...data.extras,
            seats: nextSeats,
        });

        if (activePaxIndexForSeat < data.passengers.length - 1 && !nextSeats[activePaxIndexForSeat + 1]?.[PRIMARY_SEGMENT]) {
            setActivePaxIndexForSeat(activePaxIndexForSeat + 1);
        }
    };

    const generateGrid = () => {
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
        post(route('flights.store'));
    };

    const providerPrice = Number(flight.pricing?.total || 0);
    const currency = flight.pricing?.currency || 'USD';
    const grandTotal = providerPrice + ancillaryTotal;
    const selectedSeatLabels = Object.entries(data.extras.seats)
        .map(([index, seats]) => seats?.[PRIMARY_SEGMENT] ? `Pax ${Number(index) + 1}: ${seats[PRIMARY_SEGMENT]}` : null)
        .filter(Boolean);

    return (
        <TenantLayout>
            <Head title="Passenger Details" />

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 px-4 py-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">
                    <div>
                        <Link href={route('flights.results', { uuid })} className="mb-4 flex items-center text-sm font-bold text-muted-foreground hover:text-primary">
                            <ChevronLeft className="mr-1 h-4 w-4" /> Back to Flights
                        </Link>
                        <h2 className="text-3xl font-black tracking-tight">Complete your Booking</h2>
                        <p className="mt-1 font-medium text-muted-foreground">Fill the passenger details, seats, and airline services for this offer.</p>
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
                                <div className="space-y-4">
                                    <div>
                                        <h3 className="text-lg font-bold">Airline Services</h3>
                                        <p className="text-sm text-muted-foreground">The available services come from the selected airline account. Services without a command template are stored for pricing and support follow-up until the airline-specific code is configured.</p>
                                    </div>

                                    {enabledAncillaries.length === 0 && (
                                        <Card className="border-dashed">
                                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                                No airline-specific extra services are configured for this provider yet.
                                            </CardContent>
                                        </Card>
                                    )}

                                    <div className="grid gap-4">
                                        {enabledAncillaries.map((service) => {
                                            const selection = getSelectedService(service.code);
                                            const quantity = Number(selection?.quantity ?? service.default_quantity ?? 0);
                                            const selectedPassengers = new Set((selection?.passengers ?? []).map((value) => Number(value)));
                                            const isQuantityService = service.type === 'baggage_increment' || service.pricing_mode === 'per_kg';
                                            const isBookingService = service.pricing_mode === 'per_booking';

                                            return (
                                                <Card key={service.code} className="border-2 shadow-sm">
                                                    <CardContent className="space-y-4 p-6">
                                                        <div className="flex items-start gap-4">
                                                            <div className="rounded-full bg-primary/10 p-3">
                                                                {isQuantityService ? <Briefcase className="h-6 w-6 text-primary" /> : <Settings2 className="h-6 w-6 text-primary" />}
                                                            </div>
                                                            <div className="flex-1 space-y-2">
                                                                <div className="flex items-start justify-between gap-4">
                                                                    <div>
                                                                        <h4 className="text-lg font-bold">{service.label}</h4>
                                                                        <p className="text-sm text-muted-foreground">{service.description}</p>
                                                                    </div>
                                                                    <div className="text-right">
                                                                        <p className="text-sm font-semibold text-muted-foreground">Unit price</p>
                                                                        <p className="text-lg font-black text-primary">{Number(service.unit_price || 0).toFixed(2)} {currency}</p>
                                                                    </div>
                                                                </div>

                                                                {!service.command_template && (
                                                                    <p className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                                                                        Airline command template is not configured yet. This selection will still be stored with the booking for pricing and manual handling.
                                                                    </p>
                                                                )}

                                                                {isQuantityService && (
                                                                    <div className="flex items-center justify-between rounded-2xl border bg-muted/20 px-4 py-3">
                                                                        <div>
                                                                            <p className="font-semibold">Incremental baggage</p>
                                                                            <p className="text-xs text-muted-foreground">Sold in {service.unit_label || 'units'} and priced by quantity.</p>
                                                                        </div>
                                                                        <div className="flex items-center gap-3">
                                                                            <Button type="button" variant="outline" onClick={() => setQuantityService(service, quantity - 1)} disabled={quantity <= (service.min_quantity || 0)}>-</Button>
                                                                            <span className="min-w-20 text-center text-lg font-black">{quantity} {service.unit_label || 'unit'}</span>
                                                                            <Button type="button" variant="outline" onClick={() => setQuantityService(service, quantity + 1)} disabled={service.max_quantity > 0 && quantity >= service.max_quantity}>+</Button>
                                                                        </div>
                                                                    </div>
                                                                )}

                                                                {isBookingService && !isQuantityService && (
                                                                    <Button
                                                                        type="button"
                                                                        variant={quantity > 0 ? 'default' : 'outline'}
                                                                        className="rounded-full"
                                                                        onClick={() => toggleBookingService(service)}
                                                                    >
                                                                        {quantity > 0 ? 'Selected for this booking' : 'Add to booking'}
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
                                                                                        key={`${service.code}-${passengerIndex}`}
                                                                                        type="button"
                                                                                        variant={isSelected ? 'default' : 'outline'}
                                                                                        className="rounded-full"
                                                                                        onClick={() => togglePassengerService(service, passengerIndex)}
                                                                                    >
                                                                                        Pax {passengerIndex + 1} ({passenger.type})
                                                                                    </Button>
                                                                                );
                                                                            })}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
                                    </div>
                                </div>

                                <Card onClick={fetchSeatMap} className="relative cursor-pointer overflow-hidden border-2 transition-all hover:border-primary/50">
                                    <div className="absolute inset-0 z-10 flex flex-col items-center justify-center bg-muted/50 opacity-0 backdrop-blur-[1px] transition-opacity hover:opacity-100">
                                        <Button type="button" variant="secondary" className="font-bold shadow-lg">Select Seats (Opens Map)</Button>
                                    </div>
                                    <CardContent className="flex items-start gap-4 p-6">
                                        <div className="rounded-full bg-muted p-3">
                                            <Armchair className="h-6 w-6" />
                                        </div>
                                        <div>
                                            <h3 className="mb-1 text-lg font-bold">Seat Selection</h3>
                                            <p className="mb-1 text-sm text-muted-foreground">
                                                {selectedSeatLabels.length > 0 ? `${selectedSeatLabels.length} seat(s) selected` : 'Standard auto-assignment applied.'}
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
                                                                <li key={l.code} className="text-sm font-medium">{l.label} ({l.quantity})</li>
                                                            ))}
                                                            {selectedSeatLabels.map(s => (
                                                                <li key={s} className="text-sm font-medium">Seat: {s.split(': ')[1]}</li>
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
                                    {flight.departure_airport} <ChevronRight className="inline h-3 w-3" /> {flight.arrival_airport}
                                </p>
                            </div>
                            <CardContent className="p-0">
                                <div className="space-y-4 border-b bg-muted/10 p-6">
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Airline</span>
                                        <span>{flight.airline_name}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Flight</span>
                                        <span>{flight.flight_number}</span>
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
                                </div>

                                <div className="space-y-3 p-6">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Base Flight Fare</span>
                                        <span>{providerPrice.toFixed(2)} {currency}</span>
                                    </div>
                                    {ancillaryLines.map((line) => (
                                        <div key={line.code} className="flex justify-between text-sm font-medium text-muted-foreground">
                                            <span>{line.label}{line.quantity > 1 ? ` x${line.quantity}` : ''}</span>
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
                        <DialogDescription>Select seats for each passenger on flight {flight.flight_number}.</DialogDescription>
                    </DialogHeader>

                    {loadingSeatMap ? (
                        <div className="flex flex-col items-center justify-center space-y-4 py-20">
                            <Loader2 className="h-10 w-10 animate-spin text-primary" />
                            <p className="font-medium text-muted-foreground">Loading interactive seat map from airline...</p>
                        </div>
                    ) : !seatMapData ? (
                        <div className="py-10 text-center text-muted-foreground">Could not load seat map.</div>
                    ) : (
                        <div className="mt-4 flex flex-col gap-8 lg:flex-row">
                            <div className="flex flex-1 justify-center overflow-x-auto pb-8">
                                <div className="flex flex-col gap-1 md:gap-2">
                                    {generateGrid().map((rowArray, rowIndex) => (
                                        <div key={`row-${rowIndex}`} className="flex min-w-max items-center justify-center gap-1 md:gap-2">
                                            {rowArray.map((seat, colIndex) => {
                                                if (!seat) {
                                                    return <div key={`empty-${rowIndex}-${colIndex}`} className="h-8 w-8 opacity-0" />;
                                                }

                                                const description = seat.description || '';
                                                const isTextHeader = description.length === 1 && /[A-Z]/.test(description);

                                                if (isTextHeader) {
                                                    return (
                                                        <div key={`header-${rowIndex}-${colIndex}`} className="w-10 pb-2 text-center font-bold text-slate-500 md:w-11">
                                                            {description}
                                                        </div>
                                                    );
                                                }

                                                if (seat.is_aisle || description.includes('WidthMarker') || description.includes('Door') || description.includes('Wing')) {
                                                    return <div key={`spacer-${rowIndex}-${colIndex}`} className="h-8 w-6 select-none text-transparent">.</div>;
                                                }

                                                const bookedCabin = flight.pricing?.cabin_type || flight.segments?.[0]?.cabin_type || 'Y';
                                                const isOccupied = seat.is_occupied;
                                                const isWrongCabin = seat.cabinType && seat.cabinType !== bookedCabin;
                                                const assignedSeats = Object.entries(data.extras.seats);
                                                const selectedAssignment = assignedSeats.find(([, seats]) => seats?.[PRIMARY_SEGMENT] === seat.code);
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
                                                        key={`seat-${rowIndex}-${colIndex}-${seat.code}`}
                                                        type="button"
                                                        disabled={isDisabled}
                                                        onClick={() => handleSeatSelection(seat.code)}
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
                                        const assignedSeat = data.extras.seats[index]?.[PRIMARY_SEGMENT];
                                        const isActive = activePaxIndexForSeat === index;

                                        return (
                                            <button
                                                key={index}
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
                </DialogContent>
            </Dialog>
        </TenantLayout>
    );
}
