import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';

function statusVariant(status) {
    switch (status) {
        case 'completed': return 'default';
        case 'running':   return 'secondary';
        case 'failed':    return 'destructive';
        default:          return 'outline';
    }
}

function formatDuration(seconds) {
    if (seconds == null) return '—';
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
}

export default function MigrationIndex({ connectionOk, records, legacyConfig }) {
    return (
        <LandlordLayout>
            <Head title="Legacy Migration" />

            <div className="mx-auto max-w-7xl p-6 space-y-8">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Legacy Agent Migration</h1>
                        <p className="text-muted-foreground">Import agents from the old Booknow system into the new platform.</p>
                    </div>
                    <Link href={route('landlord.migration.agents')}>
                        <Button disabled={!connectionOk}>Import New Agent</Button>
                    </Link>
                </div>

                {/* Connection Status */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Legacy Database Connection</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center gap-4">
                            <Badge variant={connectionOk ? 'default' : 'destructive'}>
                                {connectionOk ? '✓ Connected' : '✗ Unreachable'}
                            </Badge>
                            <span className="text-sm text-muted-foreground">
                                {legacyConfig.host} / {legacyConfig.database}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.reload()}
                            >
                                Test Connection
                            </Button>
                        </div>
                        {!connectionOk && (
                            <p className="mt-2 text-sm text-destructive">
                                Cannot reach the legacy database. Check LEGACY_DB_* environment variables.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Past Migrations */}
                <Card>
                    <CardHeader>
                        <CardTitle>Past Migrations</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {records.length === 0 ? (
                            <p className="p-6 text-sm text-muted-foreground">No migrations yet.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Agent</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Orders</TableHead>
                                        <TableHead className="text-right">Items</TableHead>
                                        <TableHead className="text-right">Customers</TableHead>
                                        <TableHead>Duration</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {records.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell>
                                                <div className="font-medium">{r.legacy_agent_name}</div>
                                                {r.legacy_agent_number && (
                                                    <div className="text-xs text-muted-foreground">{r.legacy_agent_number}</div>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={statusVariant(r.status)}>
                                                    {r.status === 'running' ? '⟳ Running' : r.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">{r.orders_migrated.toLocaleString()}</TableCell>
                                            <TableCell className="text-right">{r.items_migrated.toLocaleString()}</TableCell>
                                            <TableCell className="text-right">{r.customers_migrated.toLocaleString()}</TableCell>
                                            <TableCell>{formatDuration(r.duration_seconds)}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {new Date(r.created_at).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                {r.status === 'running' || r.status === 'pending' ? (
                                                    <Link href={route('landlord.migration.status', r.id)}>
                                                        <Button variant="outline" size="sm">View Status</Button>
                                                    </Link>
                                                ) : r.status === 'completed' ? (
                                                    <Link href={route('landlord.migration.report', r.id)}>
                                                        <Button variant="outline" size="sm">Report</Button>
                                                    </Link>
                                                ) : (
                                                    <Link href={route('landlord.migration.status', r.id)}>
                                                        <Button variant="outline" size="sm">Details</Button>
                                                    </Link>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </LandlordLayout>
    );
}
