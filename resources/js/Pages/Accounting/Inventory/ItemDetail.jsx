import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeftIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import MovementTypeBadge from '@/Components/Accounting/MovementTypeBadge';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function ItemDetail({ item, stock, movements }) {
    const { t } = useTranslation();

    return (
        <TenantLayout>
            <Head title={`${item.code} — ${t('accounting.nav.item_catalogue')}`} />
            <AccountingLayout
                title={`${item.code} — ${item.name}`}
                subtitle={item.description || 'Stock levels across warehouses'}
                actions={
                    <div className="flex items-center gap-2">
                        {item.isActive ? (
                            <Badge variant="success">Active</Badge>
                        ) : (
                            <Badge variant="secondary">Inactive</Badge>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('accounting.inventory.items')}>
                                <ArrowLeftIcon className="mr-1.5 size-3.5" /> All Items
                            </Link>
                        </Button>
                    </div>
                }
            >
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <Card className="p-4">
                        <p className="text-xs text-muted-foreground">Category / Unit</p>
                        <p className="mt-1 text-sm font-medium">
                            {item.category === 'travel_product' ? 'Travel product' : 'Physical good'}
                            <span className="capitalize text-muted-foreground"> · {item.unit}</span>
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs text-muted-foreground">Standard Unit Cost</p>
                        <p className="mt-1 text-sm font-medium">
                            <AmountDisplay amount={item.unitCost} />
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs text-muted-foreground">Accounts (Inv / COGS / Purch)</p>
                        <p className="mt-1 font-mono text-sm font-medium">
                            {item.inventoryAccount} / {item.cogsAccount} / {item.purchaseAccount}
                        </p>
                    </Card>
                </div>

                <h2 className="mb-2 text-sm font-semibold">Stock by Warehouse</h2>
                <Card className="mb-6">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Warehouse</TableHead>
                                <TableHead className="text-right">Quantity</TableHead>
                                <TableHead className="text-right">Avg Unit Cost</TableHead>
                                <TableHead className="text-right">Total Value</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {stock.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">
                                        No stock recorded for this item yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                stock.map((row) => (
                                    <TableRow key={row.warehouseId}>
                                        <TableCell className="text-sm">
                                            <Link
                                                href={route('accounting.inventory.warehouses.show', row.warehouseId)}
                                                className="text-primary hover:underline"
                                            >
                                                <span className="font-mono text-xs">{row.warehouseCode}</span>{' '}
                                                {row.warehouseName}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right text-sm tabular-nums">
                                            {row.quantity}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            <AmountDisplay amount={row.avgUnitCost} />
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            <AmountDisplay amount={row.totalValue} />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>

                <h2 className="mb-2 text-sm font-semibold">Recent Movements</h2>
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Reference</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>From → To</TableHead>
                                <TableHead className="text-right">Qty</TableHead>
                                <TableHead className="text-right">Total Cost</TableHead>
                                <TableHead>Ledger</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movements.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-8 text-center text-sm text-muted-foreground">
                                        No movements for this item yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                movements.map((movement) => (
                                    <TableRow key={movement.id}>
                                        <TableCell className="font-mono text-xs">{movement.reference}</TableCell>
                                        <TableCell>
                                            <MovementTypeBadge type={movement.type} />
                                        </TableCell>
                                        <TableCell className="text-xs">{movement.movementDate}</TableCell>
                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                            {movement.fromWarehouse ?? '—'} → {movement.toWarehouse ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right text-sm tabular-nums">
                                            {movement.quantity}
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
            </AccountingLayout>
        </TenantLayout>
    );
}
