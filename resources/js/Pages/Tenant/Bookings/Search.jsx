import React, { useState, useRef, useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/Card";
import { Badge } from "@/Components/ui/Badge";
import { Plane, Calendar, Users, Minus, Plus, ChevronDown, ArrowRightLeft, ArrowRight } from "lucide-react";
import { AsyncAirportSelect } from "@/Components/ui/AsyncAirportSelect";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';

export default function Search({ searchDisplayMode, bookings, filters, airlines }) {
    const [isPaxDropdownOpen, setIsPaxDropdownOpen] = useState(false);
    const paxDropdownRef = useRef(null);

    const { data, setData, post, processing, errors } = useForm({
        origin: '',
        destination: '',
        date: '',
        return_date: '',
        adults: 1,
        children: 0,
        infants: 0,
        is_return: false,
        cabin_class: 'economy',
    });

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (paxDropdownRef.current && !paxDropdownRef.current.contains(event.target)) {
                setIsPaxDropdownOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const submit = (e) => {
        e.preventDefault();
        post(route('bookings.search'));
    };

    const updatePax = (type, delta) => {
        const newVal = Math.max(type === 'adults' ? 1 : 0, Math.min(9, data[type] + delta));
        setData(type, newVal);
    };

    const totalPax = data.adults + data.children + data.infants;

    const filterBookings = (event) => {
        event.preventDefault();

        const formData = new FormData(event.currentTarget);
        const query = Object.fromEntries(Array.from(formData.entries()).filter(([, value]) => value));
        router.get(route('bookings.index'), query, { preserveState: true, preserveScroll: true });
    };

    return (
        <TenantLayout>
            <Head title="Search Flights" />

            <div className="max-w-6xl mx-auto py-8 space-y-8">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-3xl font-bold tracking-tight">Search Flights</h2>
                        <p className="text-muted-foreground">Find the best flight options across all your providers.</p>
                    </div>
                </div>

                <Card className="shadow-lg border-2 border-primary/10 overflow-visible">
                    <CardHeader className="bg-primary/5 pb-6">
                        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <CardTitle className="flex items-center gap-2">
                                <Plane className="h-6 w-6 text-primary" />
                                Book Your Next Flight
                            </CardTitle>
                            
                            <div className="flex items-center bg-muted/50 p-1 rounded-lg w-fit">
                                <button
                                    type="button"
                                    onClick={() => setData('is_return', false)}
                                    className={`flex items-center gap-2 px-4 py-1.5 rounded-md text-sm font-bold transition-all ${!data.is_return ? 'bg-background shadow-sm text-primary' : 'text-muted-foreground hover:text-foreground'}`}
                                >
                                    <ArrowRight className="h-4 w-4" />
                                    One-way
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setData('is_return', true)}
                                    className={`flex items-center gap-2 px-4 py-1.5 rounded-md text-sm font-bold transition-all ${data.is_return ? 'bg-background shadow-sm text-primary' : 'text-muted-foreground hover:text-foreground'}`}
                                >
                                    <ArrowRightLeft className="h-4 w-4" />
                                    Round-trip
                                </button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-8">
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                                <div className="md:col-span-4 space-y-2">
                                    <Label htmlFor="origin" className="font-bold">From</Label>
                                    <AsyncAirportSelect
                                        id="origin"
                                        placeholder="Departure City"
                                        value={data.origin}
                                        onChange={e => setData('origin', e.target.value.toUpperCase())}
                                    />
                                    {errors.origin && <p className="text-xs text-destructive font-medium">{errors.origin}</p>}
                                </div>

                                <div className="hidden md:flex md:col-span-1 justify-center pb-3">
                                    <div className="bg-muted p-2 rounded-full border shadow-sm">
                                        <ArrowRightLeft className="h-4 w-4 text-muted-foreground" />
                                    </div>
                                </div>

                                <div className="md:col-span-4 space-y-2">
                                    <Label htmlFor="destination" className="font-bold">To</Label>
                                    <AsyncAirportSelect
                                        id="destination"
                                        placeholder="Arrival City"
                                        value={data.destination}
                                        onChange={e => setData('destination', e.target.value.toUpperCase())}
                                        isDestination={true}
                                    />
                                    {errors.destination && <p className="text-xs text-destructive font-medium">{errors.destination}</p>}
                                </div>

                                <div className="md:col-span-3 space-y-2">
                                    <Label htmlFor="cabin_class" className="font-bold">Class</Label>
                                    <select
                                        id="cabin_class"
                                        value={data.cabin_class}
                                        onChange={e => setData('cabin_class', e.target.value)}
                                        className="flex h-12 w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-medium ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    >
                                        <option value="economy">Economy</option>
                                        <option value="premium_economy">Premium Economy</option>
                                        <option value="business">Business</option>
                                        <option value="first">First Class</option>
                                    </select>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                                <div className={`space-y-2 ${data.is_return ? 'md:col-span-3' : 'md:col-span-6'}`}>
                                    <Label htmlFor="date" className="font-bold">Departure Date</Label>
                                    <div className="relative">
                                        <Input
                                            id="date"
                                            type="date"
                                            value={data.date}
                                            onChange={e => setData('date', e.target.value)}
                                            className="pl-10 h-12 font-medium"
                                            required
                                        />
                                        <Calendar className="absolute left-3 top-3.5 h-5 w-5 text-muted-foreground" />
                                    </div>
                                    {errors.date && <p className="text-xs text-destructive font-medium">{errors.date}</p>}
                                </div>

                                {data.is_return && (
                                    <div className="md:col-span-3 space-y-2">
                                        <Label htmlFor="return_date" className="font-bold">Return Date</Label>
                                        <div className="relative">
                                            <Input
                                                id="return_date"
                                                type="date"
                                                value={data.return_date}
                                                onChange={e => setData('return_date', e.target.value)}
                                                className="pl-10 h-12 font-medium"
                                                required={data.is_return}
                                                min={data.date}
                                            />
                                            <Calendar className="absolute left-3 top-3.5 h-5 w-5 text-muted-foreground" />
                                        </div>
                                        {errors.return_date && <p className="text-xs text-destructive font-medium">{errors.return_date}</p>}
                                    </div>
                                )}

                                <div className="md:col-span-6 space-y-2 relative" ref={paxDropdownRef}>
                                    <Label className="font-bold">Passengers</Label>
                                    <button
                                        type="button"
                                        onClick={() => setIsPaxDropdownOpen(!isPaxDropdownOpen)}
                                        className="flex h-12 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm font-medium ring-offset-background hover:bg-muted/50 transition-colors"
                                    >
                                        <div className="flex items-center gap-2">
                                            <Users className="h-5 w-5 text-muted-foreground" />
                                            <span>{totalPax} Passenger{totalPax > 1 ? 's' : ''}</span>
                                            <Badge variant="secondary" className="ml-2 font-bold">
                                                {data.adults}A, {data.children}C, {data.infants}I
                                            </Badge>
                                        </div>
                                        <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform ${isPaxDropdownOpen ? 'rotate-180' : ''}`} />
                                    </button>

                                    {isPaxDropdownOpen && (
                                        <div className="absolute top-full left-0 mt-2 w-full md:w-80 bg-background border rounded-xl shadow-xl z-50 p-4 space-y-4">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="font-bold text-sm">Adults</p>
                                                    <p className="text-[10px] text-muted-foreground">Age 12+</p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Button type="button" variant="outline" size="icon" className="h-8 w-8 rounded-full" onClick={() => updatePax('adults', -1)} disabled={data.adults <= 1}>
                                                        <Minus className="h-3 w-3" />
                                                    </Button>
                                                    <span className="w-4 text-center font-bold">{data.adults}</span>
                                                    <Button type="button" variant="outline" size="icon" className="h-8 w-8 rounded-full" onClick={() => updatePax('adults', 1)} disabled={totalPax >= 9}>
                                                        <Plus className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>

                                            <div className="flex items-center justify-between border-t pt-4">
                                                <div>
                                                    <p className="font-bold text-sm">Children</p>
                                                    <p className="text-[10px] text-muted-foreground">Age 2-11</p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Button type="button" variant="outline" size="icon" className="h-8 w-8 rounded-full" onClick={() => updatePax('children', -1)} disabled={data.children <= 0}>
                                                        <Minus className="h-3 w-3" />
                                                    </Button>
                                                    <span className="w-4 text-center font-bold">{data.children}</span>
                                                    <Button type="button" variant="outline" size="icon" className="h-8 w-8 rounded-full" onClick={() => updatePax('children', 1)} disabled={totalPax >= 9}>
                                                        <Plus className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>

                                            <div className="flex items-center justify-between border-t pt-4">
                                                <div>
                                                    <p className="font-bold text-sm">Infants</p>
                                                    <p className="text-[10px] text-muted-foreground">Under 2</p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Button type="button" variant="outline" size="icon" className="h-8 w-8 rounded-full" onClick={() => updatePax('infants', -1)} disabled={data.infants <= 0}>
                                                        <Minus className="h-3 w-3" />
                                                    </Button>
                                                    <span className="w-4 text-center font-bold">{data.infants}</span>
                                                    <Button type="button" variant="outline" size="icon" className="h-8 w-8 rounded-full" onClick={() => updatePax('infants', 1)} disabled={totalPax >= 9 || data.infants >= data.adults}>
                                                        <Plus className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>
                                            
                                            <div className="pt-2 border-t">
                                                <Button type="button" variant="default" className="w-full h-9 text-xs font-bold rounded-lg" onClick={() => setIsPaxDropdownOpen(false)}>
                                                    Done
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="flex justify-end pt-4 border-t">
                                <Button
                                    type="submit"
                                    className="w-full md:w-1/4 h-12 text-lg font-black shadow-lg hover:shadow-xl transition-all rounded-full bg-primary"
                                    disabled={processing}
                                >
                                    {processing ? "Searching..." : "Find Flights"}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Booking Management</CardTitle>
                        <CardDescription>Search, review, and continue work on existing bookings.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <form onSubmit={filterBookings} className="grid gap-4 md:grid-cols-4">
                            <div className="space-y-2">
                                <Label htmlFor="pnr">PNR</Label>
                                <Input id="pnr" name="pnr" defaultValue={filters?.pnr || ''} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="customer">Customer</Label>
                                <Input id="customer" name="customer" defaultValue={filters?.customer || ''} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="status">Status</Label>
                                <select id="status" name="status" defaultValue={filters?.status || ''} className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                    <option value="">All statuses</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="ticketed">Ticketed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="refunded">Refunded</option>
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="airline">Airline</Label>
                                <select id="airline" name="airline" defaultValue={filters?.airline || ''} className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                    <option value="">All airlines</option>
                                    {airlines.map((airline) => (
                                        <option key={airline.airline_code} value={airline.airline_code}>{airline.airline_name}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="md:col-span-4 flex justify-end">
                                <Button type="submit" variant="outline">Apply filters</Button>
                            </div>
                        </form>

                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>PNR</TableHead>
                                        <TableHead>Customer</TableHead>
                                        <TableHead>Airline</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Total</TableHead>
                                        <TableHead className="text-right">Action</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {bookings.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-center text-muted-foreground">
                                                No bookings found for the current filters.
                                            </TableCell>
                                        </TableRow>
                                    ) : bookings.map((booking) => (
                                        <TableRow key={booking.id}>
                                            <TableCell className="font-semibold">{booking.pnr}</TableCell>
                                            <TableCell>
                                                <div>
                                                    <p>{booking.customer?.first_name} {booking.customer?.last_name}</p>
                                                    <p className="text-xs text-muted-foreground">{booking.customer?.email}</p>
                                                </div>
                                            </TableCell>
                                            <TableCell>{booking.provider?.airline_name}</TableCell>
                                            <TableCell>
                                                <Badge variant={booking.status === 'ticketed' ? 'success' : booking.status === 'refunded' ? 'destructive' : 'secondary'}>
                                                    {booking.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{booking.total_price} {booking.currency}</TableCell>
                                            <TableCell className="text-right">
                                                <Button asChild size="sm" variant="ghost">
                                                    <Link href={route('bookings.show', booking.id)}>Open</Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </TenantLayout>
    );
}
