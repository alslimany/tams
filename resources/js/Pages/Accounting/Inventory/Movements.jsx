import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import MovementTypeBadge from '@/Components/Accounting/MovementTypeBadge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function Movements({ movements, filters, items, warehouses }) {
    const { t } = useTranslation();

    function applyFilters(next) {
        router.get(
            route('accounting.inventory.movements'),
            {
                type: next.type !== undefined ? next.type : (filters.type ?? ''),
                item: next.item !== undefined ? next.item : (filters.item ?? ''),
                warehouse: next.warehouse !== undefined ? next.warehouse : (filters.warehouse ?? ''),
            },
            { preserveState: true },
        );
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.movement_log')} />
            <AccountingLayout
                title={t('accounting.nav.movement_log')}
                subtitle={`${movements.total} movements`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.type ?? 'all'}
                            onValueChange={(value) => applyFilters({ type: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger size="sm">
                                <SelectValue placeholder="All types" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All types</SelectItem>
                                <SelectItem value="receive">Receive</SelectItem>
                                <SelectItem value="deliver">Deliver</SelectItem>
                                <SelectItem value="transfer">Transfer</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.item ? String(filters.item) : 'all'}
                            onValueChange={(value) => applyFilters({ item: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger size="sm">
                                <SelectValue placeholder="All items" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All items</SelectItem>
                                {items.map((item) => (
                                    <SelectItem key={item.id} value={String(item.id)}>
                                        {item.code} — {item.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.warehouse ? String(filters.warehouse) : 'all'}
                            onValueChange={(value) => applyFilters({ warehouse: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger size="sm">
                                <SelectValue placeholder="All warehouses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All warehouses</SelectItem>
                                {warehouses.map((warehouse) => (
                                    <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                                        {warehouse.code} — {warehouse.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                }
            >
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Reference</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Item</TableHead>
                                <TableHead>From → To</TableHead>
                                <TableHead className="text-right">Qty</TableHead>
                                <TableHead className="text-right">Unit Cost</TableHead>
                                <TableHead className="text-right">Total Cost</TableHead>
                                <TableHead>Ledger</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movements.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={9} className="py-10 text-center text-sm text-muted-foreground">
                                        No movements found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                movements.data.map((movement) => (
                                    <TableRow key={movement.id}>
                                        <TableCell className="font-mono text-xs">{movement.reference}</TableCell>
                                        <TableCell>
                                            <MovementTypeBadge type={movement.type} />
                                        </TableCell>
                                        <TableCell className="text-xs">{movement.movementDate}</TableCell>
                                        <TableCell className="text-xs">
                                            <span className="font-mono text-muted-foreground">
                                                {movement.itemCode}
                                            </span>{' '}
                                            {movement.itemName}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                            {movement.fromWarehouse ?? '—'} → {movement.toWarehouse ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right text-sm tabular-nums">
                                            {movement.quantity}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            <AmountDisplay amount={movement.unitCost} />
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            <AmountDisplay amount={movement.totalCost} />
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {movement.ledgerEntryId ? (
                                                <Link
                                                    href={route('accounting.ledger.journal.show', movement.ledgerEntryId)}
                                                    className="text-primary hover:underline"
                                                >
                                                    #{movement.ledgerEntryId}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>

                {movements.links && movements.links.length > 3 && (
                    <div className="mt-4 flex flex-wrap items-center justify-center gap-1">
                        {movements.links.map((link, index) => (
                            <Button
                                key={index}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </AccountingLayout>
        </TenantLayout>
    );
}
