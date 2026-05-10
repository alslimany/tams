import React from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Badge } from '@/Components/ui/Badge';
import { CalendarIcon, ArrowRightLeft, Shield, Tag, Users, Minus, Plus, ChevronDown } from 'lucide-react';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Calendar } from '@/Components/ui/calendar';
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
    const normalizedValue = item?.value
        ?? item?.Value
        ?? item?.Id
        ?? item?.ID
        ?? item?.id
        ?? item?.raw?.Value
        ?? item?.raw?.Id
        ?? item?.raw?.ID
        ?? item?.raw?.value
        ?? item?.raw?.id
        ?? '';

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
            zone_id: '',
            policy_date_from: '',
            policy_date_to: '',
            adult_count: '1',
            child_count: '0',
            senior_count: '0',
        },
        orange: {
            country: '',
            document_type_id: '',
            policy_date_from: '',
            policy_date_to: '',
        },
    };
}

export default function InsuranceSearch({ productTypes = [], lookupsByType = {}, activeProvider, providers = [] }) {
    const [selectedType, setSelectedType] = React.useState('compulsory');
    const [fieldsByType, setFieldsByType] = React.useState(defaultFields);
    const [compulsoryQuote, setCompulsoryQuote] = React.useState(null);
    const [compulsoryQuoteError, setCompulsoryQuoteError] = React.useState('');
    const [travelQuote, setTravelQuote] = React.useState(null);
    const [travelQuoteError, setTravelQuoteError] = React.useState('');
    const [travelPricingLoading, setTravelPricingLoading] = React.useState(false);
    const [travelReferences, setTravelReferences] = React.useState({ zones: [] });
    const [travelReferencesLoaded, setTravelReferencesLoaded] = React.useState(false);
    const [isTravelPaxDropdownOpen, setIsTravelPaxDropdownOpen] = React.useState(false);
    const [travelVisibleMonth, setTravelVisibleMonth] = React.useState(new Date());
    const [orangeQuote, setOrangeQuote] = React.useState(null);
    const [orangeQuoteError, setOrangeQuoteError] = React.useState('');
    const [orangePricingLoading, setOrangePricingLoading] = React.useState(false);
    const [orangeReferences, setOrangeReferences] = React.useState({ countries: [], documentTypes: [] });
    const [orangeReferencesLoaded, setOrangeReferencesLoaded] = React.useState(false);
    const [orangeVisibleMonth, setOrangeVisibleMonth] = React.useState(new Date());
    const travelPaxDropdownRef = React.useRef(null);
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

        if (selectedType !== 'travel') {
            setTravelQuote(null);
            setTravelQuoteError('');
            setIsTravelPaxDropdownOpen(false);
        }

        if (selectedType !== 'orange') {
            setOrangeQuote(null);
            setOrangeQuoteError('');
        }
    }, [selectedType]);

    React.useEffect(() => {
        const handleClickOutside = (event) => {
            if (travelPaxDropdownRef.current && !travelPaxDropdownRef.current.contains(event.target)) {
                setIsTravelPaxDropdownOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);

        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

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

    React.useEffect(() => {
        if (selectedType !== 'travel' || travelReferencesLoaded) {
            return;
        }

        let active = true;

        const loadTravelReferences = async () => {
            try {
                const response = await fetch(route('insurance.travel.references'), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                const payload = await response.json();

                if (!active) {
                    return;
                }

                if (!response.ok) {
                    setTravelQuoteError(payload?.message || 'Unable to load travel references.');
                    return;
                }

                setTravelReferences({
                    zones: Array.isArray(payload?.zones) ? payload.zones : [],
                });
                setTravelReferencesLoaded(true);
            } catch {
                if (!active) {
                    return;
                }

                setTravelQuoteError('Unable to load travel references.');
            }
        };

        loadTravelReferences();

        return () => {
            active = false;
        };
    }, [selectedType, travelReferencesLoaded]);

    React.useEffect(() => {
        if (selectedType !== 'orange' || orangeReferencesLoaded) {
            return;
        }

        let active = true;

        const loadOrangeReferences = async () => {
            try {
                const response = await fetch(route('insurance.orange.references'), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                const payload = await response.json();

                if (!active) {
                    return;
                }

                if (!response.ok) {
                    setOrangeQuoteError(payload?.message || 'Unable to load orange insurance references.');
                    return;
                }

                setOrangeReferences({
                    countries: Array.isArray(payload?.countries) ? payload.countries : [],
                    documentTypes: Array.isArray(payload?.documentTypes) ? payload.documentTypes : [],
                });
                setOrangeReferencesLoaded(true);
            } catch {
                if (!active) {
                    return;
                }

                setOrangeQuoteError('Unable to load orange insurance references.');
            }
        };

        loadOrangeReferences();

        return () => {
            active = false;
        };
    }, [selectedType, orangeReferencesLoaded]);

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

        if (type === 'travel') {
            setTravelQuote(null);
            setTravelQuoteError('');
        }

        if (type === 'orange') {
            setOrangeQuote(null);
            setOrangeQuoteError('');
        }

        setFieldsByType((current) => ({
            ...current,
            [type]: {
                ...current[type],
                [key]: value,
            },
        }));
    };

    const updateTravelPax = (type, delta) => {
        const currentValue = Number(fieldsByType.travel[type] || 0);
        const totalPax = Number(fieldsByType.travel.adult_count || 0)
            + Number(fieldsByType.travel.child_count || 0)
            + Number(fieldsByType.travel.senior_count || 0);
        const nextValue = Math.max(type === 'adult_count' ? 1 : 0, Math.min(9, currentValue + delta));

        if (delta > 0 && totalPax >= 9) {
            return;
        }

        setFieldValue('travel', type, String(nextValue));
    };

    const applyTravelDateRange = (range) => {
        if (!range) {
            return;
        }

        setFieldValue('travel', 'policy_date_from', range.from ? format(range.from, 'yyyy-MM-dd') : '');
        setFieldValue('travel', 'policy_date_to', range.to ? format(range.to, 'yyyy-MM-dd') : '');
    };

    const applyOrangeDateRange = (range) => {
        if (!range) {
            return;
        }

        setFieldValue('orange', 'policy_date_from', range.from ? format(range.from, 'yyyy-MM-dd') : '');
        setFieldValue('orange', 'policy_date_to', range.to ? format(range.to, 'yyyy-MM-dd') : '');
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

        if (selectedType === 'travel') {
            const travelFields = fieldsByType.travel;

            if (travelFields.zone_id === '' || travelFields.policy_date_from === '' || travelFields.policy_date_to === '') {
                setTravelQuoteError('Please select zone and travel dates.');

                return;
            }

            const totalPassengers = Number(travelFields.adult_count || 0)
                + Number(travelFields.child_count || 0)
                + Number(travelFields.senior_count || 0);

            if (totalPassengers <= 0) {
                setTravelQuoteError('At least one passenger is required.');

                return;
            }

            setTravelQuoteError('');
            setTravelPricingLoading(true);

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

            try {
                const response = await fetch(route('insurance.travel.price'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        zone_id: Number(travelFields.zone_id),
                        policy_date_from: travelFields.policy_date_from,
                        policy_date_to: travelFields.policy_date_to,
                        adult_count: Number(travelFields.adult_count || 0),
                        child_count: Number(travelFields.child_count || 0),
                        senior_count: Number(travelFields.senior_count || 0),
                    }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    setTravelQuote(null);
                    setTravelQuoteError(payload?.message || 'Unable to find travel insurance offer right now.');

                    return;
                }

                setTravelQuote(payload);
            } catch {
                setTravelQuote(null);
                setTravelQuoteError('Unable to find travel insurance offer right now.');
            } finally {
                setTravelPricingLoading(false);
            }

            return;
        }

        if (selectedType === 'orange') {
            const orangeFields = fieldsByType.orange;

            if (orangeFields.country === '' || orangeFields.document_type_id === '' || orangeFields.policy_date_from === '' || orangeFields.policy_date_to === '') {
                setOrangeQuoteError('Please select country, document type, and policy dates.');

                return;
            }

            setOrangeQuoteError('');
            setOrangePricingLoading(true);

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

            try {
                const response = await fetch(route('insurance.orange.price'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        country: Number(orangeFields.country),
                        document_type_id: Number(orangeFields.document_type_id),
                        policy_date_from: orangeFields.policy_date_from,
                        policy_date_to: orangeFields.policy_date_to,
                    }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    setOrangeQuote(null);
                    setOrangeQuoteError(payload?.message || 'Unable to find orange insurance offer right now.');

                    return;
                }

                setOrangeQuote(payload);
            } catch {
                setOrangeQuote(null);
                setOrangeQuoteError('Unable to find orange insurance offer right now.');
            } finally {
                setOrangePricingLoading(false);
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
    const travelTotalPassengers = Number(fieldsByType.travel.adult_count || 0)
        + Number(fieldsByType.travel.child_count || 0)
        + Number(fieldsByType.travel.senior_count || 0);
    const travelDateRange = React.useMemo(() => {
        const from = fieldsByType.travel.policy_date_from ? new Date(fieldsByType.travel.policy_date_from) : undefined;
        const to = fieldsByType.travel.policy_date_to ? new Date(fieldsByType.travel.policy_date_to) : undefined;

        return {
            from: from && !Number.isNaN(from.getTime()) ? from : undefined,
            to: to && !Number.isNaN(to.getTime()) ? to : undefined,
        };
    }, [fieldsByType.travel.policy_date_from, fieldsByType.travel.policy_date_to]);
    const orangeDateRange = React.useMemo(() => {
        const from = fieldsByType.orange.policy_date_from ? new Date(fieldsByType.orange.policy_date_from) : undefined;
        const to = fieldsByType.orange.policy_date_to ? new Date(fieldsByType.orange.policy_date_to) : undefined;

        return {
            from: from && !Number.isNaN(from.getTime()) ? from : undefined,
            to: to && !Number.isNaN(to.getTime()) ? to : undefined,
        };
    }, [fieldsByType.orange.policy_date_from, fieldsByType.orange.policy_date_to]);

    const selectedOrangeCountry = orangeReferences.countries.find((item) => compulsoryOptionValue(item) === String(fieldsByType.orange.country));
    const selectedOrangeDocumentType = orangeReferences.documentTypes.find((item) => compulsoryOptionValue(item) === String(fieldsByType.orange.document_type_id));

    const handleProductTypeSelect = (typeValue) => {
        setSelectedType(typeValue);
    };

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
                                            onClick={() => handleProductTypeSelect(type.value)}
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
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Zone</Label>
                                            <select
                                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                value={fieldsByType.travel.zone_id}
                                                onChange={(event) => setFieldValue('travel', 'zone_id', event.target.value)}
                                            >
                                                <option value="">Select zone</option>
                                                {travelReferences.zones.map((item, index) => (
                                                    <option key={`${compulsoryOptionValue(item)}-zone-${index}`} value={compulsoryOptionValue(item)}>
                                                        {compulsoryOptionLabel(item)}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>

                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Travel Dates</Label>
                                            <Popover>
                                                <PopoverTrigger asChild>
                                                    <Button variant="outline" className="w-full justify-start text-left font-normal">
                                                        <CalendarIcon className="mr-2 h-4 w-4" />
                                                        {travelDateRange.from
                                                            ? travelDateRange.to
                                                                ? `${format(travelDateRange.from, 'LLL dd, y')} - ${format(travelDateRange.to, 'LLL dd, y')}`
                                                                : format(travelDateRange.from, 'LLL dd, y')
                                                            : 'Pick date range'}
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-auto p-0" align="start">
                                                    <Calendar
                                                        mode="range"
                                                        selected={travelDateRange}
                                                        onSelect={applyTravelDateRange}
                                                        onMonthChange={setTravelVisibleMonth}
                                                        month={travelVisibleMonth}
                                                        numberOfMonths={2}
                                                        initialFocus
                                                    />
                                                </PopoverContent>
                                            </Popover>
                                        </div>

                                        <div className="relative space-y-2 md:col-span-4" ref={travelPaxDropdownRef}>
                                            <Label>Passengers</Label>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => setIsTravelPaxDropdownOpen(!isTravelPaxDropdownOpen)}
                                                className="w-full justify-between"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Users className="h-4 w-4 text-muted-foreground" />
                                                    <span>{travelTotalPassengers} passenger{travelTotalPassengers > 1 ? 's' : ''}</span>
                                                    <Badge variant="secondary" className="ml-1 text-xs font-medium">
                                                        {fieldsByType.travel.adult_count}A, {fieldsByType.travel.child_count}C, {fieldsByType.travel.senior_count}S
                                                    </Badge>
                                                </div>
                                                <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform ${isTravelPaxDropdownOpen ? 'rotate-180' : ''}`} />
                                            </Button>

                                            {isTravelPaxDropdownOpen && (
                                                <Card className="absolute left-0 top-full z-50 mt-2 w-full md:w-90">
                                                    <CardContent className="space-y-4 p-4">
                                                        <div className="flex items-center justify-between">
                                                            <div>
                                                                <p className="text-sm font-medium">Adults</p>
                                                                <p className="text-xs text-muted-foreground">18 - 75 years</p>
                                                            </div>
                                                            <div className="flex items-center gap-3">
                                                                <Button type="button" variant="outline" size="icon" onClick={() => updateTravelPax('adult_count', -1)} disabled={Number(fieldsByType.travel.adult_count) <= 1}>
                                                                    <Minus className="h-3 w-3" />
                                                                </Button>
                                                                <span className="w-4 text-center text-sm font-medium">{fieldsByType.travel.adult_count}</span>
                                                                <Button type="button" variant="outline" size="icon" onClick={() => updateTravelPax('adult_count', 1)} disabled={travelTotalPassengers >= 9}>
                                                                    <Plus className="h-3 w-3" />
                                                                </Button>
                                                            </div>
                                                        </div>

                                                        <div className="flex items-center justify-between border-t pt-3">
                                                            <div>
                                                                <p className="text-sm font-medium">Children</p>
                                                                <p className="text-xs text-muted-foreground">3 months - 17 years</p>
                                                            </div>
                                                            <div className="flex items-center gap-3">
                                                                <Button type="button" variant="outline" size="icon" onClick={() => updateTravelPax('child_count', -1)} disabled={Number(fieldsByType.travel.child_count) <= 0}>
                                                                    <Minus className="h-3 w-3" />
                                                                </Button>
                                                                <span className="w-4 text-center text-sm font-medium">{fieldsByType.travel.child_count}</span>
                                                                <Button type="button" variant="outline" size="icon" onClick={() => updateTravelPax('child_count', 1)} disabled={travelTotalPassengers >= 9}>
                                                                    <Plus className="h-3 w-3" />
                                                                </Button>
                                                            </div>
                                                        </div>

                                                        <div className="flex items-center justify-between border-t pt-3">
                                                            <div>
                                                                <p className="text-sm font-medium">Seniors</p>
                                                                <p className="text-xs text-muted-foreground">76 - 85 years</p>
                                                            </div>
                                                            <div className="flex items-center gap-3">
                                                                <Button type="button" variant="outline" size="icon" onClick={() => updateTravelPax('senior_count', -1)} disabled={Number(fieldsByType.travel.senior_count) <= 0}>
                                                                    <Minus className="h-3 w-3" />
                                                                </Button>
                                                                <span className="w-4 text-center text-sm font-medium">{fieldsByType.travel.senior_count}</span>
                                                                <Button type="button" variant="outline" size="icon" onClick={() => updateTravelPax('senior_count', 1)} disabled={travelTotalPassengers >= 9}>
                                                                    <Plus className="h-3 w-3" />
                                                                </Button>
                                                            </div>
                                                        </div>

                                                        <div className="pt-2 border-t">
                                                            <Button type="button" className="w-full" onClick={() => setIsTravelPaxDropdownOpen(false)}>
                                                                Done
                                                            </Button>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {selectedType === 'orange' && (
                                    <div className="grid gap-4 md:grid-cols-12">
                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Country</Label>
                                            <select
                                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                value={fieldsByType.orange.country}
                                                onChange={(event) => setFieldValue('orange', 'country', event.target.value)}
                                            >
                                                <option value="">Select country</option>
                                                {orangeReferences.countries.map((item, index) => (
                                                    <option key={`${compulsoryOptionValue(item)}-orange-country-${index}`} value={compulsoryOptionValue(item)}>
                                                        {compulsoryOptionLabel(item)}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>

                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Vehicle power / document type</Label>
                                            <select
                                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                value={fieldsByType.orange.document_type_id}
                                                onChange={(event) => setFieldValue('orange', 'document_type_id', event.target.value)}
                                            >
                                                <option value="">Select vehicle power</option>
                                                {orangeReferences.documentTypes.map((item, index) => (
                                                    <option key={`${compulsoryOptionValue(item)}-orange-document-${index}`} value={compulsoryOptionValue(item)}>
                                                        {compulsoryOptionLabel(item)}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>

                                        <div className="space-y-2 md:col-span-4">
                                            <Label>Policy dates</Label>
                                            <Popover>
                                                <PopoverTrigger asChild>
                                                    <Button variant="outline" className="w-full justify-start text-left font-normal">
                                                        <CalendarIcon className="mr-2 h-4 w-4" />
                                                        {orangeDateRange.from
                                                            ? orangeDateRange.to
                                                                ? `${format(orangeDateRange.from, 'LLL dd, y')} - ${format(orangeDateRange.to, 'LLL dd, y')}`
                                                                : format(orangeDateRange.from, 'LLL dd, y')
                                                            : 'Pick date range'}
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-auto p-0" align="start">
                                                    <Calendar
                                                        mode="range"
                                                        selected={orangeDateRange}
                                                        onSelect={applyOrangeDateRange}
                                                        onMonthChange={setOrangeVisibleMonth}
                                                        month={orangeVisibleMonth}
                                                        numberOfMonths={2}
                                                        initialFocus
                                                    />
                                                </PopoverContent>
                                            </Popover>
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

                                {selectedType === 'travel' && travelQuoteError !== '' && (
                                    <p className="text-sm font-medium text-red-600">{travelQuoteError}</p>
                                )}

                                {selectedType === 'orange' && orangeQuoteError !== '' && (
                                    <p className="text-sm font-medium text-red-600">{orangeQuoteError}</p>
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

                    {selectedType === 'travel' && travelQuote?.quote_token && (
                        <Card className="mx-auto mt-6 max-w-5xl border border-slate-200 bg-white/95 shadow-lg dark:bg-slate-800/95">
                            <CardContent className="flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between">
                                <div className="space-y-1">
                                    <p className="text-base font-semibold text-slate-800 dark:text-slate-100">
                                        {activeProvider?.name || 'Insurance Offer'}
                                    </p>
                                    <p className="text-sm text-slate-500 dark:text-slate-300">Travel Insurance Offer</p>
                                </div>

                                <div className="flex flex-wrap items-center gap-4 text-sm text-slate-600 dark:text-slate-300">
                                    <span>{travelQuote.zone_text || `Zone #${travelQuote.zone_id}`}</span>
                                    <span>{travelQuote.duration_text}</span>
                                    <span>
                                        {travelQuote.adult_count}A / {travelQuote.child_count}C / {travelQuote.senior_count}S
                                    </span>
                                </div>

                                <div className="flex items-center gap-4 rounded-lg bg-slate-100 px-4 py-3 dark:bg-slate-700/50">
                                    <p className="text-3xl font-bold text-sky-700 dark:text-sky-300">
                                        {Number(travelQuote.total_premium ?? 0).toFixed(2)}
                                        <span className="ml-1 text-lg font-medium">{travelQuote.currency}</span>
                                    </p>
                                    <Button
                                        type="button"
                                        className="bg-sky-600 hover:bg-sky-700"
                                        onClick={() => router.get(route('insurance.travel.beneficiary', travelQuote.quote_token))}
                                        disabled={travelPricingLoading}
                                    >
                                        {t('common.select_policy')}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {selectedType === 'orange' && orangeQuote?.quote_token && (
                        <Card className="mx-auto mt-6 max-w-5xl border border-slate-200 bg-white/95 shadow-lg dark:bg-slate-800/95">
                            <CardContent className="flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between">
                                <div className="space-y-1">
                                    <p className="text-base font-semibold text-slate-800 dark:text-slate-100">
                                        {activeProvider?.name || 'Insurance Offer'}
                                    </p>
                                    <p className="text-sm text-slate-500 dark:text-slate-300">Orange Insurance Offer</p>
                                </div>

                                <div className="flex flex-wrap items-center gap-4 text-sm text-slate-600 dark:text-slate-300">
                                    <span>{compulsoryOptionLabel(selectedOrangeCountry)}</span>
                                    <span>{compulsoryOptionLabel(selectedOrangeDocumentType)}</span>
                                    <span>{orangeQuote.number_of_days} days</span>
                                </div>

                                <div className="flex items-center gap-4 rounded-lg bg-slate-100 px-4 py-3 dark:bg-slate-700/50">
                                    <p className="text-3xl font-bold text-sky-700 dark:text-sky-300">
                                        {Number(orangeQuote.total_premium ?? 0).toFixed(3)}
                                        <span className="ml-1 text-lg font-medium">{orangeQuote.currency}</span>
                                    </p>
                                    <Button
                                        type="button"
                                        className="bg-sky-600 hover:bg-sky-700"
                                        onClick={() => router.get(route('insurance.orange.beneficiary', orangeQuote.quote_token))}
                                        disabled={orangePricingLoading}
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
