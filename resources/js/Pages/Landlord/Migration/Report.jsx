import React from 'react';
import { Head, Link } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';

function formatDuration(seconds) {
    if (seconds == null) return '—';
    if (seconds < 60) return `${seconds}s`;
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

export default function MigrationReport({ record }) {
    const warnings = (record.log ?? []).filter((l) => l.step === 'warning');

    const rows = [
        { label: 'Agent name',        value: record.legacy_agent_name },
        { label: 'Agent number',       value: record.legacy_agent_number ?? '—' },
        { label: 'Tenant created',     value: record.tenant_id ?? '—' },
        { label: 'Users migrated',     value: '(see tenant)' },
        { label: 'Customers migrated', value: (record.customers_migrated ?? 0).toLocaleString() },
        { label: 'Orders migrated',    value: (record.orders_migrated ?? 0).toLocaleString() },
        { label: 'Order items',        value: (record.items_migrated ?? 0).toLocaleString() },
    ];

    return (
        <LandlordLayout>
            <Head title={`Migration Report — ${record.legacy_agent_name}`} />

            <div className="mx-auto max-w-3xl p-6 space-y-6">
                <div className="flex items-center gap-4">
                    <Link href={route('landlord.migration.index')}>
                        <Button variant="ghost" size="sm">← Back</Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Migration Report — {record.legacy_agent_name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {record.completed_at && <>Completed {new Date(record.completed_at).toLocaleString()}</>}
                            {record.duration_seconds != null && <> · Duration: {formatDuration(record.duration_seconds)}</>}
                        </p>
                    </div>
                </div>

                {/* Summary */}
                <Card>
                    <CardHeader>
                        <CardTitle>Summary</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <table className="w-full text-sm">
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.label} className="border-b last:border-0">
                                        <td className="py-2 text-muted-foreground w-48">{row.label}</td>
                                        <td className="py-2 font-medium">{row.value}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Warnings */}
                {warnings.length > 0 && (
                    <Card className="border-amber-300">
                        <CardHeader>
                            <CardTitle className="text-amber-600 text-base">
                                ⚠ {warnings.length} Warning{warnings.length !== 1 ? 's' : ''}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-1 text-sm text-muted-foreground max-h-64 overflow-y-auto">
                                {warnings.map((w, i) => (
                                    <li key={i} className="flex gap-2">
                                        <span className="text-amber-500">⚠</span>
                                        {w.migrated}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* Post-migration notes */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Post-Migration Checklist</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="space-y-2 text-sm text-muted-foreground list-disc list-inside">
                            <li>Set provider credentials (Videcom / airline API keys) in tenant provider settings.</li>
                            <li>Fund provider wallets — historical orders are imported but wallets start at 0.</li>
                            <li>Optionally run <code className="font-mono bg-muted px-1 rounded">php artisan migration:backfill-ledger {record.tenant_id}</code> to post historical ledger entries.</li>
                            <li>Verify order count matches the old system.</li>
                        </ul>
                    </CardContent>
                </Card>

                {/* Actions */}
                <div className="flex gap-3">
                    {record.tenant_id && (
                        <a href={`/agency/${record.tenant_id}/login`} target="_blank" rel="noreferrer">
                            <Button>Open Tenant ↗</Button>
                        </a>
                    )}
                    <Link href={route('landlord.migration.agents')}>
                        <Button variant="outline">Migrate Another Agent</Button>
                    </Link>
                </div>
            </div>
        </LandlordLayout>
    );
}
