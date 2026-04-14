import React from 'react';
import { Head, Link } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';

export default function Dashboard({ stats, recentRegistrations }) {
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

            <div className="mx-auto max-w-6xl p-6 space-y-8">
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
            </div>
        </LandlordLayout>
    );
}
