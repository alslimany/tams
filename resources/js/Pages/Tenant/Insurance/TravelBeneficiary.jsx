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
    if (!item) {
        return '';
    }

    const candidates = [item.name, item.text, item.Text, item.raw?.Text, item.raw?.Name];

    return candidates.map((value) => String(value ?? '').trim()).find((value) => value !== '') ?? '';
}

function optionValue(item) {
    return String(item?.id ?? item?.value ?? item?.raw?.Value ?? '');
}

export default function TravelBeneficiary({ quoteToken, quote, genders = [], nationalities = [] }) {
    const { t } = useTranslation();
    const [activeStep, setActiveStep] = React.useState('details');
    const defaultPassengers = Array.isArray(quote?.passengers) ? quote.passengers : [];
    const quoteCurrency = String(quote?.currency ?? 'LYD');

    const form = useForm({
        quote_token: quoteToken,
        client_phone: '',
        client_address: '',
        client_email: '',
        passengers: defaultPassengers.map((passenger) => ({
            first_name: String(passenger?.first_name ?? ''),
            last_name: String(passenger?.last_name ?? ''),
            birth_date: String(passenger?.birth_date ?? ''),
            gender_id: String(passenger?.gender_id ?? ''),
            birth_place: String(passenger?.birth_place ?? ''),
            passport_number: String(passenger?.passport_number ?? ''),
            nationality_id: String(passenger?.nationality_id ?? ''),
        })),
    });

    const updatePassenger = (index, key, value) => {
        form.setData('passengers', form.data.passengers.map((passenger, passengerIndex) => {
            if (passengerIndex !== index) {
                return passenger;
            }

            return {
                ...passenger,
                [key]: value,
            };
        }));
    };

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            client_name: [
                String(data.passengers[0]?.first_name ?? '').trim(),
                String(data.passengers[0]?.last_name ?? '').trim(),
            ].filter(Boolean).join(' ') || 'Not provided',
            passengers: data.passengers.map((passenger) => ({
                first_name: passenger.first_name.trim(),
                last_name: passenger.last_name.trim(),
                birth_date: passenger.birth_date,
                gender_id: Number(passenger.gender_id),
                birth_place: passenger.birth_place.trim(),
                passport_number: passenger.passport_number.trim(),
                nationality_id: Number(passenger.nationality_id),
            })),
        }));

        form.post(route('insurance.travel.issue'));
    };

    const goToConfirmStep = () => {
        setActiveStep('confirm');
    };

    const backToDetailsStep = () => {
        setActiveStep('details');
    };

    const passengerBreakdownText = `${Number(quote?.adult_count ?? 0)}A / ${Number(quote?.child_count ?? 0)}C / ${Number(quote?.senior_count ?? 0)}S`;

    return (
        <TenantNavbarLayout>
            <Head title="Travel Insurance Beneficiary" />

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 px-4 py-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">
                    <div>
                        <Link href={route('insurance.search')} className="mb-4 flex items-center text-sm font-bold text-muted-foreground hover:text-primary">
                            <ChevronLeft className="mr-1 h-4 w-4" /> {t('common.back_to_insurance_search')}
                        </Link>
                        <h2 className="text-3xl font-black tracking-tight">Complete Travel Insurance Policy</h2>
                        <p className="mt-1 font-medium text-muted-foreground">Fill in beneficiary and passenger details to continue.</p>
                    </div>

                    <Tabs value={activeStep} className="w-full">
                        <TabsList className="mb-0 grid w-full grid-cols-2 rounded-2xl border bg-muted/30 p-1">
                            <TabsTrigger value="details" disabled className="rounded-xl font-bold">Insurance Details</TabsTrigger>
                            <TabsTrigger value="confirm" disabled className="rounded-xl font-bold">Confirm, Pay & Issue Ticket</TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <Card className="overflow-hidden border-2 shadow-sm">
                        <CardHeader className="border-b bg-muted/10 pb-4">
                            <CardTitle className="text-3xl font-black tracking-tight">Travel Policy Details</CardTitle>
                            <CardDescription>Coverage: {quote?.policy_date_from} to {quote?.policy_date_to} | {quote?.zone_text || `Zone #${quote?.zone_id}`} | Duration: {quote?.duration_text}</CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <form className="space-y-6 p-6" onSubmit={submit}>
                                {activeStep === 'details' && (
                                    <>
                                        <div className="rounded-md border bg-muted/30 p-3 text-sm font-medium">Passengers</div>

                                        {form.data.passengers.map((passenger, index) => (
                                            <div key={index} className="space-y-4 rounded-lg border p-4">
                                                <h4 className="font-semibold">Passenger {index + 1}</h4>

                                                <div className="grid gap-4 md:grid-cols-2">
                                                    <div className="space-y-2">
                                                        <Label>First Name</Label>
                                                        <Input value={passenger.first_name} onChange={(event) => updatePassenger(index, 'first_name', event.target.value)} />
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label>Last Name</Label>
                                                        <Input value={passenger.last_name} onChange={(event) => updatePassenger(index, 'last_name', event.target.value)} />
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label>Birth Date</Label>
                                                        <Input type="date" value={passenger.birth_date} onChange={(event) => updatePassenger(index, 'birth_date', event.target.value)} />
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label>Gender</Label>
                                                        <select
                                                            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                            value={passenger.gender_id}
                                                            onChange={(event) => updatePassenger(index, 'gender_id', event.target.value)}
                                                        >
                                                            <option value="">Select gender</option>
                                                            {genders.map((gender) => (
                                                                <option key={gender.id} value={String(gender.id)}>{gender.name}</option>
                                                            ))}
                                                        </select>
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label>Birth Place</Label>
                                                        <Input value={passenger.birth_place} onChange={(event) => updatePassenger(index, 'birth_place', event.target.value)} />
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label>Passport Number</Label>
                                                        <Input value={passenger.passport_number} onChange={(event) => updatePassenger(index, 'passport_number', event.target.value)} />
                                                    </div>

                                                    <div className="space-y-2 md:col-span-2">
                                                        <Label>Nationality</Label>
                                                        <select
                                                            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                            value={passenger.nationality_id}
                                                            onChange={(event) => updatePassenger(index, 'nationality_id', event.target.value)}
                                                        >
                                                            <option value="">Select nationality</option>
                                                            {nationalities.map((nationality, nationalityIndex) => (
                                                                <option key={`${optionValue(nationality)}-${nationalityIndex}`} value={optionValue(nationality)}>
                                                                    {optionLabel(nationality)}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}

                                        <div className="space-y-4 border-t pt-6">
                                            <div className="rounded-md border bg-muted/30 p-3 text-sm font-medium">Contact Information</div>

                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Phone</Label>
                                                    <Input value={form.data.client_phone} onChange={(event) => form.setData('client_phone', event.target.value)} />
                                                    {form.errors.client_phone ? <p className="text-xs text-red-600">{form.errors.client_phone}</p> : null}
                                                </div>

                                                <div className="space-y-2">
                                                    <Label>Email</Label>
                                                    <Input type="email" value={form.data.client_email} onChange={(event) => form.setData('client_email', event.target.value)} />
                                                    {form.errors.client_email ? <p className="text-xs text-red-600">{form.errors.client_email}</p> : null}
                                                </div>

                                                <div className="space-y-2 md:col-span-2">
                                                    <Label>Address</Label>
                                                    <Input value={form.data.client_address} onChange={(event) => form.setData('client_address', event.target.value)} />
                                                    {form.errors.client_address ? <p className="text-xs text-red-600">{form.errors.client_address}</p> : null}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mt-8 flex items-center justify-end border-t pt-8">
                                            <Button type="button" size="lg" className="rounded-full px-10 font-black shadow-md" onClick={goToConfirmStep}>
                                                Continue to Review
                                            </Button>
                                        </div>
                                    </>
                                )}

                                {activeStep === 'confirm' && (
                                    <>
                                        <div className="rounded-xl border bg-muted/10 p-5">
                                            <p className="mb-3 text-xs font-black uppercase tracking-widest text-primary">Review Details</p>
                                            <div className="grid gap-4 text-sm md:grid-cols-2">
                                                <div>
                                                    <p className="text-muted-foreground">Primary Passenger</p>
                                                    <p className="font-bold">{[form.data.passengers[0]?.first_name, form.data.passengers[0]?.last_name].filter(Boolean).join(' ') || '-'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">Phone</p>
                                                    <p className="font-bold">{form.data.client_phone || '-'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">Coverage</p>
                                                    <p className="font-bold">{quote?.policy_date_from} to {quote?.policy_date_to}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">Passengers</p>
                                                    <p className="font-bold">{passengerBreakdownText}</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mt-8 flex items-center justify-between border-t pt-8">
                                            <Button type="button" variant="ghost" className="font-bold" onClick={backToDetailsStep}>
                                                <ChevronLeft className="mr-2 h-4 w-4" /> {t('common.back')}
                                            </Button>
                                            <Button type="submit" size="lg" className="rounded-full bg-emerald-600 px-12 text-lg font-black text-white shadow-xl hover:bg-emerald-700" disabled={form.processing}>
                                                {form.processing ? 'Issuing Policies...' : 'Confirm, Pay & Issue Ticket'}
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
                                <p className="text-sm font-medium text-primary-foreground/80">Travel Insurance</p>
                            </div>
                            <CardContent className="p-0">
                                <div className="space-y-4 border-b bg-muted/10 p-6">
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Provider</span>
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-black uppercase tracking-wider text-primary">
                                            Al Baraka
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Zone</span>
                                        <span>{quote?.zone_text || `Zone #${quote?.zone_id}`}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Duration</span>
                                        <span>{quote?.duration_text}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Passengers</span>
                                        <span>{passengerBreakdownText}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Currency</span>
                                        <span>{quoteCurrency}</span>
                                    </div>
                                </div>

                                <div className="space-y-3 p-6">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">Base fare</span>
                                        <span>{Number(quote?.total_net_premium ?? 0).toFixed(2)} {quoteCurrency}</span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                        <span>Taxes</span>
                                        <span>{Number(quote?.total_tax ?? 0).toFixed(2)} {quoteCurrency}</span>
                                    </div>
                                </div>

                                <div className="flex items-end justify-between border-t bg-muted/30 p-6">
                                    <span className="font-bold text-muted-foreground">Total to pay</span>
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
