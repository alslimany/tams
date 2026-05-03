import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Label } from '@/Components/ui/Label';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';

function optionLabel(item) {
    if (!item) {
        return '';
    }

    const labelCandidates = [
        item.text,
        item.Text,
        item.name,
        item.Name,
        item.raw?.text,
        item.raw?.Text,
        item.raw?.name,
        item.raw?.Name,
        item.raw?.Label,
        item.raw?.label,
    ];

    const label = labelCandidates
        .map((candidate) => String(candidate ?? '').trim())
        .find((candidate) => candidate !== '');

    return label ?? '';
}

function optionValue(item) {
    const normalizedValue = item?.value ?? item?.raw?.Value ?? item?.id ?? '';

    return String(normalizedValue);
}

export default function CompulsorySearch({ durations = [], documentTypes = [] }) {
    const [form, setForm] = useState({
        document_type_id: '',
        duration_id: '',
        seats: '4',
        payload: '',
    });
    const [quote, setQuote] = useState(null);
    const [error, setError] = useState('');
    const [isLoading, setIsLoading] = useState(false);

    const canSubmit = useMemo(() => {
        return form.document_type_id !== '' && form.duration_id !== '' && Number(form.seats) > 0;
    }, [form]);

    const groupedDocumentTypes = useMemo(() => {
        const groups = new Map();
        const ungrouped = [];

        documentTypes.forEach((item) => {
            const group = String(item.group ?? item.raw?.Group ?? item.raw?.group ?? '').trim();

            if (group === '') {
                ungrouped.push(item);
                return;
            }

            if (!groups.has(group)) {
                groups.set(group, []);
            }

            groups.get(group).push(item);
        });

        return {
            grouped: Array.from(groups.entries()),
            ungrouped,
        };
    }, [documentTypes]);

    const updateField = (key, value) => {
        setForm((previous) => ({
            ...previous,
            [key]: value,
        }));
    };

    const handlePriceCheck = async (event) => {
        event.preventDefault();

        if (!canSubmit || isLoading) {
            return;
        }

        setIsLoading(true);
        setError('');

        try {
            const response = await fetch(route('insurance.compulsory.price'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    ...form,
                    seats: Number(form.seats),
                    payload: form.payload === '' ? null : Number(form.payload),
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                setError(payload?.message || 'Unable to calculate price.');
                return;
            }

            setQuote(payload);
        } catch {
            setError('Unable to calculate price at the moment. Please try again.');
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <TenantLayout>
            <Head title="Compulsory Insurance Search" />

            <div className="mx-auto max-w-4xl space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Compulsory Insurance Search</CardTitle>
                        <CardDescription>
                            Select policy parameters and calculate the final premium.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form className="grid gap-4 md:grid-cols-2" onSubmit={handlePriceCheck}>
                            <div className="space-y-2">
                                <Label>Document Type</Label>
                                <select
                                    className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.document_type_id}
                                    onChange={(event) => updateField('document_type_id', event.target.value)}
                                >
                                    <option value="">Select document type</option>
                                    {groupedDocumentTypes.ungrouped.map((item, index) => (
                                        <option key={`${optionValue(item)}-ungrouped-${index}`} value={optionValue(item)}>
                                            {optionLabel(item)}
                                        </option>
                                    ))}
                                    {groupedDocumentTypes.grouped.map(([group, items]) => (
                                        <optgroup key={group} label={group}>
                                            {items.map((item, index) => (
                                                <option key={`${group}-${optionValue(item)}-${index}`} value={optionValue(item)}>
                                                    {optionLabel(item)}
                                                </option>
                                            ))}
                                        </optgroup>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Duration</Label>
                                <select
                                    className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.duration_id}
                                    onChange={(event) => updateField('duration_id', event.target.value)}
                                >
                                    <option value="">Select duration</option>
                                    {durations.map((item, index) => (
                                        <option key={`${optionValue(item)}-duration-${index}`} value={optionValue(item)}>
                                            {optionLabel(item)}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Number Of Seats</Label>
                                <select
                                    className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.seats}
                                    onChange={(event) => updateField('seats', event.target.value)}
                                >
                                    {Array.from({ length: 12 }, (_, index) => {
                                        const number = index + 1;

                                        return (
                                            <option key={number} value={number}>
                                                {number}
                                            </option>
                                        );
                                    })}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Payload (Optional)</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    value={form.payload}
                                    onChange={(event) => updateField('payload', event.target.value)}
                                />
                            </div>

                            <div className="md:col-span-2 flex justify-end">
                                <Button type="submit" disabled={!canSubmit || isLoading}>
                                    {isLoading ? 'Calculating...' : 'Check Price'}
                                </Button>
                            </div>
                        </form>

                        {error && (
                            <p className="mt-3 text-sm text-red-600">{error}</p>
                        )}
                    </CardContent>
                </Card>

                {quote && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Price Result</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm">Total Premium: <strong>{quote.total_premium} {quote.currency}</strong></p>
                            <p className="text-sm">Net Premium: <strong>{quote.net_premium} {quote.currency}</strong></p>
                            <p className="text-sm">Tax: <strong>{quote.tax_amount} {quote.currency}</strong></p>

                            <div className="flex justify-end">
                                <Button
                                    type="button"
                                    onClick={() => router.get(route('insurance.compulsory.beneficiary', quote.quote_token))}
                                >
                                    Select And Continue
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </TenantLayout>
    );
}
