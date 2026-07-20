import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import { ArrowUpFromLineIcon } from 'lucide-react';
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

export default function Deliver({ warehouses, items, stockLevels }) {
    const { t } = useTranslation();

    const form = useForm({
        warehouse_id: '',
        item_id: '',
        quantity: '',
        order_id: '',
        notes: '',
    });

    const availableStock = React.useMemo(() => {
        if (!form.data.warehouse_id || !form.data.item_id) return null;
        const row = stockLevels.find(
            (s) =>
                s.warehouseId === parseInt(form.data.warehouse_id, 10) &&
                s.itemId === parseInt(form.data.item_id, 10),
        );
        return row ?? { quantity: 0, avgUnitCost: 0 };
    }, [stockLevels, form.data.warehouse_id, form.data.item_id]);

    function handleSubmit(e) {
        e.preventDefault();
        form.post(route('accounting.inventory.deliver.store'));
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.deliver_goods')} />
            <AccountingLayout
                title={t('accounting.nav.deliver_goods')}
                subtitle="Stock out — debits cost of goods sold, credits inventory"
            >
                <Card className="max-w-2xl p-6">
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="dlv-warehouse">Warehouse</Label>
                                <Select
                                    value={form.data.warehouse_id}
                                    onValueChange={(value) => form.setData('warehouse_id', value)}
                                >
                                    <SelectTrigger id="dlv-warehouse" className="w-full">
                                        <SelectValue placeholder="Select warehouse" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {warehouses.map((warehouse) => (
                                            <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                                                {warehouse.code} — {warehouse.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.warehouse_id && (
                                    <p className="text-xs text-destructive">{form.errors.warehouse_id}</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="dlv-item">Item</Label>
                                <Select
                                    value={form.data.item_id}
                                    onValueChange={(value) => form.setData('item_id', value)}
                                >
                                    <SelectTrigger id="dlv-item" className="w-full">
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
                        </div>

                        {availableStock && (
                            <p className="text-sm text-muted-foreground">
                                Available:{' '}
                                <span className="font-medium text-foreground">{availableStock.quantity}</span>{' '}
                                at avg cost{' '}
                                <span className="font-medium text-foreground">{availableStock.avgUnitCost}</span>
                            </p>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="dlv-quantity">Quantity</Label>
                                <Input
                                    id="dlv-quantity"
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
                                <Label htmlFor="dlv-order">Order Reference (optional)</Label>
                                <Input
                                    id="dlv-order"
                                    value={form.data.order_id}
                                    onChange={(e) => form.setData('order_id', e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="dlv-notes">Notes (optional)</Label>
                            <Input
                                id="dlv-notes"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            <ArrowUpFromLineIcon className="mr-1.5 size-4" /> Deliver Goods
                        </Button>
                    </form>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
