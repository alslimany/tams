import React from 'react';
import { Head, Link } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';

export default function Dashboard({ stats, recentRegistrations, flightCacheSettings }) {
    const cards = [
        { label: 'Total agencies', value: stats.totalAgencies },
        { label: 'Active agencies', value: stats.activeAgencies },
        { label: 'Frozen agencies', value: stats.frozenAgencies },
        { label: 'Suspended agencies', value: stats.suspendedAgencies },
        { label: 'Active providers', value: stats.activeProviders },
        { label: 'Tenant users', value: stats.tenantUsers },
    ];

    return (
        <LandlordLayout>
            <Head title="Landlord Dashboard" />

            <div className="mx-auto max-w-7xl p-6 space-y-8">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Landlord Dashboard</h1>
                    <p className="text-muted-foreground">Central visibility across agencies and platform operations.</p>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
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

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Recent Registrations</CardTitle>
                        <Link href={route('landlord.tenants.index')} className="text-sm font-medium text-primary">View all agencies</Link>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {recentRegistrations.length === 0 ? (
                            <p className="text-muted-foreground">No agencies registered yet.</p>
                        ) : recentRegistrations.map((tenant) => (
                            <div key={tenant.id} className="flex items-center justify-between rounded-lg border p-4">
                                <div>
                                    <p className="font-semibold">{tenant.company_name || tenant.id}</p>
                                    <p className="text-sm text-muted-foreground">{tenant.owner_email}</p>
                                </div>
                                <Badge variant={tenant.status === 'active' ? 'success' : tenant.status === 'frozen' ? 'secondary' : 'destructive'}>
                                    {tenant.status}
                                </Badge>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div className="space-y-1">
                            <CardTitle>Global Flight Cache</CardTitle>
                            <p className="text-sm text-muted-foreground">Manage route learning, cached schedule coverage, and provider availability from a dedicated admin page.</p>
                        </div>
                        <Link href={route('landlord.settings.flight-cache.index')} className="text-sm font-medium text-primary">
                            Open flight cache
                        </Link>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex flex-wrap gap-3">
                            <Badge variant={flightCacheSettings?.route_availability_enabled ? 'success' : 'secondary'}>
                                Route availability {flightCacheSettings?.route_availability_enabled ? 'enabled' : 'disabled'}
                            </Badge>
                            <Badge variant={flightCacheSettings?.schedule_cache_enabled ? 'success' : 'secondary'}>
                                Schedule cache {flightCacheSettings?.schedule_cache_enabled ? 'enabled' : 'disabled'}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Open the flight cache page to review provider routes, availability coverage, and cached schedule rows.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </LandlordLayout>
    );
}
