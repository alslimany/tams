import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRightLeftIcon } from 'lucide-react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { useTranslation } from '@/hooks/useTranslation';

export default function Transfer({ warehouses, items, stockLevels }) {
    const { t } = useTranslation();

    const form = useForm({
        from_warehouse_id: '',
        to_warehouse_id: '',
        item_id: '',
        quantity: '',
        notes: '',
    });

    const availableStock = React.useMemo(() => {
        if (!form.data.from_warehouse_id || !form.data.item_id) return null;
        const row = stockLevels.find(
            (s) =>
                s.warehouseId === parseInt(form.data.from_warehouse_id, 10) &&
                s.itemId === parseInt(form.data.item_id, 10),
        );
        return row ?? { quantity: 0, avgUnitCost: 0 };
    }, [stockLevels, form.data.from_warehouse_id, form.data.item_id]);

    function handleSubmit(e) {
        e.preventDefault();
        form.post(route('accounting.inventory.transfer.store'));
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.transfer_goods')} />
            <AccountingLayout
                title={t('accounting.nav.transfer_goods')}
                subtitle="Move stock between warehouses at moving-average cost"
            >
                <Card className="max-w-2xl p-6">
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="trf-from">From Warehouse</Label>
                                <Select
                                    value={form.data.from_warehouse_id}
                                    onValueChange={(value) => form.setData('from_warehouse_id', value)}
                                >
                                    <SelectTrigger id="trf-from" className="w-full">
                                        <SelectValue placeholder="Select source" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {warehouses.map((warehouse) => (
                                            <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                                                {warehouse.code} — {warehouse.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.from_warehouse_id && (
                                    <p className="text-xs text-destructive">{form.errors.from_warehouse_id}</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="trf-to">To Warehouse</Label>
                                <Select
                                    value={form.data.to_warehouse_id}
                                    onValueChange={(value) => form.setData('to_warehouse_id', value)}
                                >
                                    <SelectTrigger id="trf-to" className="w-full">
                                        <SelectValue placeholder="Select destination" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {warehouses
                                            .filter((w) => String(w.id) !== form.data.from_warehouse_id)
                                            .map((warehouse) => (
                                                <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                                                    {warehouse.code} — {warehouse.name}
                                                </SelectItem>
                                            ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.to_warehouse_id && (
                                    <p className="text-xs text-destructive">{form.errors.to_warehouse_id}</p>
                                )}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="trf-item">Item</Label>
                            <Select
                                value={form.data.item_id}
                                onValueChange={(value) => form.setData('item_id', value)}
                            >
                                <SelectTrigger id="trf-item" className="w-full">
                                    <SelectValue placeholder="Select item" />
                                </SelectTrigger>
                                <SelectContent>
                                    {items.map((item) => (
                                        <SelectItem key={item.id} value={String(item.id)}>
                                            {item.code} — {item.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.item_id && (
                                <p className="text-xs text-destructive">{form.errors.item_id}</p>
                            )}
                        </div>

                        {availableStock && (
                            <p className="text-sm text-muted-foreground">
                                Available at source:{' '}
                                <span className="font-medium text-foreground">{availableStock.quantity}</span>{' '}
                                at avg cost{' '}
                                <span className="font-medium text-foreground">{availableStock.avgUnitCost}</span>
                            </p>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="trf-quantity">Quantity</Label>
                            <Input
                                id="trf-quantity"
                                type="number"
                                step="0.001"
                                min="0"
                                value={form.data.quantity}
                                onChange={(e) => form.setData('quantity', e.target.value)}
                            />
                            {form.errors.quantity && (
                                <p className="text-xs text-destructive">{form.errors.quantity}</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="trf-notes">Notes (optional)</Label>
                            <Input
                                id="trf-notes"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            <ArrowRightLeftIcon className="mr-1.5 size-4" /> Transfer Goods
                        </Button>
                    </form>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
