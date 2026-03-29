import React, { useEffect, useState, useMemo } from 'react';
import axios from 'axios';
import { Head, Link } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from "@/Components/ui/Button";
import { Card, CardContent } from "@/Components/ui/Card";
import { Badge } from "@/Components/ui/Badge";
import { Plane, Loader2, ChevronRight, Info, Clock, Briefcase, User, ReceiptText, ArrowRightLeft } from "lucide-react";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/Dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/Table";

export default function SearchResults({ providers, query, uuid, searchDisplayMode }) {
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(true);

    const formatDuration = (minutes) => {
        if (!minutes) return "N/A";
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return `${h > 0 ? `${h}h ` : ""}${m}m`;
    };

    useEffect(() => {
        let completed = 0;

        if (!providers || providers.length === 0) {
            setLoading(false);
            return;
        }

        providers.forEach(provider => {
            axios.post(route('bookings.fetch-flights'), {
                uuid: uuid,
                provider_id: provider.id
            }).then(response => {
                const newFlights = response.data?.flights || [];

                if (newFlights.length > 0) {
                    setResults(prev => {
                        return [...prev, ...newFlights].sort((a, b) => {
                            const priceA = a?.pricing?.total || 0;
                            const priceB = b?.pricing?.total || 0;
                            return priceA - priceB;
                        });
                    });
                }
            }).catch(err => {
                console.error(`Failed to load flights for ${provider.airline_name}`);
            }).finally(() => {
                completed++;
                if (completed === providers.length) {
                    setLoading(false);
                }
            });
        });
    }, []);

    // Grouping logic for "Per Flight" mode
    const groupedFlights = useMemo(() => {
        if (searchDisplayMode !== 'per_flight') return [];

        const groups = {};
        results.forEach(flight => {
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
    }, [results, searchDisplayMode]);

    const renderOfferCard = (flight) => {
        const provider = providers.find(p => flight.airline_name.includes(p.airline_name));

        return (
            <Card key={flight.id} className="overflow-hidden hover:border-primary/50 transition-colors shadow-sm hover:shadow-md">
                <CardContent className="p-0">
                    <div className="flex flex-col md:flex-row">
                        <div className="p-6 flex-1 flex flex-col md:flex-row md:items-center gap-6">
                            <div className="flex gap-4 items-center min-w-[150px]">
                                <div className="bg-primary/5 p-1 rounded-sm shrink-0 flex items-center justify-center min-w-[32px] min-h-[32px]">
                                    {flight.airline_code ? (
                                        <img src={route('api.airlines.logo', { code: flight.airline_code, variant: 'icon', radius: 4 })} alt={flight.airline_name} className="h-6 w-6 object-contain mix-blend-multiply dark:mix-blend-normal" onError={(e) => { e.target.style.display = 'none'; e.target.nextSibling.style.display = 'block'; }} />
                                    ) : null}
                                    <Plane className="h-5 w-5 text-primary" style={{ display: flight.airline_code ? 'none' : 'block' }} />
                                </div>
                                <div>
                                    <p className="font-bold text-lg">{flight.airline_name}</p>
                                    <p className="text-xs text-muted-foreground font-medium uppercase tracking-wider">{flight.flight_number}</p>
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
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="ghost" size="sm" className="h-8 gap-2 px-2 hover:bg-primary/5">
                                            <Info className="h-4 w-4" />
                                            <span className="text-xs font-bold">Flight Info</span>
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="max-w-2xl">
                                        <DialogHeader>
                                            <DialogTitle className="text-2xl font-black">Flight Information</DialogTitle>
                                            <DialogDescription className="font-medium">
                                                Detailed itinerary for {flight.airline_name} {flight.flight_number}
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="grid gap-6 py-4">
                                            {flight.segments.map((segment, idx) => (
                                                <div key={idx} className="bg-muted/30 rounded-2xl p-6 border border-dashed border-primary/20">
                                                    <div className="flex justify-between items-start mb-6">
                                                        <div>
                                                            <p className="text-xs font-bold uppercase tracking-widest text-primary mb-1">Carrier</p>
                                                            <p className="text-lg font-black">{flight.airline_name.split(' (')[0]}</p>
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="text-xs font-bold uppercase tracking-widest text-primary mb-1">Aircraft</p>
                                                            <p className="text-lg font-black">{segment.aircraft || "Standard"}</p>
                                                        </div>
                                                    </div>
                                                    <div className="grid grid-cols-3 items-center gap-4">
                                                        <div className="text-left">
                                                            <p className="text-sm font-bold text-muted-foreground mb-1">Departure</p>
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
                                                            <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-tighter">Non-stop</p>
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="text-sm font-bold text-muted-foreground mb-1">Arrival</p>
                                                            <p className="text-2xl font-black">{segment.arrival_airport}</p>
                                                            <p className="text-xs font-medium text-muted-foreground">{segment.arrival_time}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </div>

                        <div className="bg-muted/30 md:w-64 p-6 flex flex-col items-center justify-center border-t md:border-t-0 md:border-l space-y-3">
                            <div className="text-center">
                                <p className="text-xs text-muted-foreground font-semibold uppercase">From</p>
                                <p className="text-2xl font-black text-primary">
                                    {flight?.pricing?.total || 0} <span className="text-sm">{flight?.pricing?.currency || 'LYD'}</span>
                                </p>
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="link" size="sm" className="h-6 p-0 text-[10px] font-bold text-muted-foreground hover:text-primary transition-colors">
                                            View Price Details
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="max-w-2xl">
                                        <DialogHeader>
                                            <DialogTitle className="text-2xl font-black">Offer Details</DialogTitle>
                                            <DialogDescription className="font-medium">
                                                Pricing breakdown and fare conditions for <strong>{flight.pricing.brand_name}</strong>
                                            </DialogDescription>
                                        </DialogHeader>

                                        <div className="space-y-8 py-4">
                                            {flight.pricing.brand_details && (
                                                <div className="bg-primary/5 rounded-2xl p-6 border-l-4 border-primary">
                                                    <div className="flex items-center gap-3 mb-3">
                                                        <Briefcase className="h-5 w-5 text-primary" />
                                                        <p className="text-sm font-black uppercase tracking-widest text-primary">Fare Features</p>
                                                    </div>
                                                    <p className="text-sm font-medium leading-relaxed whitespace-pre-line text-muted-foreground">
                                                        {flight.pricing.brand_details}
                                                    </p>
                                                </div>
                                            )}

                                            <div>
                                                <div className="flex items-center gap-3 mb-6">
                                                    <ReceiptText className="h-5 w-5 text-primary" />
                                                    <p className="text-sm font-black uppercase tracking-widest">Passenger Breakdown</p>
                                                </div>
                                                <div className="border rounded-2xl overflow-hidden bg-muted/10 shadow-sm">
                                                    <Table>
                                                        <TableHeader>
                                                            <TableRow className="bg-muted/30">
                                                                <TableHead className="font-bold text-foreground">Type</TableHead>
                                                                <TableHead className="text-right font-bold text-foreground">Fare</TableHead>
                                                                <TableHead className="text-right font-bold text-foreground">Tax</TableHead>
                                                                <TableHead className="text-right font-bold text-foreground">Total</TableHead>
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
                                                                    <TableCell className="text-right font-medium">{pax.fare} {flight.pricing.currency}</TableCell>
                                                                    <TableCell className="text-right font-medium">{pax.tax} {flight.pricing.currency}</TableCell>
                                                                    <TableCell className="text-right font-black text-primary">{pax.amount} {flight.pricing.currency}</TableCell>
                                                                </TableRow>
                                                            ))}
                                                            <TableRow className="bg-primary/10 hover:bg-primary/20 transition-colors">
                                                                <TableCell colSpan={3} className="font-black text-lg py-4">Grand Total</TableCell>
                                                                <TableCell className="text-right font-black text-2xl text-primary">{flight.pricing.total} {flight.pricing.currency}</TableCell>
                                                            </TableRow>
                                                        </TableBody>
                                                    </Table>
                                                </div>
                                                <p className="mt-4 text-[10px] text-center font-bold text-muted-foreground bg-muted/30 py-2 rounded-full uppercase tracking-tighter">
                                                    Prices include all applicable taxes and mandatory booking fees.
                                                </p>
                                            </div>
                                        </div>
                                    </DialogContent>
                                </Dialog>
                            </div>
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button className="w-full font-bold shadow-sm" size="lg">
                                        Select Flight
                                        <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="max-w-2xl sm:rounded-3xl p-0 overflow-hidden">
                                    <div className="bg-primary/5 p-6 border-b">
                                        <h2 className="text-2xl font-black">Offer Summary</h2>
                                        <p className="text-muted-foreground font-medium text-sm">Review your selected flight before proceeding</p>
                                    </div>
                                    <div className="p-6 space-y-6 max-h-[60vh] overflow-y-auto">
                                        <div className="flex justify-between items-center bg-card border rounded-2xl p-4 shadow-sm">
                                            <div>
                                                <p className="text-sm font-bold text-muted-foreground">Itinerary</p>
                                                <p className="text-xl font-black">{flight.departure_airport} <ArrowRightLeft className="inline flex-shrink-0 h-4 w-4 mx-1" /> {flight.arrival_airport}</p>
                                                <p className="text-sm font-medium">{flight.airline_name} • {flight.flight_number}</p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-bold text-muted-foreground">Grand Total</p>
                                                <p className="text-2xl font-black text-primary">{flight.pricing.total} <span className="text-sm">{flight.pricing.currency}</span></p>
                                            </div>
                                        </div>

                                        <div>
                                            <p className="font-bold mb-3 uppercase tracking-widest text-xs text-muted-foreground">Flight Segments</p>
                                            <div className="space-y-3">
                                                {flight.segments.map((seg, i) => (
                                                    <div key={i} className="flex gap-4 p-4 border rounded-xl bg-muted/10 items-center">
                                                        <Plane className="h-5 w-5 text-primary" />
                                                        <div className="flex-1">
                                                            <div className="flex justify-between font-black text-sm">
                                                                <span>{seg.departure_airport} ({seg.departure_time.split(' ')[1].substring(0, 5)})</span>
                                                                <span>{seg.arrival_airport} ({seg.arrival_time.split(' ')[1].substring(0, 5)})</span>
                                                            </div>
                                                            <p className="text-xs text-muted-foreground font-medium mt-1">Duration: {formatDuration(seg.duration)} • {seg.aircraft || 'Standard'}</p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="p-6 border-t bg-muted/30 flex justify-end">
                                        <Link
                                            href={route('bookings.select', { flight: flight, uuid: uuid, provider_id: provider?.id })}
                                            method="post"
                                            as="button"
                                        >
                                            <Button size="lg" className="font-bold shadow-md rounded-full px-8">
                                                Next: Passenger Details
                                                <ChevronRight className="ml-2 h-5 w-5" />
                                            </Button>
                                        </Link>
                                    </div>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </div>
                </CardContent>
            </Card>
        );
    };

    const renderFlightWithOffers = (flightGroup) => {
        const provider = providers.find(p => flightGroup.airline_name.includes(p.airline_name));

        return (
            <Card key={`${flightGroup.airline_code}-${flightGroup.flight_number}`} className="overflow-hidden shadow-md border-2 border-muted/50">
                <div className="bg-muted/10 p-6 flex flex-col md:flex-row md:items-center justify-between gap-6 border-b">
                    <div className="flex gap-4 items-center">
                        <div className="bg-primary/5 p-1 rounded-sm shrink-0 flex items-center justify-center min-w-[48px] min-h-[48px]">
                            {flightGroup.airline_code ? (
                                <img src={route('api.airlines.logo', { code: flightGroup.airline_code, variant: 'icon-transparent', radius: 8 })} alt={flightGroup.airline_name} className="h-10 w-10 object-contain mix-blend-multiply dark:mix-blend-normal" onError={(e) => { e.target.style.display = 'none'; e.target.nextSibling.style.display = 'block'; }} />
                            ) : null}
                            <Plane className="h-6 w-6 text-primary" style={{ display: flightGroup.airline_code ? 'none' : 'block' }} />
                        </div>
                        <div>
                            <p className="text-xl font-bold">{flightGroup.airline_name.split(' (')[0]}</p>
                            <p className="text-xs text-muted-foreground font-bold uppercase tracking-widest">{flightGroup.flight_number}</p>
                        </div>
                    </div>

                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="outline" size="sm" className="h-9 gap-2 font-bold px-4 rounded-full border-primary/20 hover:bg-primary/5 transition-all">
                                <Info className="h-4 w-4 text-primary" />
                                Flight Details
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-2xl">
                            <DialogHeader>
                                <DialogTitle className="text-2xl font-black">Flight Information</DialogTitle>
                                <DialogDescription className="font-medium">
                                    Detailed itinerary for {flightGroup.airline_name.split(' (')[0]} {flightGroup.flight_number}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-6 py-4">
                                {flightGroup.segments.map((segment, idx) => (
                                    <div key={idx} className="bg-muted/30 rounded-2xl p-8 border border-dashed border-primary/20">
                                        <div className="grid grid-cols-3 items-center gap-8">
                                            <div className="text-left">
                                                <p className="text-xs font-bold uppercase tracking-widest text-primary mb-2">Departure</p>
                                                <p className="text-3xl font-black">{segment.departure_airport}</p>
                                                <p className="text-sm font-bold text-muted-foreground mt-1">{segment.departure_time}</p>
                                            </div>
                                            <div className="flex flex-col items-center gap-3">
                                                <div className="flex items-center gap-2 text-primary font-black text-[10px] uppercase bg-primary/10 px-4 py-1.5 rounded-full shadow-sm ring-1 ring-primary/20">
                                                    <Clock className="h-3 w-3" />
                                                    {formatDuration(segment.duration)}
                                                </div>
                                                <div className="w-full h-px bg-primary/20 relative mx-4">
                                                    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-background p-1.5 rounded-full border-2 border-primary/20 shadow-sm">
                                                        <Plane className="h-4 w-4 text-primary" />
                                                    </div>
                                                </div>
                                                <p className="text-[10px] font-black text-muted-foreground uppercase tracking-[0.2em] animate-pulse">Non-stop</p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-xs font-bold uppercase tracking-widest text-primary mb-2">Arrival</p>
                                                <p className="text-3xl font-black">{segment.arrival_airport}</p>
                                                <p className="text-sm font-bold text-muted-foreground mt-1">{segment.arrival_time}</p>
                                            </div>
                                        </div>
                                        <div className="mt-8 pt-6 border-t border-primary/10 flex justify-between items-center text-xs font-black uppercase tracking-widest text-muted-foreground">
                                            <span>Aircraft: <span className="text-foreground">{segment.aircraft || "Standard"}</span></span>
                                            <span>Class: <span className="text-foreground">{segment.class}</span></span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </DialogContent>
                    </Dialog>

                    <div className="flex-1 max-w-md grid grid-cols-2 gap-12">
                        <div className="text-left">
                            <p className="text-3xl font-black">{flightGroup.departure_time.split(' ')[1].substring(0, 5)}</p>
                            <p className="text-sm font-bold text-muted-foreground">{flightGroup.departure_airport}</p>
                        </div>
                        <div className="text-right">
                            <p className="text-3xl font-black">{flightGroup.arrival_time.split(' ')[1].substring(0, 5)}</p>
                            <p className="text-sm font-bold text-muted-foreground">{flightGroup.arrival_airport}</p>
                        </div>
                    </div>
                </div>

                <CardContent className="p-0">
                    <div className="divide-y">
                        {flightGroup.offers.map((offer) => (
                            <div key={offer.id} className="p-4 flex items-center justify-between hover:bg-muted/20 transition-colors">
                                <div className="flex items-center gap-3">
                                    <Badge variant="outline" className="px-3 py-1 font-bold bg-background shadow-sm">
                                        {offer.pricing.brand_name || 'Standard'}
                                    </Badge>
                                    <span className="text-xs text-muted-foreground font-medium">Class {offer.pricing.class_code} • {offer.available_seats} seats left</span>
                                </div>
                                <div className="flex items-center gap-6">
                                    <div className="text-right">
                                        <p className="text-lg font-bold">
                                            {offer.pricing.total} <span className="text-xs text-muted-foreground">{offer.pricing.currency}</span>
                                        </p>
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <button className="text-[10px] font-bold text-primary hover:underline cursor-pointer">
                                                    Price Details
                                                </button>
                                            </DialogTrigger>
                                            <DialogContent className="max-w-2xl">
                                                <DialogHeader>
                                                    <DialogTitle className="text-2xl font-black">Offer Breakdown</DialogTitle>
                                                    <DialogDescription className="font-medium">
                                                        Branded fare: <strong>{offer.pricing.brand_name} (Class {offer.pricing.class_code})</strong>
                                                    </DialogDescription>
                                                </DialogHeader>

                                                <div className="space-y-8 py-4">
                                                    {offer.pricing.brand_details && (
                                                        <div className="bg-muted/30 rounded-2xl p-6 border-2 border-dashed border-primary/10">
                                                            <div className="flex items-center gap-3 mb-4">
                                                                <Briefcase className="h-5 w-5 text-primary" />
                                                                <p className="text-xs font-black uppercase tracking-widest">Included Features</p>
                                                            </div>
                                                            <p className="text-sm font-medium leading-relaxed text-muted-foreground bg-background/50 p-4 rounded-xl">
                                                                {offer.pricing.brand_details}
                                                            </p>
                                                        </div>
                                                    )}

                                                    <div>
                                                        <div className="flex items-center gap-3 mb-6">
                                                            <ReceiptText className="h-5 w-5 text-primary" />
                                                            <p className="text-xs font-black uppercase tracking-widest">Pricing Summary</p>
                                                        </div>
                                                        <div className="border rounded-2xl overflow-hidden shadow-md">
                                                            <Table>
                                                                <TableBody>
                                                                    {(offer.pricing.breakdown || []).map((pax, i) => (
                                                                        <TableRow key={i} className="hover:bg-muted/20">
                                                                            <TableCell className="font-black py-4">
                                                                                <div className="flex items-center gap-2">
                                                                                    <User className="h-4 w-4 text-primary" />
                                                                                    {pax.label}
                                                                                </div>
                                                                            </TableCell>
                                                                            <TableCell className="text-right text-xs font-bold text-muted-foreground">
                                                                                <span className="bg-muted px-2 py-0.5 rounded">Fare: {pax.fare}</span>
                                                                            </TableCell>
                                                                            <TableCell className="text-right text-xs font-bold text-muted-foreground">
                                                                                <span className="bg-muted px-2 py-0.5 rounded">Tax: {pax.tax}</span>
                                                                            </TableCell>
                                                                            <TableCell className="text-right font-black text-primary">{pax.amount} {offer.pricing.currency}</TableCell>
                                                                        </TableRow>
                                                                    ))}
                                                                    <TableRow className="bg-primary/10">
                                                                        <TableCell colSpan={3} className="font-black text-xl py-6">Grand Total</TableCell>
                                                                        <TableCell className="text-right font-black text-3xl text-primary">{offer.pricing.total} {offer.pricing.currency}</TableCell>
                                                                    </TableRow>
                                                                </TableBody>
                                                            </Table>
                                                        </div>
                                                    </div>
                                                </div>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button size="sm" variant="secondary" className="font-bold border shadow-sm px-6">
                                                Select
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent className="max-w-2xl sm:rounded-3xl p-0 overflow-hidden">
                                            <div className="bg-primary/5 p-6 border-b">
                                                <h2 className="text-2xl font-black">Offer Summary</h2>
                                                <p className="text-muted-foreground font-medium text-sm">Review your selected class before proceeding</p>
                                            </div>
                                            <div className="p-6 space-y-6 max-h-[60vh] overflow-y-auto">
                                                <div className="flex justify-between items-center bg-card border rounded-2xl p-4 shadow-sm">
                                                    <div>
                                                        <p className="text-sm font-bold text-muted-foreground">Itinerary & Fare</p>
                                                        <p className="text-xl font-black">{flightGroup.departure_airport} <ArrowRightLeft className="inline flex-shrink-0 h-4 w-4 mx-1" /> {flightGroup.arrival_airport}</p>
                                                        <p className="text-sm font-medium">{offer.pricing.brand_name || 'Standard'} • Class {offer.pricing.class_code}</p>
                                                    </div>
                                                    <div className="text-right">
                                                        <p className="text-sm font-bold text-muted-foreground">Grand Total</p>
                                                        <p className="text-2xl font-black text-primary">{offer.pricing.total} <span className="text-sm">{offer.pricing.currency}</span></p>
                                                    </div>
                                                </div>

                                                <div>
                                                    <p className="font-bold mb-3 uppercase tracking-widest text-xs text-muted-foreground">Flight Segments</p>
                                                    <div className="space-y-3">
                                                        {flightGroup.segments.map((seg, i) => (
                                                            <div key={i} className="flex gap-4 p-4 border rounded-xl bg-muted/10 items-center">
                                                                <Plane className="h-5 w-5 text-primary" />
                                                                <div className="flex-1">
                                                                    <div className="flex justify-between font-black text-sm">
                                                                        <span>{seg.departure_airport} ({seg.departure_time.split(' ')[1].substring(0, 5)})</span>
                                                                        <span>{seg.arrival_airport} ({seg.arrival_time.split(' ')[1].substring(0, 5)})</span>
                                                                    </div>
                                                                    <p className="text-xs text-muted-foreground font-medium mt-1">Duration: {formatDuration(seg.duration)} • {seg.aircraft || 'Standard'} • Class {offer.pricing.class_code}</p>
                                                                </div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="p-6 border-t bg-muted/30 flex justify-end">
                                                <Link
                                                    href={route('bookings.select', { flight: offer, uuid: uuid, provider_id: provider?.id })}
                                                    method="post"
                                                    as="button"
                                                >
                                                    <Button size="lg" className="font-bold shadow-md rounded-full px-8">
                                                        Next: Passenger Details
                                                        <ChevronRight className="ml-2 h-5 w-5" />
                                                    </Button>
                                                </Link>
                                            </div>
                                        </DialogContent>
                                    </Dialog>
                                </div>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>
        );
    };

    return (
        <TenantLayout>
            <Head title={`Flights to ${query.destination}`} />

            <div className="max-w-5xl mx-auto py-8 px-4">
                <div className="flex flex-col md:flex-row md:items-end justify-between mb-10 gap-6">
                    <div>
                        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold uppercase tracking-wider mb-2">
                            <div className="size-1.5 rounded-full bg-primary animate-pulse" />
                            Search Results
                        </div>
                        <h2 className="text-4xl font-black tracking-tight flex items-center gap-3">
                            {query.origin} <ChevronRight className="h-8 w-8 text-muted-foreground/30" /> {query.destination}
                        </h2>
                        <p className="text-muted-foreground font-medium mt-1">
                            {new Date(query.date).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })} • {
                                [
                                    query.adults > 0 ? `${query.adults} Adult${query.adults > 1 ? 's' : ''}` : null,
                                    query.children > 0 ? `${query.children} Child${query.children > 1 ? 'ren' : ''}` : null,
                                    query.infants > 0 ? `${query.infants} Infant${query.infants > 1 ? 's' : ''}` : null
                                ].filter(Boolean).join(', ')
                            }
                        </p>
                    </div>
                    {results.length > 0 && (
                        <div className="flex items-center gap-2 px-4 py-2 bg-muted/30 rounded-lg border border-dashed text-sm font-medium text-muted-foreground">
                            <Info className="h-4 w-4" />
                            Displaying {searchDisplayMode === 'per_flight' ? 'Grouped Flights' : 'Individual Offers'}
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    {loading && results.length === 0 && (
                        <div className="flex flex-col items-center justify-center py-24 gap-6 bg-card border rounded-3xl shadow-sm">
                            <div className="relative">
                                <Loader2 className="h-12 w-12 animate-spin text-primary" />
                                <Plane className="h-6 w-6 text-primary absolute inset-0 m-auto" />
                            </div>
                            <div className="text-center">
                                <p className="text-xl font-bold">Searching all available airlines...</p>
                                <p className="text-muted-foreground">This normally takes just a few seconds.</p>
                            </div>
                        </div>
                    )}

                    {results.length > 0 ? (
                        searchDisplayMode === 'per_flight'
                            ? groupedFlights.map(renderFlightWithOffers)
                            : results.map(renderOfferCard)
                    ) : (
                        !loading && (
                            <div className="text-center py-24 border rounded-3xl bg-card shadow-sm">
                                <Plane className="h-16 w-16 text-muted-foreground/20 mx-auto mb-4" />
                                <h3 className="text-xl font-bold">No Flights Found</h3>
                                <p className="text-muted-foreground max-w-xs mx-auto">We couldn't find any flights for your selected route and date. Try adjusting your search.</p>
                                <Link href={route('bookings.index')} className="mt-6 inline-block">
                                    <Button variant="outline" className="font-bold">Modify Search</Button>
                                </Link>
                            </div>
                        )
                    )}
                </div>
            </div>
        </TenantLayout>
    );
}