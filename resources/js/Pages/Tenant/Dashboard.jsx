import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';

export default function Dashboard({ stats, recentBookings, providerStatus }) {
    const cards = [
        { label: "Today's Bookings", value: stats.todaysBookings },
        { label: 'Issued Tickets', value: stats.issuedTickets },
        { label: 'Active Users', value: stats.activeAgents },
        { label: 'Active Providers', value: stats.activeProviders },
    ];

    return (
        <TenantLayout>
            <Head title="Dashboard" />
            
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                {cards.map((card) => (
                    <Card key={card.label}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">{card.label}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{card.value}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="mt-8 grid gap-6 xl:grid-cols-[2fr_1fr]">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-xl">Recent Bookings</CardTitle>
                        <Link href={route('bookings.index')} className="text-sm font-medium text-primary">
                            View all
                        </Link>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {recentBookings.length === 0 ? (
                            <p className="text-muted-foreground">No bookings yet.</p>
                        ) : recentBookings.map((booking) => (
                            <div key={booking.id} className="flex items-center justify-between rounded-lg border p-4">
                                <div>
                                    <p className="font-semibold">{booking.pnr}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {booking.customer?.first_name} {booking.customer?.last_name} • {booking.provider?.airline_name}
                                    </p>
                                </div>
                                <div className="text-right">
                                    <Badge variant={booking.status === 'ticketed' ? 'success' : 'secondary'}>{booking.status}</Badge>
                                    <p className="mt-2 text-sm font-medium">{booking.total_price} {booking.currency}</p>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-xl">Provider Health</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {providerStatus.length === 0 ? (
                            <p className="text-muted-foreground">No providers configured yet.</p>
                        ) : providerStatus.map((provider) => (
                            <div key={provider.id} className="rounded-lg border p-4">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="font-semibold">{provider.airline_name}</p>
                                        <p className="text-sm text-muted-foreground">{provider.account_name}</p>
                                    </div>
                                    <Badge variant={provider.last_test_status === 'passed' ? 'success' : provider.is_active ? 'default' : 'outline'}>
                                        {provider.last_test_status || (provider.is_active ? 'active' : 'inactive')}
                                    </Badge>
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {provider.last_test_message || 'No connection check recorded yet.'}
                                </p>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </TenantLayout>
    );
}
