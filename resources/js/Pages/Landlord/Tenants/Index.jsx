import React from 'react';
import { Head, Link } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';

export default function Index({ tenants }) {
    return (
        <LandlordLayout>
            <Head title="Agencies" />

            <div className="mx-auto max-w-7xl p-6 space-y-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Agencies</h1>
                    <p className="text-muted-foreground">Review agency status, tenant health, and provider footprint.</p>
                </div>

                <div className="rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Agency</TableHead>
                                <TableHead>Owner</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Providers</TableHead>
                                <TableHead>Users</TableHead>
                                <TableHead>Bookings</TableHead>
                                <TableHead className="text-right">Action</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tenants.map((tenant) => (
                                <TableRow key={tenant.id}>
                                    <TableCell>
                                        <div>
                                            <p className="font-semibold">{tenant.company_name || tenant.id}</p>
                                            <p className="text-xs text-muted-foreground">{tenant.domains.join(', ')}</p>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div>
                                            <p>{tenant.owner_name || 'Unassigned'}</p>
                                            <p className="text-xs text-muted-foreground">{tenant.owner_email}</p>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant={tenant.status === 'active' ? 'success' : tenant.status === 'frozen' ? 'secondary' : 'destructive'}>
                                            {tenant.status}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>{tenant.stats.active_providers}/{tenant.stats.providers}</TableCell>
                                    <TableCell>{tenant.stats.active_users}/{tenant.stats.users}</TableCell>
                                    <TableCell>{tenant.stats.bookings}</TableCell>
                                    <TableCell className="text-right">
                                        <Button asChild size="sm" variant="ghost">
                                            <Link href={route('landlord.tenants.show', tenant.id)}>Open</Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </LandlordLayout>
    );
}
