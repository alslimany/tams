import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeftIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function WarehouseDetail({ warehouse, stock }) {
    const { t } = useTranslation();

    const totalValue = stock.reduce((sum, row) => sum + row.totalValue, 0);

    return (
        <TenantLayout>
            <Head title={`${warehouse.code} — ${t('accounting.nav.warehouses')}`} />
            <AccountingLayout
                title={`${warehouse.code} — ${warehouse.name}`}
                subtitle={warehouse.address || 'Warehouse stock levels'}
                actions={
                    <div className="flex items-center gap-2">
                        {warehouse.isActive ? (
                            <Badge variant="success">Active</Badge>
                        ) : (
                            <Badge variant="secondary">Inactive</Badge>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('accounting.inventory.warehouses')}>
                                <ArrowLeftIcon className="mr-1.5 size-3.5" /> All Warehouses
                            </Link>
                        </Button>
                    </div>
                }
            >
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item Code</TableHead>
                                <TableHead>Item Name</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead className="text-right">Quantity</TableHead>
                                <TableHead className="text-right">Avg Unit Cost</TableHead>
                                <TableHead className="text-right">Total Value</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {stock.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-10 text-center text-sm text-muted-foreground">
                                        No stock in this warehouse yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                stock.map((row) => (
                                    <TableRow key={row.itemId}>
                                        <TableCell>
                                            <Link
                                                href={route('accounting.inventory.items.show', row.itemId)}
                                                className="font-mono text-xs text-primary hover:underline"
                                            >
                                                {row.itemCode}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-sm">{row.itemName}</TableCell>
                                        <TableCell className="text-xs capitalize text-muted-foreground">
                                            {row.unit}
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
                            {stock.length > 0 && (
                                <TableRow className="border-t-2 font-semibold">
                                    <TableCell colSpan={5} className="text-sm">
                                        Total Stock Value
                                    </TableCell>
                                    <TableCell className="text-right text-sm">
                                        <AmountDisplay amount={totalValue} />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
