import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/Dialog';

export default function MigrationAgents({ connectionOk, agents, agencyTenants, allTenants = [] }) {
    const [search, setSearch] = useState('');
    const [selectedAgent, setSelectedAgent] = useState(null);

    const { data, setData, post, processing, errors } = useForm({
        legacy_agent_id: '',
        include_voided: false,
        date_from: '',
        agency_network_tenant_id: '',
        existing_tenant_id: '',
    });

    const filtered = agents.filter((a) =>
        a.name.toLowerCase().includes(search.toLowerCase()) ||
        (a.number ?? '').toLowerCase().includes(search.toLowerCase()),
    );

    function openDialog(agent) {
        setSelectedAgent(agent);
        setData('legacy_agent_id', agent.id);
    }

    function closeDialog() {
        setSelectedAgent(null);
    }

    function submit(e) {
        e.preventDefault();
        post(route('landlord.migration.run'), {
            onSuccess: () => closeDialog(),
        });
    }

    if (!connectionOk) {
        return (
            <LandlordLayout>
                <Head title="Select Agent" />
                <div className="mx-auto max-w-7xl p-6">
                    <Card>
                        <CardContent className="p-6">
                            <p className="text-destructive font-medium">Cannot connect to legacy database.</p>
                            <p className="text-sm text-muted-foreground mt-1">
                                Check LEGACY_DB_* environment variables and ensure the MySQL server is running.
                            </p>
                            <Link href={route('landlord.migration.index')} className="mt-4 inline-block">
                                <Button variant="outline">← Back to Migration Hub</Button>
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </LandlordLayout>
        );
    }

    return (
        <LandlordLayout>
            <Head title="Select Agent to Import" />

            <div className="mx-auto max-w-7xl p-6 space-y-6">
                <div className="flex items-center gap-4">
                    <Link href={route('landlord.migration.index')}>
                        <Button variant="ghost" size="sm">← Back</Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Select Agent to Import</h1>
                        <p className="text-muted-foreground text-sm">{agents.length} agents found in legacy system</p>
                    </div>
                </div>

                <Input
                    placeholder="Search by name or number..."
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="max-w-sm"
                />

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Number</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead className="text-right">Orders</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Joined</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                            No agents found.
                                        </TableCell>
                                    </TableRow>
                                ) : filtered.map((agent) => (
                                    <TableRow key={agent.id}>
                                        <TableCell className="font-mono text-sm">{agent.number ?? '—'}</TableCell>
                                        <TableCell className="font-medium">{agent.name}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{agent.email ?? '—'}</TableCell>
                                        <TableCell className="text-right">{agent.order_count.toLocaleString()}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{agent.agent_type}</Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {agent.joined_at ? new Date(agent.joined_at).toLocaleDateString() : '—'}
                                        </TableCell>
                                        <TableCell>
                                            {agent.already_migrated ? (
                                                <Button size="sm" variant="outline" onClick={() => openDialog(agent)}>
                                                    Re-import
                                                </Button>
                                            ) : (
                                                <Button size="sm" onClick={() => openDialog(agent)}>
                                                    Import
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {/* Configuration Dialog */}
            <Dialog open={!!selectedAgent} onOpenChange={(open) => !open && closeDialog()}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Import Agent</DialogTitle>
                    </DialogHeader>

                    {selectedAgent && (
                        <form onSubmit={submit} className="space-y-4">
                            <div className="rounded-md bg-muted p-3 text-sm">
                                <div className="font-medium">{selectedAgent.name}</div>
                                <div className="text-muted-foreground">
                                    ~{selectedAgent.order_count.toLocaleString()} orders
                                </div>
                            </div>

                            {/* Existing tenant option */}
                            <div className="space-y-1">
                                <label className="text-sm font-medium">Migrate into existing tenant</label>
                                <select
                                    value={data.existing_tenant_id}
                                    onChange={(e) => setData('existing_tenant_id', e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                >
                                    <option value="">— Create new tenant —</option>
                                    {allTenants.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.company_name} ({t.id})
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-muted-foreground">
                                    Select an existing tenant to import orders into it. Users will be matched by email. Already-imported orders are skipped automatically.
                                </p>
                                {errors.existing_tenant_id && (
                                    <p className="text-sm text-destructive">{errors.existing_tenant_id}</p>
                                )}
                            </div>

                            {/* Only show these options when creating a new tenant */}
                            {!data.existing_tenant_id && (
                                <div className="space-y-1">
                                    <label className="text-sm font-medium">Import orders from date</label>
                                    <Input
                                        type="date"
                                        value={data.date_from}
                                        onChange={(e) => setData('date_from', e.target.value)}
                                    />
                                    <p className="text-xs text-muted-foreground">Leave blank to import all orders.</p>
                                </div>
                            )}

                            {data.existing_tenant_id && (
                                <div className="space-y-1">
                                    <label className="text-sm font-medium">Import orders from date</label>
                                    <Input
                                        type="date"
                                        value={data.date_from}
                                        onChange={(e) => setData('date_from', e.target.value)}
                                    />
                                    <p className="text-xs text-muted-foreground">Leave blank to import all orders. Duplicate orders are skipped.</p>
                                </div>
                            )}

                            <div className="flex items-center gap-2">
                                <input
                                    id="include_voided"
                                    type="checkbox"
                                    checked={data.include_voided}
                                    onChange={(e) => setData('include_voided', e.target.checked)}
                                    className="h-4 w-4 rounded border-input"
                                />
                                <label htmlFor="include_voided" className="text-sm">
                                    Include voided / cancelled orders
                                </label>
                            </div>

                            <div className="space-y-1">
                                <label className="text-sm font-medium">Agency Network</label>
                                <select
                                    value={data.agency_network_tenant_id}
                                    onChange={(e) => setData('agency_network_tenant_id', e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                >
                                    <option value="">— None (no provider linking) —</option>
                                    {(agencyTenants ?? []).map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.company_name} ({t.provider_count} provider{t.provider_count !== 1 ? 's' : ''})
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-muted-foreground">
                                    {data.existing_tenant_id
                                        ? 'Optional. The tenant\'s own providers are used first; agency providers supplement any unmatched IATA codes.'
                                        : 'Select an agency to link order items to its airline providers and join the agent as a merchant.'}
                                </p>
                                {errors.agency_network_tenant_id && (
                                    <p className="text-sm text-destructive">{errors.agency_network_tenant_id}</p>
                                )}
                            </div>

                            {errors.legacy_agent_id && (
                                <p className="text-sm text-destructive">{errors.legacy_agent_id}</p>
                            )}

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={closeDialog}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Starting...' : 'Start Migration'}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </LandlordLayout>
    );
}
