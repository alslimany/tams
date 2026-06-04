import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/Tabs';
import { Label } from '@/Components/ui/Label';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Check, ChevronDown, ChevronLeft } from 'lucide-react';
import { useTranslation } from '@/hooks/useTranslation';

function optionLabel(item) {
    if (!item) {
        return '';
    }

    const candidates = [
        item.text,
        item.Text,
        item.name,
        item.Name,
        item.raw?.text,
        item.raw?.Text,
        item.raw?.name,
        item.raw?.Name,
    ];

    const resolved = candidates
        .map((candidate) => String(candidate ?? '').trim())
        .find((candidate) => candidate !== '');

    return resolved ?? '';
}

function optionValue(item) {
    return String(item?.value ?? item?.raw?.Value ?? item?.id ?? '');
}

function FilterableSelect({
    value,
    onChange,
    options,
    placeholder,
    searchPlaceholder,
    emptyMessage,
}) {
    const [open, setOpen] = React.useState(false);
    const [query, setQuery] = React.useState('');

    const selectedOption = React.useMemo(() => {
        return options.find((option) => String(option.value) === String(value));
    }, [options, value]);

    const filteredOptions = React.useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        if (normalizedQuery === '') {
            return options;
        }

        return options.filter((option) => option.label.toLowerCase().includes(normalizedQuery));
    }, [options, query]);

    const selectOption = (optionValueToSelect) => {
        onChange(String(optionValueToSelect));
        setOpen(false);
        setQuery('');
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" className="h-10 w-full justify-between border-input bg-background px-3 py-2 font-normal">
                    <span className="truncate text-left">
                        {selectedOption?.label || placeholder}
                    </span>
                    <ChevronDown className="ml-2 h-4 w-4 shrink-0 opacity-60" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[--radix-popover-trigger-width] p-2" align="start">
                <Input
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={searchPlaceholder}
                    className="mb-2"
                />

                <div className="max-h-56 overflow-auto rounded-md border">
                    {filteredOptions.length === 0 && (
                        <p className="px-3 py-2 text-sm text-muted-foreground">{emptyMessage}</p>
                    )}

                    {filteredOptions.map((option) => {
                        const isSelected = String(option.value) === String(value);

                        return (
                            <button
                                key={option.value}
                                type="button"
                                className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted"
                                onClick={() => selectOption(option.value)}
                            >
                                <span className="truncate">{option.label}</span>
                                {isSelected && <Check className="h-4 w-4 text-sky-600" />}
                            </button>
                        );
                    })}
                </div>
            </PopoverContent>
        </Popover>
    );
}

