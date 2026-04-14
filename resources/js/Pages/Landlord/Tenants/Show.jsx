import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';

export default function Show({ tenantRecord }) {
    const updateStatus = (status) => {
        router.patch(route('landlord.tenants.status', tenantRecord.id), { status });
    };

    return (
        <LandlordLayout>
            <Head title={tenantRecord.company_name || tenantRecord.id} />

            <div className="mx-auto max-w-6xl p-6 space-y-8">
                <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-bold tracking-tight">{tenantRecord.company_name || tenantRecord.id}</h1>
                            <Badge variant={tenantRecord.status === 'active' ? 'success' : tenantRecord.status === 'frozen' ? 'secondary' : 'destructive'}>
                                {tenantRecord.status}
                            </Badge>
                        </div>
                        <p className="text-muted-foreground">{tenantRecord.domains.join(', ')}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => updateStatus('active')}>Activate</Button>
                        <Button variant="secondary" onClick={() => updateStatus('frozen')}>Freeze</Button>
                        <Button variant="destructive" onClick={() => updateStatus('suspended')}>Suspend</Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader><CardTitle>Agency Profile</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p><span className="font-semibold">Owner:</span> {tenantRecord.owner_name || 'Unassigned'}</p>
                            <p><span className="font-semibold">Email:</span> {tenantRecord.owner_email}</p>
                            <p><span className="font-semibold">Phone:</span> {tenantRecord.owner_phone || 'N/A'}</p>
                            <p><span className="font-semibold">Plan:</span> {tenantRecord.subscription_plan || 'Not assigned'}</p>
                            <p><span className="font-semibold">Subscription:</span> {tenantRecord.subscription_status}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Tenant Health</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p><span className="font-semibold">Users:</span> {tenantRecord.snapshot.stats.users}</p>
                            <p><span className="font-semibold">Active Users:</span> {tenantRecord.snapshot.stats.active_users}</p>
                            <p><span className="font-semibold">Providers:</span> {tenantRecord.snapshot.stats.active_providers}/{tenantRecord.snapshot.stats.providers}</p>
                            <p><span className="font-semibold">Bookings:</span> {tenantRecord.snapshot.stats.bookings}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Tenant Admin</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {tenantRecord.snapshot.admin_user ? (
                                <>
                                    <p><span className="font-semibold">Name:</span> {tenantRecord.snapshot.admin_user.name}</p>
                                    <p><span className="font-semibold">Email:</span> {tenantRecord.snapshot.admin_user.email}</p>
                                    <p><span className="font-semibold">Last Login:</span> {tenantRecord.snapshot.admin_user.last_login_at || 'Never'}</p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">No admin user found in this tenant.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader><CardTitle>Configured Providers</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Airline</TableHead>
                                    <TableHead>Account</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Last Test</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tenantRecord.snapshot.providers.map((provider) => (
                                    <TableRow key={provider.id}>
                                        <TableCell>{provider.airline_name}</TableCell>
                                        <TableCell>{provider.account_name}</TableCell>
                                        <TableCell>
                                            <Badge variant={provider.is_active ? 'success' : 'outline'}>
                                                {provider.is_active ? 'active' : 'inactive'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{provider.last_test_status || 'untested'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Recent Bookings</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>PNR</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Provider</TableHead>
                                    <TableHead>Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tenantRecord.snapshot.recent_bookings.map((booking) => (
                                    <TableRow key={booking.id}>
                                        <TableCell>{booking.pnr}</TableCell>
                                        <TableCell>{booking.status}</TableCell>
                                        <TableCell>{booking.provider?.airline_name}</TableCell>
                                        <TableCell>{booking.total_price} {booking.currency}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </LandlordLayout>
    );
}
