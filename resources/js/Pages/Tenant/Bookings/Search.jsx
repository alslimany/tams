import React, { useState, useRef, useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { CalendarIcon, Plane, Users, Minus, Plus, ChevronDown, ArrowRightLeft, ArrowRight } from 'lucide-react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Calendar } from '@/Components/ui/calendar';
import { AsyncAirportSelect } from '@/Components/ui/AsyncAirportSelect';

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
        post(route('flights.search'));
    };

    const updatePax = (type, delta) => {
        const newVal = Math.max(type === 'adults' ? 1 : 0, Math.min(9, data[type] + delta));
        setData(type, newVal);
    };

    const totalPax = data.adults + data.children + data.infants;

    const departureDate = data.date ? new Date(data.date) : undefined;
    const returnDate = data.return_date ? new Date(data.return_date) : undefined;
    const tripRange = {
        from: departureDate,
        to: returnDate,
    };

    const applySingleDate = (selectedDate) => {
        if (!selectedDate) {
            return;
        }

        setData('date', format(selectedDate, 'yyyy-MM-dd'));
    };

    const applyRangeDate = (selectedRange) => {
        setData('date', selectedRange?.from ? format(selectedRange.from, 'yyyy-MM-dd') : '');
        setData('return_date', selectedRange?.to ? format(selectedRange.to, 'yyyy-MM-dd') : '');
    };

    const filterBookings = (event) => {
        event.preventDefault();

        const formData = new FormData(event.currentTarget);
        const query = Object.fromEntries(Array.from(formData.entries()).filter(([, value]) => value));
        router.get(route('flights.index'), query, { preserveState: true, preserveScroll: true });
    };

    return (
        <TenantLayout>
            <Head title="Search Flights" />

            <div className="mx-auto max-w-6xl space-y-6 py-6">
                <div>
                    <div>
                        <h2 className="text-2xl font-semibold tracking-tight">Search Flights</h2>
                        <p className="text-sm text-muted-foreground">Find flight options across all your providers.</p>
                    </div>
                </div>

                <Card className="overflow-visible">
                    <CardHeader>
                        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <Plane className="h-5 w-5 text-primary" />
                                    Book Your Next Flight
                                </CardTitle>
                                <CardDescription>
                                    Complete the details below to start a new booking.
                                </CardDescription>
                            </div>

                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant={!data.is_return ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => setData('is_return', false)}
                                    className="gap-2"
                                >
                                    <ArrowRight className="h-4 w-4" />
                                    One-way
                                </Button>
                                <Button
                                    type="button"
                                    variant={data.is_return ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => setData('is_return', true)}
                                    className="gap-2"
                                >
                                    <ArrowRightLeft className="h-4 w-4" />
                                    Round-trip
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-4 md:grid-cols-12">
                                <div className="space-y-2 md:col-span-4">
                                    <Label htmlFor="origin">From (IATA)</Label>
                                    <AsyncAirportSelect
                                        id="origin"
                                        placeholder="MJI"
                                        value={data.origin}
                                        onChange={(event) => setData('origin', event.target.value.toUpperCase())}
                                    />
                                    {errors.origin && <p className="text-xs text-destructive">{errors.origin}</p>}
                                </div>

                                <div className="hidden items-end justify-center pb-2 md:col-span-1 md:flex">
                                    <div className="rounded-md border p-2">
                                        <ArrowRightLeft className="h-4 w-4 text-muted-foreground" />
                                    </div>
                                </div>

                                <div className="space-y-2 md:col-span-4">
                                    <Label htmlFor="destination">To (IATA)</Label>
                                    <AsyncAirportSelect
                                        id="destination"
                                        placeholder="IST"
                                        value={data.destination}
                                        onChange={(event) => setData('destination', event.target.value.toUpperCase())}
                                        isDestination
                                    />
                                    {errors.destination && <p className="text-xs text-destructive">{errors.destination}</p>}
                                </div>

                                <div className="space-y-2 md:col-span-3">
                                    <Label htmlFor="cabin_class">Class</Label>
                                    <Select
                                        id="cabin_class"
                                        value={data.cabin_class}
                                        onChange={(event) => setData('cabin_class', event.target.value)}
                                    >
                                        <option value="economy">Economy</option>
                                        <option value="premium_economy">Premium Economy</option>
                                        <option value="business">Business</option>
                                        <option value="first">First Class</option>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-12">
                                {!data.is_return ? (
                                    <div className="space-y-2 md:col-span-6">
                                        <Label htmlFor="date">Departure Date</Label>
                                        <Popover>
                                            <PopoverTrigger asChild>
                                                <Button id="date" variant="outline" className="w-full justify-start text-left font-normal">
                                                    <CalendarIcon className="mr-2 h-4 w-4" />
                                                    {departureDate ? format(departureDate, 'PPP') : 'Pick a date'}
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent className="w-auto p-0" align="start">
                                                <Calendar
                                                    mode="single"
                                                    selected={departureDate}
                                                    onSelect={applySingleDate}
                                                    initialFocus
                                                />
                                            </PopoverContent>
                                        </Popover>
                                        {errors.date && <p className="text-xs text-destructive">{errors.date}</p>}
                                    </div>
                                ) : (
                                    <div className="space-y-2 md:col-span-6">
                                        <Label htmlFor="date-range">Trip Dates</Label>
                                        <Popover>
                                            <PopoverTrigger asChild>
                                                <Button id="date-range" variant="outline" className="w-full justify-start text-left font-normal">
                                                    <CalendarIcon className="mr-2 h-4 w-4" />
                                                    {tripRange.from
                                                        ? tripRange.to
                                                            ? `${format(tripRange.from, 'LLL dd, y')} - ${format(tripRange.to, 'LLL dd, y')}`
                                                            : format(tripRange.from, 'LLL dd, y')
                                                        : 'Pick a date range'}
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent className="w-auto p-0" align="start">
                                                <Calendar
                                                    mode="range"
                                                    selected={tripRange}
                                                    onSelect={applyRangeDate}
                                                    numberOfMonths={2}
                                                    initialFocus
                                                />
                                            </PopoverContent>
                                        </Popover>
                                        {errors.date && <p className="text-xs text-destructive">{errors.date}</p>}
                                        {errors.return_date && <p className="text-xs text-destructive">{errors.return_date}</p>}
                                    </div>
                                )}

                                <div className="relative space-y-2 md:col-span-6" ref={paxDropdownRef}>
                                    <Label>Passengers</Label>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setIsPaxDropdownOpen(!isPaxDropdownOpen)}
                                        className="w-full justify-between"
                                    >
                                        <div className="flex items-center gap-2">
                                            <Users className="h-4 w-4 text-muted-foreground" />
                                            <span>{totalPax} Passenger{totalPax > 1 ? 's' : ''}</span>
                                            <Badge variant="secondary" className="ml-1 text-xs font-medium">
                                                {data.adults}A, {data.children}C, {data.infants}I
                                            </Badge>
                                        </div>
                                        <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform ${isPaxDropdownOpen ? 'rotate-180' : ''}`} />
                                    </Button>

                                    {isPaxDropdownOpen && (
                                        <Card className="absolute left-0 top-full z-50 mt-2 w-full md:w-80">
                                            <CardContent className="space-y-4 p-4">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="text-sm font-medium">Adults</p>
                                                    <p className="text-xs text-muted-foreground">Age 12+</p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Button type="button" variant="outline" size="icon" onClick={() => updatePax('adults', -1)} disabled={data.adults <= 1}>
                                                        <Minus className="h-3 w-3" />
                                                    </Button>
                                                    <span className="w-4 text-center text-sm font-medium">{data.adults}</span>
                                                    <Button type="button" variant="outline" size="icon" onClick={() => updatePax('adults', 1)} disabled={totalPax >= 9}>
                                                        <Plus className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>

                                            <div className="flex items-center justify-between border-t pt-3">
                                                <div>
                                                    <p className="text-sm font-medium">Children</p>
                                                    <p className="text-xs text-muted-foreground">Age 2-11</p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Button type="button" variant="outline" size="icon" onClick={() => updatePax('children', -1)} disabled={data.children <= 0}>
                                                        <Minus className="h-3 w-3" />
                                                    </Button>
                                                    <span className="w-4 text-center text-sm font-medium">{data.children}</span>
                                                    <Button type="button" variant="outline" size="icon" onClick={() => updatePax('children', 1)} disabled={totalPax >= 9}>
                                                        <Plus className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>

                                            <div className="flex items-center justify-between border-t pt-3">
                                                <div>
                                                    <p className="text-sm font-medium">Infants</p>
                                                    <p className="text-xs text-muted-foreground">Under 2</p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Button type="button" variant="outline" size="icon" onClick={() => updatePax('infants', -1)} disabled={data.infants <= 0}>
                                                        <Minus className="h-3 w-3" />
                                                    </Button>
                                                    <span className="w-4 text-center text-sm font-medium">{data.infants}</span>
                                                    <Button type="button" variant="outline" size="icon" onClick={() => updatePax('infants', 1)} disabled={totalPax >= 9 || data.infants >= data.adults}>
                                                        <Plus className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>
                                            
                                            <div className="pt-2 border-t">
                                                <Button type="button" className="w-full" onClick={() => setIsPaxDropdownOpen(false)}>
                                                    Done
                                                </Button>
                                            </div>
                                            </CardContent>
                                        </Card>
                                    )}
                                </div>
                            </div>

                            <div className="flex justify-end pt-3 border-t">
                                <Button
                                    type="submit"
                                    className="w-full md:w-auto"
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
                        <CardTitle>Flight Management</CardTitle>
                        <CardDescription>Search, review, and continue work on existing flights.</CardDescription>
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
                                <Select id="status" name="status" defaultValue={filters?.status || ''}>
                                    <option value="">All statuses</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="ticketed">Ticketed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="refunded">Refunded</option>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="airline">Airline</Label>
                                <Select id="airline" name="airline" defaultValue={filters?.airline || ''}>
                                    <option value="">All airlines</option>
                                    {airlines.map((airline) => (
                                        <option key={airline.airline_code} value={airline.airline_code}>{airline.airline_name}</option>
                                    ))}
                                </Select>
                            </div>
                            <div className="md:col-span-4 flex justify-end">
                                <Button type="submit" variant="outline">Apply Filters</Button>
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
                                                No flights found for the current filters.
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
                                                    <Link href={route('flights.show', booking.id)}>Open</Link>
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