export default function CompulsoryBeneficiary({ quoteToken, quote, vehicleTypes = [], colors = [], licensingAuthorities = [] }) {
    const { t } = useTranslation();
    const [activeStep, setActiveStep] = React.useState('details');
    const currentYear = new Date().getFullYear();
    const manufactureYears = React.useMemo(() => {
        return Array.from({ length: currentYear + 2 - 1990 }, (_, index) => String(currentYear + 1 - index));
    }, [currentYear]);

    const quoteCurrency = String(quote?.currency ?? quote?.raw?.data?.Curr ?? 'LYD');

    const licensingAuthorityOptions = React.useMemo(() => {
        return licensingAuthorities.map((item) => ({
            value: optionValue(item),
            label: optionLabel(item),
        }));
    }, [licensingAuthorities]);

    const colorOptions = React.useMemo(() => {
        return colors.map((item) => ({
            value: optionValue(item),
            label: optionLabel(item),
        }));
    }, [colors]);

    const vehicleTypeOptions = React.useMemo(() => {
        return vehicleTypes.map((item) => ({
            value: optionValue(item),
            label: optionLabel(item),
        }));
    }, [vehicleTypes]);

    const manufactureYearOptions = React.useMemo(() => {
        return manufactureYears.map((year) => ({
            value: year,
            label: year,
        }));
    }, [manufactureYears]);

    const form = useForm({
        quote_token: quoteToken,
        policy_date_from: new Date().toISOString(),
        beneficiary_name: '',
        beneficiary_phone_country_code: '+218',
        beneficiary_phone_local: '',
        beneficiary_address: '',
        beneficiary_email: '',
        vehicle_type_id: '',
        vehicle_color_id: '',
        vehicle_licensing_authority_id: '',
        vehicle_manufacture_year: new Date().getFullYear().toString(),
        vehicle_chassis_number: '',
        vehicle_plate_number: '',
        vehicle_payload: quote?.payload !== null && quote?.payload !== undefined ? String(quote.payload) : '',
        vehicle_type_engine_power: '',
    });

    const selectedLicensingAuthority = React.useMemo(() => {
        return licensingAuthorityOptions.find((item) => String(item.value) === String(form.data.vehicle_licensing_authority_id));
    }, [licensingAuthorityOptions, form.data.vehicle_licensing_authority_id]);

    const selectedVehicleType = React.useMemo(() => {
        return vehicleTypeOptions.find((item) => String(item.value) === String(form.data.vehicle_type_id));
    }, [vehicleTypeOptions, form.data.vehicle_type_id]);

    const selectedColor = React.useMemo(() => {
        return colorOptions.find((item) => String(item.value) === String(form.data.vehicle_color_id));
    }, [colorOptions, form.data.vehicle_color_id]);

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => {
            const phoneNumber = `${String(data.beneficiary_phone_country_code ?? '').trim()}${String(data.beneficiary_phone_local ?? '').trim()}`;

            return {
                quote_token: data.quote_token,
                policy_date_from: data.policy_date_from,
                beneficiary_name: data.beneficiary_name,
                beneficiary_phone: phoneNumber,
                beneficiary_address: String(data.beneficiary_address ?? '').trim() || 'Not provided',
                beneficiary_email: data.beneficiary_email,
                vehicle_type_id: data.vehicle_type_id,
                vehicle_color_id: data.vehicle_color_id,
                vehicle_licensing_authority_id: data.vehicle_licensing_authority_id,
                vehicle_manufacture_year: data.vehicle_manufacture_year,
                vehicle_chassis_number: data.vehicle_chassis_number,
                vehicle_plate_number: data.vehicle_plate_number,
                vehicle_payload: data.vehicle_payload === '' ? null : Number(data.vehicle_payload),
                vehicle_type_engine_power: data.vehicle_type_engine_power === '' ? null : Number(data.vehicle_type_engine_power),
            };
        });

        form.post(route('insurance.compulsory.issue'));
    };

    const goToConfirmStep = () => {
        setActiveStep('confirm');
    };

    const backToDetailsStep = () => {
        setActiveStep('details');
    };

    return (
        <TenantNavbarLayout>
            <Head title={t('common.compassionary_beneficiary')} />

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 px-4 py-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">
                    <div>
                        <Link href={route('insurance.search')} className="mb-4 flex items-center text-sm font-bold text-muted-foreground hover:text-primary">
                            <ChevronLeft className="mr-1 h-4 w-4" /> {t('common.back_to_insurance_search')}
                        </Link>
                        <h2 className="text-3xl font-black tracking-tight">{t('common.complete_insurance_policy')}</h2>
                        <p className="mt-1 font-medium text-muted-foreground">{t('common.fill_beneficiary_vehicle_info')}</p>
                    </div>

                    <Tabs value={activeStep} className="w-full">
                        <TabsList className="mb-0 grid w-full grid-cols-2 rounded-2xl border bg-muted/30 p-1">
                            <TabsTrigger value="details" disabled className="rounded-xl font-bold">{t('common.insurance_details')}</TabsTrigger>
                            <TabsTrigger value="confirm" disabled className="rounded-xl font-bold">{t('common.confirm_pay_issue_ticket')}</TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <Card className="overflow-hidden border-2 shadow-sm">
                        <CardHeader className="border-b bg-muted/10 pb-4">
                            <CardTitle className="text-3xl font-black tracking-tight">{t('common.insurance_details')}</CardTitle>
                            <CardDescription>{t('common.vehicle_information')}</CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <form className="space-y-6 p-6" onSubmit={submit}>
                                {activeStep === 'details' && (
                                    <>
                                        <div className="rounded-md border bg-muted/30 p-3 text-sm font-medium">{t('common.owner_and_vehicle_information')}</div>

                                <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label>{t('common.vehicle_owner_name')}</Label>
                                                <Input
                                                    value={form.data.beneficiary_name}
                                                    onChange={(event) => form.setData('beneficiary_name', event.target.value)}
                                                    placeholder={t('common.full_name')}
                                                />
                                                {form.errors.beneficiary_name && <p className="text-xs text-red-600">{form.errors.beneficiary_name}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>{t('common.metal_plate_number')}</Label>
                                                <Input
                                                    value={form.data.vehicle_plate_number}
                                                    onChange={(event) => form.setData('vehicle_plate_number', event.target.value)}
                                                    placeholder={t('common.enter_metal_plate_number')}
                                                />
                                                {form.errors.vehicle_plate_number && <p className="text-xs text-red-600">{form.errors.vehicle_plate_number}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>{t('common.licensing_authority')}</Label>
                                                <FilterableSelect
                                                    value={form.data.vehicle_licensing_authority_id}
                                                    onChange={(nextValue) => form.setData('vehicle_licensing_authority_id', nextValue)}
                                                    options={licensingAuthorityOptions}
                                                    placeholder={t('common.select_licensing_authority')}
                                                    searchPlaceholder={t('common.search_licensing_authority')}
                                                    emptyMessage={t('common.no_authority_found')}
                                                />
                                                {form.errors.vehicle_licensing_authority_id && <p className="text-xs text-red-600">{form.errors.vehicle_licensing_authority_id}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>{t('common.vehicle_type')}</Label>
                                                <FilterableSelect
                                                    value={form.data.vehicle_type_id}
                                                    onChange={(nextValue) => form.setData('vehicle_type_id', nextValue)}
                                                    options={vehicleTypeOptions}
                                                    placeholder={t('common.select_vehicle_type')}
                                                    searchPlaceholder={t('common.search_vehicle_type')}
                                                    emptyMessage={t('common.no_vehicle_type_found')}
                                                />
                                                {form.errors.vehicle_type_id && <p className="text-xs text-red-600">{form.errors.vehicle_type_id}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>{t('common.color')}</Label>
                                                <FilterableSelect
                                                    value={form.data.vehicle_color_id}
                                                    onChange={(nextValue) => form.setData('vehicle_color_id', nextValue)}
                                                    options={colorOptions}
                                                    placeholder={t('common.select_color')}
                                                    searchPlaceholder={t('common.search_color')}
                                                    emptyMessage={t('common.no_color_found')}
                                                />
                                                {form.errors.vehicle_color_id && <p className="text-xs text-red-600">{form.errors.vehicle_color_id}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>{t('common.chassis_number')}</Label>
                                                <Input
                                                    value={form.data.vehicle_chassis_number}
                                                    onChange={(event) => form.setData('vehicle_chassis_number', event.target.value)}
                                                    placeholder={t('common.enter_chassis_number')}
                                                />
                                                {form.errors.vehicle_chassis_number && <p className="text-xs text-red-600">{form.errors.vehicle_chassis_number}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>{t('common.payload_optional')}</Label>
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    value={form.data.vehicle_payload}
                                                    onChange={(event) => form.setData('vehicle_payload', event.target.value)}
                                                />
                                                {form.errors.vehicle_payload && <p className="text-xs text-red-600">{form.errors.vehicle_payload}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>{t('common.manufacture_year')}</Label>
                                                <FilterableSelect
                                                    value={form.data.vehicle_manufacture_year}
                                                    onChange={(nextValue) => form.setData('vehicle_manufacture_year', nextValue)}
                                                    options={manufactureYearOptions}
                                                    placeholder={t('common.select_manufacture_year')}
                                                    searchPlaceholder={t('common.search_year')}
                                                    emptyMessage={t('common.no_year_found')}
                                                />
                                                {form.errors.vehicle_manufacture_year && <p className="text-xs text-red-600">{form.errors.vehicle_manufacture_year}</p>}
                                            </div>
                                </div>

                                <div className="space-y-4 border-t pt-6">
                                    <h3 className="text-2xl font-black tracking-tight">{t('common.booking_detail_sent_to')}</h3>
                                    <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>{t('common.mobile_number')}</Label>
                                                    <div className="flex gap-2">
                                                        <select
                                                            className="h-10 w-28 rounded-md border bg-background px-3 text-sm"
                                                            value={form.data.beneficiary_phone_country_code}
                                                            onChange={(event) => form.setData('beneficiary_phone_country_code', event.target.value)}
                                                        >
                                                            <option value="+218">+218</option>
                                                            <option value="+20">+20</option>
                                                            <option value="+216">+216</option>
                                                        </select>
                                                        <Input
                                                            value={form.data.beneficiary_phone_local}
                                                            onChange={(event) => form.setData('beneficiary_phone_local', event.target.value)}
                                                            placeholder="91xxxxxxx"
                                                        />
                                                    </div>
                                                    {form.errors.beneficiary_phone && <p className="text-xs text-red-600">{form.errors.beneficiary_phone}</p>}
                                                </div>

                                                <div className="space-y-2">
                                                    <Label>{t('common.email_optional')}</Label>
                                                    <Input
                                                        type="email"
                                                        value={form.data.beneficiary_email}
                                                        onChange={(event) => form.setData('beneficiary_email', event.target.value)}
                                                        placeholder="email@company.com"
                                                    />
                                                    {form.errors.beneficiary_email && <p className="text-xs text-red-600">{form.errors.beneficiary_email}</p>}
                                                </div>

                                                <div className="space-y-2 md:col-span-2">
                                                    <Label>{t('common.address_optional')}</Label>
                                                    <Input
                                                        value={form.data.beneficiary_address}
                                                        onChange={(event) => form.setData('beneficiary_address', event.target.value)}
                                                        placeholder="Tripoli"
                                                    />
                                                    {form.errors.beneficiary_address && <p className="text-xs text-red-600">{form.errors.beneficiary_address}</p>}
                                                </div>
                                    </div>
                                </div>

                                <div className="mt-8 flex items-center justify-end border-t pt-8">
                                    <Button type="button" size="lg" className="rounded-full px-10 font-black shadow-md" onClick={goToConfirmStep}>
                                        {t('common.continue_to_review')}
                                    </Button>
                                </div>
                                    </>
                                )}

                                {activeStep === 'confirm' && (
                                    <>
                                        <div className="rounded-xl border bg-muted/10 p-5">
                                            <p className="mb-3 text-xs font-black uppercase tracking-widest text-primary">{t('common.passenger_details')}</p>
                                            <div className="grid gap-4 text-sm md:grid-cols-2">
                                                <div>
                                                    <p className="text-muted-foreground">{t('common.vehicle_owner_name')}</p>
                                                    <p className="font-bold">{form.data.beneficiary_name || '-'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t('common.mobile_number')}</p>
                                                    <p className="font-bold">{`${form.data.beneficiary_phone_country_code}${form.data.beneficiary_phone_local}` || '-'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t('common.vehicle_type')}</p>
                                                    <p className="font-bold">{selectedVehicleType?.label || '-'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t('common.color')}</p>
                                                    <p className="font-bold">{selectedColor?.label || '-'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t('common.licensing_authority')}</p>
                                                    <p className="font-bold">{selectedLicensingAuthority?.label || '-'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t('common.metal_plate_number')}</p>
                                                    <p className="font-bold">{form.data.vehicle_plate_number || '-'}</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mt-8 flex items-center justify-between border-t pt-8">
                                            <Button type="button" variant="ghost" className="font-bold" onClick={backToDetailsStep}>
                                                <ChevronLeft className="mr-2 h-4 w-4" /> {t('common.back')}
                                            </Button>
                                            <Button type="submit" size="lg" className="rounded-full bg-emerald-600 px-12 text-lg font-black text-white shadow-xl hover:bg-emerald-700" disabled={form.processing}>
                                                {form.processing ? t('common.issuing_policy') : t('common.confirm_pay_issue_ticket')}
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <div className="hidden lg:block">
                    <div className="sticky top-8">
                        <Card className="overflow-hidden border-2 shadow-lg">
                            <div className="bg-primary p-6 text-primary-foreground">
                                <h3 className="mb-1 text-xl font-black">{t('common.offer_summary')}</h3>
                                <p className="text-sm font-medium text-primary-foreground/80">
                                    {t('common.compassionary_insurance')}
                                </p>
                            </div>
                            <CardContent className="p-0">
                                <div className="space-y-4 border-b bg-muted/10 p-6">
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t('common.provider')}</span>
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-black uppercase tracking-wider text-primary">
                                            Al Baraka
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t('common.policy_type')}</span>
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-black uppercase tracking-wider text-primary">
                                            {t('common.compassionary')}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t('common.currency')}</span>
                                        <span>{quoteCurrency}</span>
                                    </div>
                                </div>

                                <div className="space-y-3 p-6">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t('common.base_fare')}</span>
                                        <span>{Number(quote?.net_premium ?? 0).toFixed(2)} {quoteCurrency}</span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                        <span>{t('common.taxes')}</span>
                                        <span>{Number(quote?.tax_amount ?? 0).toFixed(2)} {quoteCurrency}</span>
                                    </div>
                                </div>

                                <div className="flex items-end justify-between border-t bg-muted/30 p-6">
                                    <span className="font-bold text-muted-foreground">{t('common.total_to_pay')}</span>
                                    <div className="text-right">
                                        <p className="text-3xl font-black tracking-tight text-primary">
                                            {Number(quote?.total_premium ?? 0).toFixed(2)}
                                        </p>
                                        <p className="text-xs font-black uppercase tracking-widest text-muted-foreground">{quoteCurrency}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </TenantNavbarLayout>
    );
}
