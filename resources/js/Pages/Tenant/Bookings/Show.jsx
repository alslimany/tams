import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/Card";
import { Badge } from "@/Components/ui/Badge";
import { Button } from "@/Components/ui/Button";
import { Plane, Calendar, Users, CreditCard, CheckCircle2, AlertCircle, ArrowRight, User, Info } from "lucide-react";

export default function Show({ booking }) {
    const { auth } = usePage().props;
    const canManageTickets = auth.user?.role === 'admin' || auth.user?.role === 'manager';
    
    // Format dates nicely
    const formatDate = (dateString) => {
        if (!dateString) return '';
        return new Date(dateString).toLocaleDateString('en-US', {
            weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
        });
    };

    const formatTime = (dateString) => {
        if (!dateString) return '';
        return new Date(dateString).toLocaleTimeString('en-US', {
            hour: '2-digit', minute: '2-digit'
        });
    };

    return (
        <TenantLayout>
            <Head title={`Booking ${booking.pnr}`} />

            <div className="max-w-5xl mx-auto py-8 px-4">
                
                {/* Header Section */}
                <div className="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 gap-4">
                    <div>
                        <div className="flex items-center gap-3 mb-2">
                            <Badge variant={booking.status === 'pending' ? 'secondary' : 'default'} className="px-3 py-1 uppercase tracking-widest text-xs font-black">
                                {booking.status === 'pending' ? 'Awaiting Payment' : booking.status}
                            </Badge>
                            <span className="text-sm font-bold text-muted-foreground">Booking Reference</span>
                        </div>
                        <h1 className="text-5xl font-black tracking-tight text-primary">{booking.pnr}</h1>
                    </div>
                    {booking.status === 'pending' && (
                        <Button size="lg" className="rounded-full shadow-lg font-black bg-indigo-600 hover:bg-indigo-700 text-white px-8">
                            <CreditCard className="mr-2 h-5 w-5" /> Proceed to Payment
                        </Button>
                    )}
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    {/* Left Column (Flight & Passengers) */}
                    <div className="lg:col-span-2 space-y-8">
                        
                        <Card className="border-2 shadow-sm overflow-hidden">
                            <div className="bg-muted/30 p-4 border-b flex justify-between items-center">
                                <h2 className="font-black text-lg flex items-center gap-2">
                                    <Plane className="h-5 w-5 text-primary" /> Flight Itinerary
                                </h2>
                                <span className="text-sm font-bold text-muted-foreground">{booking.provider?.airline_name || 'Airline'}</span>
                            </div>
                            <CardContent className="p-0">
                                <div className="divide-y relative">
                                    <div className="absolute left-10 top-12 bottom-12 w-0.5 bg-primary/20 hidden sm:block"></div>
                                    {booking.flight_segments?.map((segment, index) => (
                                        <div key={segment.id} className="p-6 sm:pl-20 relative flex flex-col sm:flex-row gap-6 hover:bg-muted/5 transition-colors">
                                            <div className="hidden sm:flex absolute left-8 top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-background border-2 border-primary shadow-sm z-10 items-center justify-center">
                                                <div className="w-1.5 h-1.5 bg-primary rounded-full"></div>
                                            </div>
                                            
                                            <div className="flex-1">
                                                <p className="text-xs font-bold text-primary uppercase tracking-widest mb-1">Departure</p>
                                                <p className="text-2xl font-black">{segment.origin_airport}</p>
                                                <p className="text-sm font-medium text-muted-foreground flex items-center gap-1 mt-1">
                                                    <Calendar className="h-3 w-3" /> {formatDate(segment.departure_time)} at {formatTime(segment.departure_time)}
                                                </p>
                                            </div>
                                            
                                            <div className="flex items-center justify-center sm:px-6">
                                                <ArrowRight className="h-6 w-6 text-muted-foreground/30 hidden sm:block" />
                                                <Plane className="h-6 w-6 text-muted-foreground/30 sm:hidden -rotate-90 my-4" />
                                            </div>
                                            
                                            <div className="flex-1 sm:text-right">
                                                <p className="text-xs font-bold text-primary uppercase tracking-widest mb-1">Arrival</p>
                                                <p className="text-2xl font-black">{segment.destination_airport}</p>
                                                <p className="text-sm font-medium text-muted-foreground flex items-center gap-1 mt-1 sm:justify-end">
                                                    <Calendar className="h-3 w-3" /> {formatDate(segment.arrival_time)} at {formatTime(segment.arrival_time)}
                                                </p>
                                            </div>
                                            
                                            <div className="w-full sm:w-auto p-4 bg-muted/30 rounded-xl sm:ml-4 text-center sm:text-left flex flex-col justify-center">
                                                <p className="text-xs font-bold text-muted-foreground uppercase tracking-widest mb-1">Flight</p>
                                                <p className="font-black text-lg">{segment.flight_number}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card className="border-2 shadow-sm">
                            <div className="bg-muted/30 p-4 border-b flex justify-between items-center">
                                <h2 className="font-black text-lg flex items-center gap-2">
                                    <Users className="h-5 w-5 text-primary" /> Passengers
                                </h2>
                            </div>
                            <CardContent className="p-6">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    {booking.passengers?.map(pax => (
                                        <div key={pax.id} className="p-4 border rounded-2xl flex items-center gap-4 bg-card hover:border-primary/30 transition-colors shadow-sm">
                                            <div className="bg-primary/10 p-3 rounded-full">
                                                <User className="h-5 w-5 text-primary" />
                                            </div>
                                            <div>
                                                <p className="font-bold text-lg leading-tight uppercase">{pax.first_name} {pax.last_name}</p>
                                                <p className="text-xs font-bold text-muted-foreground uppercase tracking-widest mt-1">
                                                    {pax.type} • {pax.gender === 'M' ? 'Male' : 'Female'}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                    </div>

                    {/* Right Column (Summary & Payment) */}
                    <div className="space-y-6">
                        <Card className="border-2 border-primary/20 shadow-lg relative overflow-hidden">
                            <div className="absolute top-0 left-0 w-full h-1 bg-primary"></div>
                            <CardContent className="p-6">
                                <h3 className="text-xs font-black uppercase tracking-widest text-muted-foreground mb-4">Payment Summary</h3>
                                
                                <div className="flex justify-between items-end mb-6">
                                    <span className="font-bold text-muted-foreground text-sm">Amount Due</span>
                                    <span className="text-4xl font-black text-primary">{booking.total_price} <span className="text-sm">{booking.currency}</span></span>
                                </div>
                                
                                <div className="space-y-3 pt-6 border-t border-dashed">
                                    <div className="flex justify-between text-sm font-medium">
                                        <span className="text-muted-foreground">Booking Status</span>
                                        <span className={booking.status === 'pending' ? 'text-amber-600 font-bold' : 'text-emerald-600 font-bold'}>
                                            {booking.status === 'pending' ? 'Unpaid' : 'Confirmed'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium">
                                        <span className="text-muted-foreground">Primary Contact</span>
                                        <span className="font-bold truncate max-w-[150px]">{booking.customer?.first_name}</span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium">
                                        <span className="text-muted-foreground">Email Address</span>
                                        <span className="font-bold truncate max-w-[150px]">{booking.customer?.email}</span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium">
                                        <span className="text-muted-foreground">Booking Date</span>
                                        <span className="font-bold">{formatDate(booking.created_at)}</span>
                                    </div>
                                </div>
                                
                                {booking.status === 'pending' ? (
                                    <div className="mt-8">
                                        <Button className="w-full rounded-xl py-6 text-base font-black shadow-md bg-indigo-600 hover:bg-indigo-700 text-white">
                                            Pay Now Securely
                                        </Button>
                                        <p className="text-xs text-center text-muted-foreground mt-3 flex justify-center items-center gap-1 font-medium">
                                            <AlertCircle className="h-3 w-3" /> Secure payment gateway
                                        </p>
                                    </div>
                                ) : (
                                    <div className="mt-8 bg-emerald-50 text-emerald-700 p-4 rounded-xl flex items-center justify-center gap-2 border border-emerald-100">
                                        <CheckCircle2 className="h-5 w-5" />
                                        <span className="font-bold">Payment Complete</span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card className="border-2 shadow-sm">
                            <CardHeader>
                                <CardTitle className="text-lg">Ticketing</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {booking.tickets?.length ? booking.tickets.map((ticket) => (
                                    <div key={ticket.id} className="rounded-xl border p-4 space-y-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <p className="font-bold">{ticket.ticket_number}</p>
                                                <p className="text-xs text-muted-foreground">Issued {formatDate(ticket.issued_at)}</p>
                                            </div>
                                            <Badge variant={ticket.status === 'issued' ? 'success' : ticket.status === 'voided' ? 'secondary' : 'destructive'}>
                                                {ticket.status}
                                            </Badge>
                                        </div>

                                        {ticket.status === 'issued' && canManageTickets && (
                                            <div className="flex gap-2">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={route('tickets.void', { booking: booking.id, ticket: ticket.id })} method="post" as="button">
                                                        Void
                                                    </Link>
                                                </Button>
                                                <Button asChild variant="destructive" size="sm">
                                                    <Link href={route('tickets.refund', { booking: booking.id, ticket: ticket.id })} method="post" as="button">
                                                        Refund
                                                    </Link>
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                )) : (
                                    <div className="rounded-xl border border-dashed p-4">
                                        <p className="text-sm text-muted-foreground mb-4">No ticket has been issued for this booking yet.</p>
                                        {booking.status !== 'cancelled' && booking.status !== 'refunded' && canManageTickets && (
                                            <Button asChild className="w-full">
                                                <Link href={route('tickets.issue', { booking: booking.id })} method="post" as="button">
                                                    Issue Ticket
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                        
                        <div className="bg-muted p-4 rounded-2xl flex gap-3 text-sm font-medium text-muted-foreground border">
                            <Info className="h-5 w-5 shrink-0 text-primary" />
                            <p>Prices and availability are not guaranteed until the booking is ticketed and fully paid.</p>
                        </div>
                    </div>
                    
                </div>
            </div>
        </TenantLayout>
    );
}
