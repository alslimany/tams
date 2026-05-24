import React, { useEffect, useRef } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';

const STEPS = [
    { key: 'create_tenant',      label: 'Create Tenant' },
    { key: 'bootstrap_ledger',   label: 'Bootstrap Ledger' },
    { key: 'bootstrap_wallets',  label: 'Bootstrap Wallets' },
    { key: 'migrate_users',      label: 'Migrate Users' },
    { key: 'migrate_customers',  label: 'Migrate Customers' },
    { key: 'migrate_orders',     label: 'Migrate Orders' },
];

function stepStatus(log, key) {
    const entries = (log ?? []).filter((l) => l.step === key);
    if (entries.length === 0) return 'pending';
    const last = entries[entries.length - 1];
    if (last.migrated === 'done') return 'done';
    if (last.migrated === 'started') return 'running';
    return 'done';
}

function stepCount(log, key) {
    const entries = (log ?? []).filter((l) => l.step === key && typeof l.migrated === 'number');
    if (entries.length === 0) return null;
    return entries[entries.length - 1].migrated;
}

function StepIcon({ status }) {
    if (status === 'done')    return <span className="text-green-500 font-bold">✓</span>;
    if (status === 'running') return <span className="animate-spin inline-block">⟳</span>;
    return <span className="text-muted-foreground">○</span>;
}

function formatDuration(seconds) {
    if (seconds == null) return null;
    if (seconds < 60) return `${seconds}s`;
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

export default function MigrationStatus({ record }) {
    const intervalRef = useRef(null);
    const isTerminal  = record.status === 'completed' || record.status === 'failed';

    useEffect(() => {
        if (isTerminal) return;

        intervalRef.current = setInterval(() => {
            router.reload({ only: ['record'] });
        }, 3000);

        return () => clearInterval(intervalRef.current);
    }, [isTerminal]);

    const warnings = (record.log ?? []).filter((l) => l.step === 'warning');
    const duration  = formatDuration(record.duration_seconds);

    const completedSteps = STEPS.filter((s) => stepStatus(record.log, s.key) === 'done').length;
    const progress = Math.round((completedSteps / STEPS.length) * 100);

    return (
        <LandlordLayout>
            <Head title={`Migration: ${record.legacy_agent_name}`} />

            <div className="mx-auto max-w-3xl p-6 space-y-6">
                <div className="flex items-center gap-4">
                    <Link href={route('landlord.migration.index')}>
                        <Button variant="ghost" size="sm">← Back</Button>
                    </Link>
                    <div className="flex-1">
                        <h1 className="text-2xl font-bold tracking-tight">
                            Migration: {record.legacy_agent_name}
                        </h1>
                    </div>
                    <Badge variant={
                        record.status === 'completed' ? 'default' :
                        record.status === 'failed'    ? 'destructive' :
                        'secondary'
                    }>
                        {record.status === 'running' ? '⟳ Running...' : record.status}
                    </Badge>
                </div>

                {/* Progress bar */}
                <div className="h-2 w-full rounded-full bg-muted overflow-hidden">
                    <div
                        className="h-full bg-primary transition-all duration-500"
                        style={{ width: `${isTerminal && record.status === 'completed' ? 100 : progress}%` }}
                    />
                </div>

                {/* Steps */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Steps</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="space-y-3">
                            {STEPS.map((step) => {
                                const status = stepStatus(record.log, step.key);
                                const count  = stepCount(record.log, step.key);
                                return (
                                    <li key={step.key} className="flex items-center gap-3">
                                        <StepIcon status={status} />
                                        <span className={status === 'pending' ? 'text-muted-foreground' : ''}>
                                            {step.label}
                                        </span>
                                        {count != null && (
                                            <span className="ml-auto text-sm text-muted-foreground">
                                                {count.toLocaleString()} records
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </CardContent>
                </Card>

                {/* Stats */}
                {(record.orders_migrated > 0 || record.status === 'completed') && (
                    <div className="grid grid-cols-3 gap-4">
                        {[
                            { label: 'Orders', value: record.orders_migrated },
                            { label: 'Items', value: record.items_migrated },
                            { label: 'Customers', value: record.customers_migrated },
                        ].map((s) => (
                            <Card key={s.label}>
                                <CardContent className="p-4 text-center">
                                    <div className="text-2xl font-bold">{s.value.toLocaleString()}</div>
                                    <div className="text-sm text-muted-foreground">{s.label}</div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Error */}
                {record.status === 'failed' && record.error && (
                    <Card className="border-destructive">
                        <CardContent className="p-4">
                            <p className="text-sm font-medium text-destructive">Migration Failed</p>
                            <p className="text-sm text-muted-foreground mt-1 font-mono">{record.error}</p>
                        </CardContent>
                    </Card>
                )}

                {/* Warnings */}
                {warnings.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base text-amber-600">
                                ⚠ {warnings.length} Warning{warnings.length !== 1 ? 's' : ''}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-1 text-sm text-muted-foreground max-h-48 overflow-y-auto">
                                {warnings.map((w, i) => (
                                    <li key={i}>{w.migrated}</li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* Footer */}
                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>
                        {record.started_at && <>Started {new Date(record.started_at).toLocaleString()}</>}
                        {duration && <> · Duration: {duration}</>}
                    </span>
                    {record.status === 'completed' && record.id && (
                        <Link href={route('landlord.migration.report', record.id)}>
                            <Button>View Report →</Button>
                        </Link>
                    )}
                </div>
            </div>
        </LandlordLayout>
    );
}
