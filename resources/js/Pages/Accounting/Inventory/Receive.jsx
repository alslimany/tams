import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import { ArrowDownToLineIcon } from 'lucide-react';
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

export default function Receive({ warehouses, items }) {
    const { t } = useTranslation();

    const form = useForm({
        warehouse_id: '',
        item_id: '',
        quantity: '',
        unit_cost: '',
        supplier: '',
        notes: '',
    });

    const totalCost =
        form.data.quantity && form.data.unit_cost
            ? (parseFloat(form.data.quantity) * parseFloat(form.data.unit_cost)).toFixed(3)
            : null;

    function handleSubmit(e) {
        e.preventDefault();
        form.post(route('accounting.inventory.receive.store'));
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.nav.receive_goods')} />
            <AccountingLayout
                title={t('accounting.nav.receive_goods')}
                subtitle="Stock in — debits inventory, credits accounts payable"
            >
                <Card className="max-w-2xl p-6">
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="rcv-warehouse">Warehouse</Label>
                                <Select
                                    value={form.data.warehouse_id}
                                    onValueChange={(value) => form.setData('warehouse_id', value)}
                                >
                                    <SelectTrigger id="rcv-warehouse" className="w-full">
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
                                <Label htmlFor="rcv-item">Item</Label>
                                <Select
                                    value={form.data.item_id}
                                    onValueChange={(value) => form.setData('item_id', value)}
                                >
                                    <SelectTrigger id="rcv-item" className="w-full">
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
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="rcv-quantity">Quantity</Label>
                                <Input
                                    id="rcv-quantity"
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
                                <Label htmlFor="rcv-cost">Unit Cost</Label>
                                <Input
                                    id="rcv-cost"
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
                            <Label htmlFor="rcv-supplier">Supplier</Label>
                            <Input
                                id="rcv-supplier"
                                value={form.data.supplier}
                                onChange={(e) => form.setData('supplier', e.target.value)}
                            />
                            {form.errors.supplier && (
                                <p className="text-xs text-destructive">{form.errors.supplier}</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rcv-notes">Notes (optional)</Label>
                            <Input
                                id="rcv-notes"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </div>
                        {totalCost && (
                            <p className="text-sm text-muted-foreground">
                                Total cost: <span className="font-medium text-foreground">{totalCost}</span>
                            </p>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            <ArrowDownToLineIcon className="mr-1.5 size-4" /> Receive Goods
                        </Button>
                    </form>
                </Card>
            </AccountingLayout>
        </TenantLayout>
    );
}
