import React from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Badge } from '@/Components/ui/Badge';
import { ArrowRightLeft, Shield, Tag } from 'lucide-react';
import { useTranslation } from '@/hooks/useTranslation';

function compulsoryOptionLabel(item) {
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

function compulsoryOptionValue(item) {
    const normalizedValue = item?.value ?? item?.raw?.Value ?? item?.id ?? '';

    return String(normalizedValue);
}

function defaultFields() {
    return {
        compulsory: {
            DocumentTypeId: '',
            InsuranceDurationId: '',
            NoPassengers: '1',
            Payload: '',
            IsPolicyPaid: true,
        },
        travel: {
            ClientProfileId: '',
            ClientProfilePaxeId: '',
            ZoneID: '',
            InsuranceDurationID: '',
            PolicyDateFrom: '',
            IsPolicyPaid: true,
        },
        orange: {
            Name: '',
            Address: '',
            ChassisNumber: '',
            MetalPlateNo: '',
            ManufactureYear: '',
            CarID: '',
            Nationality: '',
            Country: '',
            NumberOfDays: '',
            DocumentTypeID: '',
            PolicyDateFrom: '',
            IsPolicyPaid: true,
        },
    };
}

export default function InsuranceSearch({ productTypes = [], lookupsByType = {}, activeProvider, providers = [] }) {
    const [selectedType, setSelectedType] = React.useState('compulsory');
    const [fieldsByType, setFieldsByType] = React.useState(defaultFields);
    const [compulsoryQuote, setCompulsoryQuote] = React.useState(null);
    const [compulsoryQuoteError, setCompulsoryQuoteError] = React.useState('');
    const { t } = useTranslation();
    const [compulsoryReferences, setCompulsoryReferences] = React.useState({
        durations: [],
        documentTypes: [],
    });

    const quoteForm = useForm({ product_type: 'compulsory', payload: {} });

    const reportForm = useForm({
        product_type: 'compulsory',
        reference: '',
    });

    const cancelForm = useForm({
        product_type: 'compulsory',
        insurance_policy_id: '',
        remarks: '',
    });

    React.useEffect(() => {
        reportForm.setData('product_type', selectedType);
        cancelForm.setData('product_type', selectedType);

        if (selectedType !== 'compulsory') {
            setCompulsoryQuote(null);
            setCompulsoryQuoteError('');
        }
    }, [selectedType]);

    React.useEffect(() => {
        if (selectedType !== 'compulsory') {
            return;
        }

        let active = true;

        const loadCompulsoryReferences = async () => {
            try {
                const [durationsResponse, documentTypesResponse] = await Promise.all([
                    fetch(route('insurance.compulsory.references.durations'), { headers: { Accept: 'application/json' } }),
                    fetch(route('insurance.compulsory.references.document-types'), { headers: { Accept: 'application/json' } }),
                ]);

                const [durationsPayload, documentTypesPayload] = await Promise.all([
                    durationsResponse.json(),
                    documentTypesResponse.json(),
                ]);

                if (!active) {
                    return;
                }

                setCompulsoryReferences({
                    durations: Array.isArray(durationsPayload?.data) ? durationsPayload.data : [],
                    documentTypes: Array.isArray(documentTypesPayload?.data) ? documentTypesPayload.data : [],
                });
            } catch {
                if (!active) {
                    return;
                }

                setCompulsoryReferences({ durations: [], documentTypes: [] });
            }
        };

        loadCompulsoryReferences();

        return () => {
            active = false;
        };
    }, [selectedType]);

    const groupedCompulsoryDocumentTypes = React.useMemo(() => {
        const groups = new Map();
        const ungrouped = [];

        compulsoryReferences.documentTypes.forEach((item) => {
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
    }, [compulsoryReferences.documentTypes]);

    const setFieldValue = (type, key, value) => {
        if (type === 'compulsory') {
            setCompulsoryQuote(null);
            setCompulsoryQuoteError('');
        }

        setFieldsByType((current) => ({
            ...current,
            [type]: {
                ...current[type],
                [key]: value,
            },
        }));
    };

    const buildPayload = () => {
        const source = fieldsByType[selectedType] || {};
        const payload = {};

        Object.entries(source).forEach(([key, value]) => {
            if (value === '' || value === null || value === undefined) {
                return;
            }

            if (['IsPolicyPaid'].includes(key)) {
                payload[key] = Boolean(value);
                return;
            }

            if (['Name', 'Address', 'ChassisNumber', 'MetalPlateNo'].includes(key)) {
                payload[key] = String(value);
                return;
            }

            if (['PolicyDateFrom', 'ManufactureYear'].includes(key)) {
                payload[key] = String(value);
                return;
            }

            payload[key] = Number(value);
        });

        return payload;
    };

    const submitQuote = async (event) => {
        event.preventDefault();

        if (selectedType === 'compulsory') {
            const compulsoryFields = fieldsByType.compulsory;

            if (compulsoryFields.DocumentTypeId === '' || compulsoryFields.InsuranceDurationId === '') {
                setCompulsoryQuoteError('Please select document type and insurance duration.');

                return;
            }

            setCompulsoryQuoteError('');

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

            try {
                const response = await fetch(route('insurance.compulsory.price'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        document_type_id: Number(compulsoryFields.DocumentTypeId),
                        duration_id: Number(compulsoryFields.InsuranceDurationId),
                        seats: Number(compulsoryFields.NoPassengers || 1),
                        payload: compulsoryFields.Payload === '' ? null : Number(compulsoryFields.Payload),
                    }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    setCompulsoryQuote(null);
                    setCompulsoryQuoteError(payload?.message || 'Unable to find policy price right now.');

                    return;
                }

                setCompulsoryQuote(payload);
            } catch {
                setCompulsoryQuote(null);
                setCompulsoryQuoteError('Unable to find policy price right now.');
            }

            return;
        }

        quoteForm.setData({
            product_type: selectedType,
            payload: buildPayload(),
        });

        quoteForm.post(route('insurance.quote'));
    };

    const selectedCompulsoryDocumentType = compulsoryReferences.documentTypes.find((item) => {
        return compulsoryOptionValue(item) === String(fieldsByType.compulsory.DocumentTypeId);
    });

    const selectedCompulsoryDuration = compulsoryReferences.durations.find((item) => {
        return compulsoryOptionValue(item) === String(fieldsByType.compulsory.InsuranceDurationId);
    });

    const quoteCurrency = String(compulsoryQuote?.currency ?? compulsoryQuote?.raw?.data?.Curr ?? 'LYD');
    const quoteTotalPremium = Number(compulsoryQuote?.total_premium ?? 0);

    return (
        <TenantNavbarLayout>
            <Head title={t('common.search_title')} />

            <section className="relative min-h-162.5 bg-slate-900">
                <div
                    className="absolute inset-0 bg-cover bg-center bg-no-repeat"
                    style={{ backgroundImage: "url('/img/search-insurance-hero.png')" }}
                />
                <div className="absolute inset-0 bg-linear-to-b from-slate-900/70 via-slate-900/60 to-slate-900/90" />

                <div className="relative z-10 mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div className="mb-10 text-center">
                        <Badge variant="secondary" className="mb-4 border-sky-500/30 bg-sky-500/10 text-sky-300">
                            <Shield className="mr-1 h-3 w-3" />
                            {t('common.made_simple')}
                        </Badge>
                        <h1 className="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">{t('common.protect_journey')}</h1>
                        <p className="mx-auto mt-4 max-w-2xl text-lg text-slate-300">
                            {t('common.search_description')}
                        </p>
                    </div>

                    <Card className="mx-auto max-w-5xl overflow-visible border-0 bg-white/95 shadow-2xl backdrop-blur-md dark:bg-slate-800/95">
                        <CardHeader className="pb-4">
                            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <Tag className="h-5 w-5 text-sky-600" />
                                        {t('common.search_form')}
                                    </CardTitle>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {productTypes.map((type) => (
                                        <Button
                                            key={type.value}
                                            type="button"
                                            variant={selectedType === type.value ? 'default' : 'outline'}
                                            size="sm"
                                            onClick={() => setSelectedType(type.value)}
                                            className="gap-2"
                                        >
                                            <ArrowRightLeft className="h-4 w-4" />
                                            {type.label}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-6" onSubmit={(event) => event.preventDefault()}>
                                {selectedType === 'compulsory' && (
                                    <div className="grid gap-4 md:grid-cols-12">
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>{t('common.document_type')}</Label>
                                            <select
                                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                value={fieldsByType.compulsory.DocumentTypeId}
                                                onChange={(event) => setFieldValue('compulsory', 'DocumentTypeId', event.target.value)}
                                            >
                                                <option value="">{t('common.select_document_type')}</option>
                                                {groupedCompulsoryDocumentTypes.ungrouped.map((item, index) => (
                                                    <option key={`${compulsoryOptionValue(item)}-ungrouped-${index}`} value={compulsoryOptionValue(item)}>
                                                        {compulsoryOptionLabel(item)}
                                                    </option>
                                                ))}
                                                {groupedCompulsoryDocumentTypes.grouped.map(([group, items]) => (
                                                    <optgroup key={group} label={group}>
                                                        {items.map((item, index) => (
                                                            <option key={`${group}-${compulsoryOptionValue(item)}-${index}`} value={compulsoryOptionValue(item)}>
                                                                {compulsoryOptionLabel(item)}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>{t('common.insurance_duration')}</Label>
                                            <select
                                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                value={fieldsByType.compulsory.InsuranceDurationId}
                                                onChange={(event) => setFieldValue('compulsory', 'InsuranceDurationId', event.target.value)}
                                            >
                                                <option value="">{t('common.select_duration')}</option>
                                                {compulsoryReferences.durations.map((item, index) => (
                                                    <option key={`${compulsoryOptionValue(item)}-duration-${index}`} value={compulsoryOptionValue(item)}>
                                                        {compulsoryOptionLabel(item)}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>{t('common.number_of_passengers')}</Label>
                                            <select
                                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                value={fieldsByType.compulsory.NoPassengers}
                                                onChange={(event) => setFieldValue('compulsory', 'NoPassengers', event.target.value)}
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
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>{t('common.payload_optional')}</Label>
                                            <Input
                                                type="number"
                                                min="0"
                                                value={fieldsByType.compulsory.Payload}
                                                onChange={(event) => setFieldValue('compulsory', 'Payload', event.target.value)}
                                            />
                                        </div>
                                    </div>
                                )}

                                {selectedType === 'travel' && (
                                    <div className="grid gap-4 md:grid-cols-12">
                                        <div className="space-y-2 md:col-span-3">
                                            <Label>Client Profile ID</Label>
                                            <Input value={fieldsByType.travel.ClientProfileId} onChange={(event) => setFieldValue('travel', 'ClientProfileId', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-3">
                                            <Label>Client Profile Pax ID</Label>
                                            <Input value={fieldsByType.travel.ClientProfilePaxeId} onChange={(event) => setFieldValue('travel', 'ClientProfilePaxeId', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-3">
                                            <Label>Zone ID</Label>
                                            <Input value={fieldsByType.travel.ZoneID} onChange={(event) => setFieldValue('travel', 'ZoneID', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-3">
                                            <Label>Insurance Duration ID</Label>
                                            <Input value={fieldsByType.travel.InsuranceDurationID} onChange={(event) => setFieldValue('travel', 'InsuranceDurationID', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-6">
                                            <Label>Policy Date From</Label>
                                            <Input type="datetime-local" value={fieldsByType.travel.PolicyDateFrom} onChange={(event) => setFieldValue('travel', 'PolicyDateFrom', event.target.value)} />
                                        </div>
                                    </div>
                                )}

                                {selectedType === 'orange' && (
                                    <div className="grid gap-4 md:grid-cols-12">
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Name</Label>
                                            <Input value={fieldsByType.orange.Name} onChange={(event) => setFieldValue('orange', 'Name', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Address</Label>
                                            <Input value={fieldsByType.orange.Address} onChange={(event) => setFieldValue('orange', 'Address', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Chassis Number</Label>
                                            <Input value={fieldsByType.orange.ChassisNumber} onChange={(event) => setFieldValue('orange', 'ChassisNumber', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Metal Plate Number</Label>
                                            <Input value={fieldsByType.orange.MetalPlateNo} onChange={(event) => setFieldValue('orange', 'MetalPlateNo', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>Car ID</Label>
                                            <Input value={fieldsByType.orange.CarID} onChange={(event) => setFieldValue('orange', 'CarID', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>Nationality</Label>
                                            <Input value={fieldsByType.orange.Nationality} onChange={(event) => setFieldValue('orange', 'Nationality', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>Country</Label>
                                            <Input value={fieldsByType.orange.Country} onChange={(event) => setFieldValue('orange', 'Country', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>Document Type ID</Label>
                                            <Input value={fieldsByType.orange.DocumentTypeID} onChange={(event) => setFieldValue('orange', 'DocumentTypeID', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>Number Of Days</Label>
                                            <Input value={fieldsByType.orange.NumberOfDays} onChange={(event) => setFieldValue('orange', 'NumberOfDays', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-3">
                                            <Label>Manufacture Year</Label>
                                            <Input type="datetime-local" value={fieldsByType.orange.ManufactureYear} onChange={(event) => setFieldValue('orange', 'ManufactureYear', event.target.value)} />
                                        </div>
                                        <div className="space-y-2 md:col-span-3">
                                            <Label>Policy Date From</Label>
                                            <Input type="datetime-local" value={fieldsByType.orange.PolicyDateFrom} onChange={(event) => setFieldValue('orange', 'PolicyDateFrom', event.target.value)} />
                                        </div>
                                    </div>
                                )}

                                <div className="flex flex-wrap justify-end gap-2 border-t pt-3">
                                    <Button type="button" className="bg-sky-600 hover:bg-sky-700" onClick={submitQuote} disabled={quoteForm.processing}>
                                        {t('common.search')}
                                    </Button>
                                </div>

                                {selectedType === 'compulsory' && compulsoryQuoteError !== '' && (
                                    <p className="text-sm font-medium text-red-600">{compulsoryQuoteError}</p>
                                )}
                            </form>
                        </CardContent>
                    </Card>

                    {selectedType === 'compulsory' && compulsoryQuote?.quote_token && (
                        <Card className="mx-auto mt-6 max-w-5xl border border-slate-200 bg-white/95 shadow-lg dark:bg-slate-800/95">
                            <CardContent className="flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between">
                                <div className="space-y-1">
                                    <p className="text-base font-semibold text-slate-800 dark:text-slate-100">
                                        {activeProvider?.name || 'Insurance Offer'}
                                    </p>
                                    <p className="text-sm text-slate-500 dark:text-slate-300">{t('common.vehicle_offer_title')}</p>
                                </div>

                                <div className="flex flex-wrap items-center gap-4 text-sm text-slate-600 dark:text-slate-300">
                                    <span>{compulsoryOptionLabel(selectedCompulsoryDuration)}</span>
                                    <span>{fieldsByType.compulsory.NoPassengers}</span>
                                    <span>{compulsoryOptionLabel(selectedCompulsoryDocumentType)}</span>
                                </div>

                                <div className="flex items-center gap-4 rounded-lg bg-slate-100 px-4 py-3 dark:bg-slate-700/50">
                                    <p className="text-3xl font-bold text-sky-700 dark:text-sky-300">
                                        {quoteTotalPremium}
                                        <span className="ml-1 text-lg font-medium">{quoteCurrency}</span>
                                    </p>
                                    <Button
                                        type="button"
                                        className="bg-sky-600 hover:bg-sky-700"
                                        onClick={() => router.get(route('insurance.compulsory.beneficiary', compulsoryQuote.quote_token))}
                                    >
                                        {t('common.select_policy')}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </section>

        
        </TenantNavbarLayout>
    );
}
