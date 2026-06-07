import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/Tabs';
import { Label } from '@/Components/ui/Label';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { ChevronLeft } from 'lucide-react';
import { useTranslation } from '@/hooks/useTranslation';

function optionLabel(item) {
    const candidates = [item?.name, item?.text, item?.Text, item?.Name, item?.raw?.Text, item?.raw?.Name];

    return candidates.map((value) => String(value ?? '').trim()).find((value) => value !== '') ?? '';
}

function optionValue(item) {
    return String(
        item?.value
            ?? item?.Value
            ?? item?.Id
            ?? item?.ID
            ?? item?.id
            ?? item?.raw?.Value
            ?? item?.raw?.Id
            ?? item?.raw?.ID
            ?? item?.raw?.value
            ?? item?.raw?.id
            ?? '',
    );
}

export default function OrangeBeneficiary({ quoteToken, quote, cars = [], vehicleNationalities = [], countries = [], documentTypes = [] }) {
    const { t } = useTranslation();
    const [activeStep, setActiveStep] = React.useState('details');
    const currentYear = new Date().getFullYear();
    const manufactureYears = React.useMemo(() => Array.from({ length: currentYear + 2 - 1950 }, (_, index) => String(currentYear + 1 - index)), [currentYear]);

    const selectedCountry = countries.find((item) => optionValue(item) === String(quote?.country));
    const selectedDocumentType = documentTypes.find((item) => optionValue(item) === String(quote?.document_type_id));

    const form = useForm({
        quote_token: quoteToken,
        name: '',
        address: '',
        phone: '',
        chassis_number: '',
        metal_plate_number: '',
        manufacture_year: String(currentYear),
        car_id: '',
        nationality: '',
    });

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            quote_token: data.quote_token,
            name: String(data.name ?? '').trim(),
            address: String(data.address ?? '').trim(),
            phone: String(data.phone ?? '').trim(),
            chassis_number: String(data.chassis_number ?? '').trim(),
            metal_plate_number: String(data.metal_plate_number ?? '').trim(),
            manufacture_year: Number(data.manufacture_year),
            car_id: Number(data.car_id),
            nationality: Number(data.nationality),
        }));

        form.post(route('insurance.orange.issue'));
    };

    return (
        <TenantNavbarLayout>
            <Head title="Orange Insurance Details" />

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 py-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">
                    <div>
                        <Link href={route('insurance.search')} className="mb-4 flex items-center text-sm font-bold text-muted-foreground hover:text-primary">
                            <ChevronLeft className="mr-1 h-4 w-4" /> {t('common.back_to_insurance_search')}
                        </Link>
                        <h2 className="text-3xl font-black tracking-tight">Complete Orange Insurance Policy</h2>
                        <p className="mt-1 font-medium text-muted-foreground">Fill owner and vehicle details. Quote fields are locked from the search step.</p>
                    </div>

                    <Tabs value={activeStep} className="w-full">
                        <TabsList className="mb-0 grid w-full grid-cols-2 rounded-2xl border bg-muted/30 p-1">
                            <TabsTrigger value="details" disabled className="rounded-xl font-bold">Policy Details</TabsTrigger>
                            <TabsTrigger value="confirm" disabled className="rounded-xl font-bold">Confirm, Pay & Issue</TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <Card className="overflow-hidden border-2 shadow-sm">
                        <CardHeader className="border-b bg-muted/10 pb-4">
                            <CardTitle className="text-3xl font-black tracking-tight">Orange Policy Details</CardTitle>
                            <CardDescription>Owner and vehicle information for the selected Orange insurance offer.</CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <form className="space-y-6 p-6" onSubmit={submit}>
                                {activeStep === 'details' && (
                                    <>
                                        <div className="rounded-md border bg-muted/30 p-3 text-sm font-medium">Owner Information</div>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2 md:col-span-2">
                                                <Label>Name</Label>
                                                <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                                                {form.errors.name ? <p className="text-xs text-red-600">{form.errors.name}</p> : null}
                                            </div>
                                        </div>

                                        <div className="rounded-md border bg-muted/30 p-3 text-sm font-medium">Vehicle Information</div>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label>Chassis Number</Label>
                                                <Input value={form.data.chassis_number} onChange={(event) => form.setData('chassis_number', event.target.value)} />
                                                {form.errors.chassis_number ? <p className="text-xs text-red-600">{form.errors.chassis_number}</p> : null}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Metal Plate Number</Label>
                                                <Input value={form.data.metal_plate_number} onChange={(event) => form.setData('metal_plate_number', event.target.value)} />
                                                {form.errors.metal_plate_number ? <p className="text-xs text-red-600">{form.errors.metal_plate_number}</p> : null}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Manufacture Year</Label>
                                                <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.data.manufacture_year} onChange={(event) => form.setData('manufacture_year', event.target.value)}>
                                                    {manufactureYears.map((year) => <option key={year} value={year}>{year}</option>)}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Car</Label>
                                                <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.data.car_id} onChange={(event) => form.setData('car_id', event.target.value)}>
                                                    <option value="">Select car</option>
                                                    {cars.map((car, index) => <option key={`${optionValue(car)}-${index}`} value={optionValue(car)}>{optionLabel(car)}</option>)}
                                                </select>
                                                {form.errors.car_id ? <p className="text-xs text-red-600">{form.errors.car_id}</p> : null}
                                            </div>
                                            <div className="space-y-2 md:col-span-2">
                                                <Label>Vehicle Nationality</Label>
                                                <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.data.nationality} onChange={(event) => form.setData('nationality', event.target.value)}>
                                                    <option value="">Select nationality</option>
                                                    {vehicleNationalities.map((nationality, index) => <option key={`${optionValue(nationality)}-${index}`} value={optionValue(nationality)}>{optionLabel(nationality)}</option>)}
                                                </select>
                                                {form.errors.nationality ? <p className="text-xs text-red-600">{form.errors.nationality}</p> : null}
                                            </div>
                                        </div>

                                        <div className="space-y-4 border-t pt-6">
                                            <div className="rounded-md border bg-muted/30 p-3 text-sm font-medium">Contact Information</div>

                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Phone</Label>
                                                    <Input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} />
                                                    {form.errors.phone ? <p className="text-xs text-red-600">{form.errors.phone}</p> : null}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Address</Label>
                                                    <Input value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} />
                                                    {form.errors.address ? <p className="text-xs text-red-600">{form.errors.address}</p> : null}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mt-8 flex items-center justify-end border-t pt-8">
                                            <Button type="button" size="lg" className="rounded-full px-10 font-black shadow-md" onClick={() => setActiveStep('confirm')}>
                                                Continue to Review
                                            </Button>
                                        </div>
                                    </>
                                )}

                                {activeStep === 'confirm' && (
                                    <>
                                        <div className="rounded-xl border bg-muted/10 p-5">
                                            <p className="mb-3 text-xs font-black uppercase tracking-widest text-primary">Locked quote</p>
                                            <div className="grid gap-4 text-sm md:grid-cols-2">
                                                <div><p className="text-muted-foreground">Country</p><p className="font-bold">{optionLabel(selectedCountry) || `#${quote?.country}`}</p></div>
                                                <div><p className="text-muted-foreground">Vehicle power</p><p className="font-bold">{optionLabel(selectedDocumentType) || `#${quote?.document_type_id}`}</p></div>
                                                <div><p className="text-muted-foreground">Coverage</p><p className="font-bold">{quote?.policy_date_from} to {quote?.policy_date_to}</p></div>
                                                <div><p className="text-muted-foreground">Total</p><p className="font-bold">{Number(quote?.total_premium ?? 0).toFixed(3)} {quote?.currency ?? 'LYD'}</p></div>
                                            </div>
                                        </div>

                                        <div className="mt-8 flex items-center justify-between border-t pt-8">
                                            <Button type="button" variant="ghost" className="font-bold" onClick={() => setActiveStep('details')}>
                                                <ChevronLeft className="mr-2 h-4 w-4" /> {t('common.back')}
                                            </Button>
                                            <Button type="submit" size="lg" className="rounded-full bg-emerald-600 px-12 text-lg font-black text-white shadow-xl hover:bg-emerald-700" disabled={form.processing}>
                                                {form.processing ? 'Issuing Policy...' : 'Confirm, Pay & Issue Policy'}
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
                                <h3 className="mb-1 text-xl font-black">Offer Summary</h3>
                                <p className="text-sm font-medium text-primary-foreground/80">Orange Insurance</p>
                            </div>
                            <CardContent className="p-0">
                                <div className="space-y-4 border-b bg-muted/10 p-6">
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Provider</span>
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-black uppercase tracking-wider text-primary">
                                            Al Baraka
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between gap-4 text-sm font-bold">
                                        <span className="text-muted-foreground">Country</span>
                                        <span className="text-right">{optionLabel(selectedCountry) || `Country #${quote?.country}`}</span>
                                    </div>
                                    <div className="flex items-center justify-between gap-4 text-sm font-bold">
                                        <span className="text-muted-foreground">Vehicle power</span>
                                        <span className="text-right">{optionLabel(selectedDocumentType) || `#${quote?.document_type_id}`}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Duration</span>
                                        <span>{quote?.number_of_days} days</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Currency</span>
                                        <span>{quote?.currency ?? 'LYD'}</span>
                                    </div>
                                </div>

                                <div className="space-y-3 p-6">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Base fare</span>
                                        <span>{Number(quote?.net_premium ?? 0).toFixed(2)} {quote?.currency ?? 'LYD'}</span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                        <span>Taxes</span>
                                        <span>{Number(quote?.tax_amount ?? 0).toFixed(2)} {quote?.currency ?? 'LYD'}</span>
                                    </div>
                                </div>

                                <div className="flex items-end justify-between border-t bg-muted/30 p-6">
                                    <span className="font-bold text-muted-foreground">Total to pay</span>
                                    <div className="text-right">
                                        <p className="text-3xl font-black tracking-tight text-primary">
                                            {Number(quote?.total_premium ?? 0).toFixed(2)}
                                        </p>
                                        <p className="text-xs font-black uppercase tracking-widest text-muted-foreground">{quote?.currency ?? 'LYD'}</p>
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
