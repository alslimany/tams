import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/Card";
import { Badge } from "@/Components/ui/Badge";
import { Plane, Calendar, Users } from "lucide-react";
import { AsyncAirportSelect } from "@/Components/ui/AsyncAirportSelect";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';

export default function Search({ searchDisplayMode, bookings, filters, airlines }) {
    const { data, setData, post, processing, errors } = useForm({
        origin: '',
        destination: '',
        date: '',
        adults: 1,
        children: 0,
        infants: 0,
        is_return: false,
        cabin_class: 'economy',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('bookings.search'));
    };

    const updatePax = (type, val) => {
        const num = parseInt(val);
        if (!isNaN(num)) {
            setData(type, num);
        }
    };

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
                <div className="mb-8">
                    <h2 className="text-3xl font-bold tracking-tight">Search Flights</h2>
                    <p className="text-muted-foreground">Find the best flight options across all your providers.</p>
                </div>

                <Card className="shadow-lg border-2 border-primary/10">
                    <CardHeader className="bg-primary/5 pb-8">
                        <CardTitle className="flex items-center gap-2">
                            <Plane className="h-6 w-6 text-primary" />
                            Book Your Next Flight
                        </CardTitle>
                        <CardDescription>Enter your travel details to see availability and pricing.</CardDescription>
                    </CardHeader>
                    <CardContent className="pt-8">
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div className="space-y-2">
                                    <Label htmlFor="origin" className="flex items-center gap-2">
                                        Departure City
                                    </Label>
                                    <AsyncAirportSelect
                                        id="origin"
                                        placeholder="e.g. MJI or Tripoli"
                                        value={data.origin}
                                        onChange={e => setData('origin', e.target.value.toUpperCase())}
                                    />
                                    {errors.origin && <p className="text-sm text-destructive">{errors.origin}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="destination" className="flex items-center gap-2">
                                        Arrival City
                                    </Label>
                                    <AsyncAirportSelect
                                        id="destination"
                                        placeholder="e.g. IST or Istanbul"
                                        value={data.destination}
                                        onChange={e => setData('destination', e.target.value.toUpperCase())}
                                        isDestination={true}
                                    />
                                    {errors.destination && <p className="text-sm text-destructive">{errors.destination}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="date">Departure Date</Label>
                                    <div className="relative">
                                        <Input
                                            id="date"
                                            type="date"
                                            value={data.date}
                                            onChange={e => setData('date', e.target.value)}
                                            className="pl-10 h-12"
                                            required
                                        />
                                        <Calendar className="absolute left-3 top-3.5 h-5 w-5 text-muted-foreground" />
                                    </div>
                                    {errors.date && <p className="text-sm text-destructive">{errors.date}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label>Adults (12+)</Label>
                                    <div className="relative">
                                        <Input
                                            type="number"
                                            min="1"
                                            max="9"
                                            value={data.adults}
                                            onChange={e => updatePax('adults', e.target.value)}
                                            className="pl-10 h-12"
                                            required
                                        />
                                        <Users className="absolute left-3 top-3.5 h-5 w-5 text-muted-foreground" />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label>Children (2-11)</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        max="9"
                                        value={data.children}
                                        onChange={e => updatePax('children', e.target.value)}
                                        className="h-12"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label>Infants (0-1)</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        max="9"
                                        value={data.infants}
                                        onChange={e => updatePax('infants', e.target.value)}
                                        className="h-12"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="cabin_class">Cabin Class</Label>
                                    <div className="relative">
                                        <select
                                            id="cabin_class"
                                            value={data.cabin_class}
                                            onChange={e => setData('cabin_class', e.target.value)}
                                            className="flex h-12 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <option value="economy">Economy</option>
                                            <option value="business">Business</option>
                                            <option value="first">First Class</option>
                                        </select>
                                    </div>
                                    {searchDisplayMode === 'per_offer' && (
                                        <p className="text-[10px] text-primary font-medium italic">Required for Per-Offer results</p>
                                    )}
                                </div>
                            </div>

                            <div className="flex justify-end pt-4">
                                <Button
                                    type="submit"
                                    className="w-full md:w-1/3 h-12 text-lg font-bold shadow-md hover:shadow-lg transition-all"
                                    disabled={processing}
                                >
                                    {processing ? "Searching..." : "Search Flights"}
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
