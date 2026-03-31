import React, { useState } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import axios from 'axios';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/Components/ui/Card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/ui/Tabs";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/Components/ui/Dialog";
import { Plane, Users, CheckCircle2, ChevronRight, Briefcase, Utensils, Armchair, ChevronLeft, Loader2 } from "lucide-react";

export default function PassengerInfo({ uuid, provider_id, flight, searchParams }) {
    // Generate empty passenger forms based on search params
    const initialPassengers = [];
    const types = [
        { type: 'adult', count: searchParams?.adults || 1 },
        { type: 'child', count: searchParams?.children || 0 },
        { type: 'infant', count: searchParams?.infants || 0 },
    ];

    types.forEach(({ type, count }) => {
        for (let i = 0; i < count; i++) {
            initialPassengers.push({
                type: type,
                first_name: '',
                last_name: '',
                dob: '',
                gender: 'M',
                passport_number: '',
                passport_expiry: ''
            });
        }
    });

    const { data, setData, post, processing, errors } = useForm({
        uuid: uuid,
        provider_id: provider_id,
        flight: flight,
        customer: {
            first_name: '',
            last_name: '',
            email: '',
            phone: ''
        },
        passengers: initialPassengers,
        // Mock sub-data for extras
        extras: {
            bags: 0,
            meals: false,
            seats: {} // e.g. { 0: '1F', 1: '1D' }
        }
    });

    const [activeTab, setActiveTab] = useState('contact');

    // Seat Map State
    const [isSeatMapOpen, setIsSeatMapOpen] = useState(false);
    const [seatMapData, setSeatMapData] = useState(null);
    const [loadingSeatMap, setLoadingSeatMap] = useState(false);
    const [activePaxIndexForSeat, setActivePaxIndexForSeat] = useState(0);

    const fetchSeatMap = async () => {
        setIsSeatMapOpen(true);
        if (seatMapData) return;
        
        setLoadingSeatMap(true);
        try {
            const flightCode = flight.segments?.[0]?.flight_number || flight.flight_number;
            const flightDate = flight.segments?.[0]?.departure_time || flight.departure_time;
            const response = await axios.post(route('bookings.seatmap'), {
                provider_id: provider_id,
                flight_number: flightCode,
                date: flightDate
            });
            setSeatMapData(response.data);
        } catch (error) {
            console.error("Failed to fetch seat map", error);
        } finally {
            setLoadingSeatMap(false);
        }
    };

    const handleSeatSelection = (seatCode) => {
        const newSeats = { ...data.extras.seats };
        
        // Check if someone else already has this specific seat (allow deselection or swap)
        const existingPaxIndex = Object.keys(newSeats).find(idx => newSeats[idx] === seatCode);
        if (existingPaxIndex !== undefined) {
            if (parseInt(existingPaxIndex) === activePaxIndexForSeat) {
                // Deselect if already my seat
                delete newSeats[activePaxIndexForSeat];
            } else {
                // Swap or error (For now just re-assigning it to currently active pax and removing from the other)
                delete newSeats[existingPaxIndex];
                newSeats[activePaxIndexForSeat] = seatCode;
            }
        } else {
            newSeats[activePaxIndexForSeat] = seatCode;
        }

        setData('extras', { ...data.extras, seats: newSeats });
        
        // Auto-advance to next passenger if there are more
        if (activePaxIndexForSeat < data.passengers.length - 1 && !newSeats[activePaxIndexForSeat + 1]) {
            setActivePaxIndexForSeat(activePaxIndexForSeat + 1);
        }
    };

    // Helper to generate a 2D grid from seats array
    const generateGrid = () => {
        if (!seatMapData || !seatMapData.grid) return [];
        const { max_row, max_col } = seatMapData.grid;
        
        // max_row x max_col empty matrix
        let grid = Array(max_row).fill(null).map(() => Array(max_col).fill(null));
        
        seatMapData.seats.forEach(seat => {
            if (seat.row > 0 && seat.col > 0) {
                // Invert columns so Col 9 is index 0 (left-most), Col 1 is max_col - 1 (right-most)
                grid[seat.row - 1][max_col - seat.col] = seat;
            }
        });
        
        return grid;
    };

    const handleCustomerChange = (field, value) => {
        setData('customer', { ...data.customer, [field]: value });
    };

    const handlePassengerChange = (index, field, value) => {
        const updated = [...data.passengers];
        updated[index][field] = value;
        setData('passengers', updated);
    };

    const submitBooking = (e) => {
        e.preventDefault();
        post(route('bookings.store'));
    };

    // Derived flight data
    const providerPrice = flight.pricing?.total || 0;
    const currency = flight.pricing?.currency || 'USD';
    const mockExtrasTotal = (data.extras.bags * 50) + (data.extras.meals ? 25 : 0);
    const grandTotal = parseFloat(providerPrice) + mockExtrasTotal;

    return (
        <TenantLayout>
            <Head title="Passenger Details" />

            <div className="max-w-7xl mx-auto py-8 px-4 grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                {/* Main Form Area */}
                <div className="lg:col-span-2 space-y-8">
                    <div>
                        <Link href={route('bookings.results', { uuid })} className="text-sm font-bold text-muted-foreground hover:text-primary flex items-center mb-4">
                            <ChevronLeft className="h-4 w-4 mr-1" /> Back to Flights
                        </Link>
                        <h2 className="text-3xl font-black tracking-tight">Complete your Booking</h2>
                        <p className="text-muted-foreground font-medium mt-1">Please fill in the passenger details matching their travel documents.</p>
                    </div>

                    <form onSubmit={submitBooking} className="space-y-8">
                        
                        <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
                            <TabsList className="grid w-full grid-cols-3 mb-8 bg-muted/30 p-1 rounded-2xl border">
                                <TabsTrigger value="contact" className="rounded-xl font-bold">1. Contact Info</TabsTrigger>
                                <TabsTrigger value="passengers" className="rounded-xl font-bold">2. Passengers</TabsTrigger>
                                <TabsTrigger value="extras" className="rounded-xl font-bold">3. Extras</TabsTrigger>
                            </TabsList>
                            
                            <TabsContent value="contact" className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                                <Card className="border-2 shadow-sm">
                                    <CardHeader className="bg-muted/10 border-b pb-4">
                                        <CardTitle className="flex items-center gap-2">
                                            <div className="bg-primary/10 p-2 rounded-full"><Users className="h-5 w-5 text-primary" /></div>
                                            Primary Contact
                                        </CardTitle>
                                        <CardDescription>We'll send the booking confirmation and tickets to this address.</CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6">
                                        <div className="space-y-2">
                                            <Label>First Name</Label>
                                            <Input required value={data.customer.first_name} onChange={e => handleCustomerChange('first_name', e.target.value)} placeholder="e.g. John" />
                                            {errors['customer.first_name'] && <p className="text-xs text-destructive">{errors['customer.first_name']}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Last Name</Label>
                                            <Input required value={data.customer.last_name} onChange={e => handleCustomerChange('last_name', e.target.value)} placeholder="e.g. Doe" />
                                            {errors['customer.last_name'] && <p className="text-xs text-destructive">{errors['customer.last_name']}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Email Address</Label>
                                            <Input required type="email" value={data.customer.email} onChange={e => handleCustomerChange('email', e.target.value)} placeholder="john@example.com" />
                                            {errors['customer.email'] && <p className="text-xs text-destructive">{errors['customer.email']}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Phone Number</Label>
                                            <Input required type="tel" value={data.customer.phone} onChange={e => handleCustomerChange('phone', e.target.value)} placeholder="+1 234 567 890" />
                                            {errors['customer.phone'] && <p className="text-xs text-destructive">{errors['customer.phone']}</p>}
                                        </div>
                                    </CardContent>
                                </Card>
                                <div className="flex justify-end">
                                    <Button type="button" size="lg" className="rounded-full shadow-md px-8" onClick={() => setActiveTab('passengers')}>
                                        Continue to Passengers <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </div>
                            </TabsContent>

                            <TabsContent value="passengers" className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                                {data.passengers.map((pax, index) => (
                                    <Card key={index} className="border-2 shadow-sm overflow-hidden">
                                        <CardHeader className="bg-primary/5 border-b pb-4">
                                            <CardTitle className="text-lg flex items-center gap-2">
                                                <span className="bg-primary text-primary-foreground w-6 h-6 rounded-full flex items-center justify-center text-xs">{index + 1}</span>
                                                <span className="capitalize">{pax.type}</span> Passenger
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6">
                                            <div className="space-y-2">
                                                <Label>First Name</Label>
                                                <Input required value={pax.first_name} onChange={e => handlePassengerChange(index, 'first_name', e.target.value)} />
                                                {errors[`passengers.${index}.first_name`] && <p className="text-xs text-destructive">{errors[`passengers.${index}.first_name`]}</p>}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Last Name</Label>
                                                <Input required value={pax.last_name} onChange={e => handlePassengerChange(index, 'last_name', e.target.value)} />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Gender</Label>
                                                <select
                                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
                                                    value={pax.gender}
                                                    onChange={e => handlePassengerChange(index, 'gender', e.target.value)}
                                                >
                                                    <option value="M">Male</option>
                                                    <option value="F">Female</option>
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Date of Birth</Label>
                                                <Input required type="date" value={pax.dob} onChange={e => handlePassengerChange(index, 'dob', e.target.value)} />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Passport Number</Label>
                                                <Input required value={pax.passport_number} onChange={e => handlePassengerChange(index, 'passport_number', e.target.value)} placeholder="Optional for domestic" />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Passport Expiry</Label>
                                                <Input type="date" value={pax.passport_expiry} onChange={e => handlePassengerChange(index, 'passport_expiry', e.target.value)} />
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                                <div className="flex justify-between items-center">
                                    <Button type="button" variant="ghost" className="font-bold" onClick={() => setActiveTab('contact')}>
                                        <ChevronLeft className="mr-2 h-4 w-4" /> Back to Contact
                                    </Button>
                                    <Button type="button" size="lg" className="rounded-full shadow-md px-8" onClick={() => setActiveTab('extras')}>
                                        Continue to Extras <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </div>
                            </TabsContent>

                            <TabsContent value="extras" className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                                <div className="grid md:grid-cols-2 gap-6">
                                    <Card className={`border-2 cursor-pointer transition-all ${data.extras.bags > 0 ? 'border-primary bg-primary/5' : 'hover:border-primary/50'}`} 
                                          onClick={() => setData('extras', { ...data.extras, bags: data.extras.bags === 0 ? 1 : 0 })}>
                                        <CardContent className="p-6 flex items-start gap-4">
                                            <div className={`p-3 rounded-full ${data.extras.bags > 0 ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}>
                                                <Briefcase className="h-6 w-6" />
                                            </div>
                                            <div>
                                                <h3 className="font-bold text-lg mb-1">Checked Baggage</h3>
                                                <p className="text-sm text-muted-foreground mb-3">Add a 23kg checked bag.</p>
                                                <p className="font-black text-primary">+50.00 {currency}</p>
                                                {data.extras.bags > 0 && <span className="inline-block mt-2 text-xs font-bold text-primary bg-primary/20 px-2 py-1 rounded">Selected</span>}
                                            </div>
                                        </CardContent>
                                    </Card>
                                    
                                    <Card className={`border-2 cursor-pointer transition-all ${data.extras.meals ? 'border-primary bg-primary/5' : 'hover:border-primary/50'}`} 
                                          onClick={() => setData('extras', { ...data.extras, meals: !data.extras.meals })}>
                                        <CardContent className="p-6 flex items-start gap-4">
                                            <div className={`p-3 rounded-full ${data.extras.meals ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}>
                                                <Utensils className="h-6 w-6" />
                                            </div>
                                            <div>
                                                <h3 className="font-bold text-lg mb-1">In-Flight Meal</h3>
                                                <p className="text-sm text-muted-foreground mb-3">Premium hot meal selection.</p>
                                                <p className="font-black text-primary">+25.00 {currency}</p>
                                                {data.extras.meals && <span className="inline-block mt-2 text-xs font-bold text-primary bg-primary/20 px-2 py-1 rounded">Selected</span>}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card onClick={fetchSeatMap} className="col-span-full border-2 hover:border-primary/50 transition-all overflow-hidden relative group cursor-pointer">
                                        <div className="absolute inset-0 bg-muted/50 z-10 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity backdrop-blur-[1px]">
                                            <Button variant="secondary" className="shadow-lg font-bold">Select Seats (Opens Map)</Button>
                                        </div>
                                        <CardContent className="p-6 flex items-start gap-4">
                                            <div className="p-3 rounded-full bg-muted">
                                                <Armchair className="h-6 w-6" />
                                            </div>
                                            <div>
                                                <h3 className="font-bold text-lg mb-1">Seat Selection</h3>
                                                <p className="text-sm text-muted-foreground mb-1">
                                                    {Object.keys(data.extras.seats).length > 0 
                                                        ? `${Object.keys(data.extras.seats).length} Seats Selected` 
                                                        : 'Standard auto-assignment applied.'}
                                                </p>
                                                <div className="flex gap-2 mt-2">
                                                    {Object.entries(data.extras.seats).map(([idx, code]) => (
                                                        <span key={idx} className="bg-primary/10 text-primary text-xs font-bold px-2 py-1 rounded">
                                                            Pax {parseInt(idx) + 1}: {code}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>
                                <div className="flex justify-between items-center pt-8 border-t mt-8">
                                    <Button type="button" variant="ghost" className="font-bold" onClick={() => setActiveTab('passengers')}>
                                        <ChevronLeft className="mr-2 h-4 w-4" /> Back to Passengers
                                    </Button>
                                    
                                    <Button 
                                        type="submit" 
                                        size="lg" 
                                        className="rounded-full shadow-xl px-12 text-lg font-black bg-emerald-600 hover:bg-emerald-700 text-white" 
                                        disabled={processing}
                                    >
                                        {processing ? 'Processing...' : 'Confirm & Proceed to Payment'}
                                    </Button>
                                </div>
                            </TabsContent>
                        </Tabs>
                    </form>
                </div>

                {/* Sidebar Summary */}
                <div className="hidden lg:block">
                    <div className="sticky top-8">
                        <Card className="border-2 shadow-lg overflow-hidden">
                            <div className="bg-primary p-6 text-primary-foreground">
                                <h3 className="font-black text-xl mb-1">Trip Summary</h3>
                                <p className="text-primary-foreground/80 font-medium text-sm">
                                    {flight.departure_airport} <ChevronRight className="inline h-3 w-3" /> {flight.arrival_airport}
                                </p>
                            </div>
                            <CardContent className="p-0">
                                <div className="p-6 border-b bg-muted/10 space-y-4">
                                    <div className="flex justify-between items-center text-sm font-bold">
                                        <span className="text-muted-foreground">Airline</span>
                                        <span>{flight.airline_name}</span>
                                    </div>
                                    <div className="flex justify-between items-center text-sm font-bold">
                                        <span className="text-muted-foreground">Flight</span>
                                        <span>{flight.flight_number}</span>
                                    </div>
                                    <div className="flex justify-between text-sm font-bold mt-2">
                                        <span className="text-muted-foreground">Passengers</span>
                                        <span className="text-right">
                                            {searchParams.adults > 0 && <span>{searchParams.adults} Adult(s)<br/></span>}
                                            {searchParams.children > 0 && <span>{searchParams.children} Child(ren)<br/></span>}
                                            {searchParams.infants > 0 && <span>{searchParams.infants} Infant(s)</span>}
                                        </span>
                                    </div>
                                </div>

                                <div className="p-6 space-y-3">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Base Flight Fare</span>
                                        <span>{providerPrice} {currency}</span>
                                    </div>
                                    {data.extras.bags > 0 && (
                                        <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                            <span>Checked Baggage</span>
                                            <span>+50.00 {currency}</span>
                                        </div>
                                    )}
                                    {data.extras.meals && (
                                        <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                            <span>In-Flight Meals</span>
                                            <span>+25.00 {currency}</span>
                                        </div>
                                    )}
                                </div>

                                <div className="p-6 bg-muted/30 border-t flex justify-between items-end">
                                    <span className="font-bold text-muted-foreground">Total to Pay</span>
                                    <span className="text-3xl font-black text-primary">{grandTotal.toFixed(2)} <span className="text-sm">{currency}</span></span>
                                </div>
                            </CardContent>
                        </Card>
                        
                        <div className="mt-6 flex items-start gap-3 bg-blue-50/50 p-4 border border-blue-100 rounded-2xl text-blue-800">
                            <CheckCircle2 className="h-5 w-5 shrink-0 mt-0.5" />
                            <p className="text-xs font-semibold leading-relaxed">
                                By continuing, you agree to our Terms of Booking and confirm that your name matches your government-issued ID exactly.
                            </p>
                        </div>
                    </div>
                </div>
                
            </div>

            <Dialog open={isSeatMapOpen} onOpenChange={setIsSeatMapOpen}>
                <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Select Your Seats</DialogTitle>
                        <DialogDescription>
                            Select seats for each passenger on flight {flight.flight_number}.
                        </DialogDescription>
                    </DialogHeader>

                    {loadingSeatMap ? (
                        <div className="py-20 flex flex-col items-center justify-center space-y-4">
                            <Loader2 className="w-10 h-10 animate-spin text-primary" />
                            <p className="font-medium text-muted-foreground">Loading interactive seat map from airline...</p>
                        </div>
                    ) : !seatMapData ? (
                        <div className="py-10 text-center text-muted-foreground">
                            Could not load seat map.
                        </div>
                    ) : (
                        <div className="flex flex-col lg:flex-row gap-8 mt-4">
                            {/* Plane Grid Wrapper */}
                            <div className="flex-1 overflow-x-auto flex justify-center pb-8">
                                <div className="flex flex-col gap-1 md:gap-2">
                                    {generateGrid().map((rowArray, rowIndex) => (
                                        <div key={`row-${rowIndex}`} className="flex justify-center gap-1 md:gap-2 items-center min-w-max">
                                            {rowArray.map((seat, colIndex) => {
                                                if (!seat) return <div key={`empty-${rowIndex}-${colIndex}`} className="w-8 h-8 opacity-0"></div>;
                                                
                                                // Handle structural columns or non-seats
                                                const desc = seat.description || '';
                                                const isTextHeader = desc.length === 1 && /[A-Z]/.test(desc);
                                                
                                                if (isTextHeader) {
                                                    return (
                                                        <div key={`header-${rowIndex}-${colIndex}`} className="w-10 md:w-11 text-center font-bold text-slate-500 pb-2">
                                                            {desc}
                                                        </div>
                                                    );
                                                }

                                                if (seat.is_aisle || desc.includes('WidthMarker') || desc.includes('Door') || desc.includes('Wing')) {
                                                    // Render aisle or empty spacing
                                                    return <div key={`spacer-${rowIndex}-${colIndex}`} className="w-6 h-8 text-transparent select-none">.</div>;
                                                }

                                                // Determine booked cabin class of the passenger (Fallback to Economy 'Y' if not found)
                                                const bookedCabin = flight.pricing?.cabin_type || (flight.segments && flight.segments[0]?.cabin_type) || 'Y';
                                                
                                                const isOccupied = seat.is_occupied;
                                                const isWrongCabin = seat.cabinType && seat.cabinType !== bookedCabin;
                                                const isSelectedByMe = Object.values(data.extras.seats).includes(seat.code);
                                                const paxIndexFound = Object.keys(data.extras.seats).find(k => data.extras.seats[k] === seat.code);
                                                const paxNumberAssigned = paxIndexFound ? parseInt(paxIndexFound) + 1 : null;
                                                
                                                const isEmergency = desc === 'EmergencySeat';
                                                
                                                // Check infant rule
                                                const activePax = data.passengers[activePaxIndexForSeat];
                                                const isInfant = activePax?.type === 'infant';
                                                const disableForInfant = seat.no_infant && isInfant;

                                                const isDisabled = isOccupied || disableForInfant || isWrongCabin;
                                                
                                                // Determine styles based on state
                                                let buttonClasses = 'bg-white border-slate-300 hover:border-primary text-slate-700 hover:shadow-sm';
                                                
                                                if (isSelectedByMe) {
                                                    buttonClasses = 'bg-primary border-primary text-primary-foreground shadow-md scale-105 z-10';
                                                } else if (isDisabled && isWrongCabin) {
                                                    buttonClasses = 'bg-red-50/50 border-red-200 text-red-300 cursor-not-allowed opacity-50';
                                                } else if (isDisabled) {
                                                    buttonClasses = 'bg-slate-200 border-slate-300 text-slate-400 cursor-not-allowed opacity-60 bg-[url("data:image/svg+xml,%3Csvg width=\'6\' height=\'6\' viewBox=\'0 0 6 6\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'%23cbd5e1\' fill-opacity=\'1\' fill-rule=\'evenodd\'%3E%3Cpath d=\'M5 0h1L0 6V5zM6 5v1H5z\'/%3E%3C/g%3E%3C/svg%3E")]';
                                                } else if (isEmergency) {
                                                    buttonClasses = 'bg-orange-50 border-orange-300 hover:border-orange-500 text-orange-800 hover:shadow-sm';
                                                }
                                                
                                                // Generate title
                                                let tooltipTitle = `${seat.code} - ${desc || seat.cabinType + ' Class'}`;
                                                if (disableForInfant) tooltipTitle += ' (No Infants Allowed)';
                                                if (isWrongCabin) tooltipTitle += ` (Not applicable for your booked ${bookedCabin} Class)`;

                                                return (
                                                    <button
                                                        key={`seat-${rowIndex}-${colIndex}-${seat.code}`}
                                                        disabled={isDisabled}
                                                        onClick={() => handleSeatSelection(seat.code)}
                                                        className={`w-10 h-10 md:w-11 md:h-12 rounded-t-lg rounded-b-sm border-2 flex flex-col items-center justify-center transition-all focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-primary ${buttonClasses}`}
                                                        title={tooltipTitle}
                                                    >
                                                        {isSelectedByMe && <span className="text-[10px] font-black tracking-tight leading-none opacity-80 mb-0.5">P{paxNumberAssigned}</span>}
                                                        <span className={`text-xs font-bold leading-none ${isSelectedByMe ? 'text-white' : ''}`}>{seat.code}</span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    ))}
                                </div>
                            </div>
                            
                            {/* Passenger Selector Sidebar */}
                            <div className="w-full lg:w-1/3 flex flex-col gap-4">
                                <h4 className="font-bold text-lg mb-2 border-b pb-2">Assign Passengers</h4>
                                <div className="flex flex-col gap-2">
                                    {data.passengers.map((pax, idx) => {
                                        const assignedSeat = data.extras.seats[idx];
                                        const isActive = activePaxIndexForSeat === idx;
                                        return (
                                            <button
                                                key={idx}
                                                type="button"
                                                onClick={() => setActivePaxIndexForSeat(idx)}
                                                className={`flex items-center justify-between p-3 rounded-xl border-2 transition-alltext-left ${
                                                    isActive 
                                                        ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary' 
                                                        : 'border-transparent hover:bg-muted'
                                                }`}
                                            >
                                                <div className="flex items-center gap-3">
                                                    <span className={`flex items-center justify-center w-8 h-8 rounded-full font-bold text-sm ${isActive ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}>
                                                        {idx + 1}
                                                    </span>
                                                    <div>
                                                        <div className={`font-bold text-sm ${isActive ? 'text-primary' : ''}`}>
                                                            {pax.first_name || `Passenger ${idx + 1}`}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground capitalize">{pax.type}</div>
                                                    </div>
                                                </div>
                                                <div className={`font-mono font-bold text-base bg-white border px-2 py-1 rounded shadow-sm ${assignedSeat ? 'text-emerald-600 border-emerald-200' : 'text-slate-300'}`}>
                                                    {assignedSeat || '---'}
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>

                                <div className="mt-8 p-4 bg-muted/50 rounded-xl space-y-3">
                                    <h5 className="font-bold text-sm">Key</h5>
                                    <div className="flex items-center gap-2 text-sm"><div className="w-4 h-4 rounded-t bg-white border-2 border-slate-300"></div> Available</div>
                                    <div className="flex items-center gap-2 text-sm"><div className="w-4 h-4 rounded-t bg-orange-50 border-2 border-orange-300"></div> Emergency Exit Row (No Infants)</div>
                                    <div className="flex items-center gap-2 text-sm"><div className="w-4 h-4 rounded-t bg-slate-200 border-2 border-slate-300"></div> Unavailable</div>
                                    <div className="flex items-center gap-2 text-sm"><div className="w-4 h-4 rounded-t bg-primary border-2 border-primary"></div> Selected</div>
                                </div>

                                <div className="mt-auto pt-6 flex justify-end">
                                    <Button onClick={() => setIsSeatMapOpen(false)} size="lg" className="w-full rounded-full font-bold">
                                        Done Selecting
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

        </TenantLayout>
    );
}
