import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { PlusIcon, WarehouseIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import AmountDisplay from '@/Components/Accounting/AmountDisplay';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { useTranslation } from '@/hooks/useTranslation';

export default function Warehouses({ warehouses }) {
    const { t } = useTranslation();
    const [dialogOpen, setDialogOpen] = React.useState(false);

    const form = useForm({
        name: '',
        code: '',
        type: 'physical',
        address: '',
        notes: '',
    });

    function handleSubmit(e) {
        e.preventDefault();
        form.post(route('accounting.inventory.warehouses.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setDialogOpen(false);
            },
        });
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.warehouses')} />
            <AccountingLayout
                title={t('accounting.nav.warehouses')}
                subtitle="Physical and virtual stock locations"
                actions={
                    <Button size="sm" onClick={() => setDialogOpen(true)}>
                        <PlusIcon className="mr-1.5 size-3.5" /> New Warehouse
                    </Button>
                }
            >
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">Items</TableHead>
                                <TableHead className="text-right">Total Qty</TableHead>
                                <TableHead className="text-right">Stock Value</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {warehouses.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-10 text-center text-sm text-muted-foreground">
                                        <WarehouseIcon className="mx-auto mb-2 size-6" />
                                        No warehouses yet. Create one to start tracking stock.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                warehouses.map((warehouse) => (
                                    <TableRow key={warehouse.id}>
                                        <TableCell>
                                            <Link
                                                href={route('accounting.inventory.warehouses.show', warehouse.id)}
                                                className="font-mono text-xs text-primary hover:underline"
                                            >
                                                {warehouse.code}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-sm">{warehouse.name}</TableCell>
                                        <TableCell>
                                            <span className="text-xs capitalize text-muted-foreground">
                                                {warehouse.type}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right text-sm">{warehouse.itemCount}</TableCell>
                                        <TableCell className="text-right text-sm tabular-nums">
                                            {warehouse.totalQuantity}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            <AmountDisplay amount={warehouse.totalValue} />
                                        </TableCell>
                                        <TableCell>
                                            {warehouse.isActive ? (
                                                <Badge variant="success">Active</Badge>
                                            ) : (
                                                <Badge variant="secondary">Inactive</Badge>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>

                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>New Warehouse</DialogTitle>
                            <DialogDescription>Add a physical or virtual stock location.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="wh-code">Code</Label>
                                    <Input
                                        id="wh-code"
                                        value={form.data.code}
                                        onChange={(e) => form.setData('code', e.target.value)}
                                        placeholder="WH-TRIPOLI"
                                    />
                                    {form.errors.code && (
                                        <p className="text-xs text-destructive">{form.errors.code}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="wh-type">Type</Label>
                                    <Select
                                        value={form.data.type}
                                        onValueChange={(value) => form.setData('type', value)}
                                    >
                                        <SelectTrigger id="wh-type" className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="physical">Physical</SelectItem>
                                            <SelectItem value="virtual">Virtual</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="wh-name">Name</Label>
                                <Input
                                    id="wh-name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="Tripoli Main Warehouse"
                                />
                                {form.errors.name && <p className="text-xs text-destructive">{form.errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="wh-address">Address (optional)</Label>
                                <Input
                                    id="wh-address"
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="wh-notes">Notes (optional)</Label>
                                <Input
                                    id="wh-notes"
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    Create Warehouse
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </AccountingLayout>
        </TenantLayout>
    );
}
