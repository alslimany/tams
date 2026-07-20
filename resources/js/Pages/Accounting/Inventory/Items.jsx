import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { PackageIcon, PlusIcon } from 'lucide-react';
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

export default function Items({ items }) {
    const { t } = useTranslation();
    const [dialogOpen, setDialogOpen] = React.useState(false);

    const form = useForm({
        code: '',
        name: '',
        category: 'physical_good',
        unit: 'piece',
        unit_cost: '0',
        description: '',
    });

    function handleSubmit(e) {
        e.preventDefault();
        form.post(route('accounting.inventory.items.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setDialogOpen(false);
            },
        });
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.item_catalogue')} />
            <AccountingLayout
                title={t('accounting.nav.item_catalogue')}
                subtitle="Items that can be stocked and moved between warehouses"
                actions={
                    <Button size="sm" onClick={() => setDialogOpen(true)}>
                        <PlusIcon className="mr-1.5 size-3.5" /> New Item
                    </Button>
                }
            >
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead className="text-right">Std. Cost</TableHead>
                                <TableHead className="text-right">On Hand</TableHead>
                                <TableHead>Accounts (Inv / COGS / Purch)</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={8} className="py-10 text-center text-sm text-muted-foreground">
                                        <PackageIcon className="mx-auto mb-2 size-6" />
                                        No items in the catalogue yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <Link
                                                href={route('accounting.inventory.items.show', item.id)}
                                                className="font-mono text-xs text-primary hover:underline"
                                            >
                                                {item.code}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-sm">{item.name}</TableCell>
                                        <TableCell>
                                            <span className="text-xs text-muted-foreground">
                                                {item.category === 'travel_product' ? 'Travel product' : 'Physical good'}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-xs capitalize text-muted-foreground">
                                            {item.unit}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            <AmountDisplay amount={item.unitCost} />
                                        </TableCell>
                                        <TableCell className="text-right text-sm tabular-nums">
                                            {item.totalQuantity}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                            {item.inventoryAccount} / {item.cogsAccount} / {item.purchaseAccount}
                                        </TableCell>
                                        <TableCell>
                                            {item.isActive ? (
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
                            <DialogTitle>New Inventory Item</DialogTitle>
                            <DialogDescription>Add an item to the catalogue.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="item-code">Code</Label>
                                    <Input
                                        id="item-code"
                                        value={form.data.code}
                                        onChange={(e) => form.setData('code', e.target.value)}
                                        placeholder="ITEM-001"
                                    />
                                    {form.errors.code && (
                                        <p className="text-xs text-destructive">{form.errors.code}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="item-category">Category</Label>
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(value) => form.setData('category', value)}
                                    >
                                        <SelectTrigger id="item-category" className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="physical_good">Physical good</SelectItem>
                                            <SelectItem value="travel_product">Travel product</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="item-name">Name</Label>
                                <Input
                                    id="item-name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                                {form.errors.name && <p className="text-xs text-destructive">{form.errors.name}</p>}
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="item-unit">Unit</Label>
                                    <Input
                                        id="item-unit"
                                        value={form.data.unit}
                                        onChange={(e) => form.setData('unit', e.target.value)}
                                        placeholder="piece / booklet / box"
                                    />
                                    {form.errors.unit && (
                                        <p className="text-xs text-destructive">{form.errors.unit}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="item-cost">Standard Unit Cost</Label>
                                    <Input
                                        id="item-cost"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        value={form.data.unit_cost}
                                        onChange={(e) => form.setData('unit_cost', e.target.value)}
                                    />
                                    {form.errors.unit_cost && (
                                        <p className="text-xs text-destructive">{form.errors.unit_cost}</p>
                                    )}
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="item-description">Description (optional)</Label>
                                <Input
                                    id="item-description"
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    Create Item
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </AccountingLayout>
        </TenantLayout>
    );
}
