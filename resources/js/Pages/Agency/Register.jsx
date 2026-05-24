import { Head, useForm } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { useTranslation } from '@/hooks/useTranslation';

export default function Register({ centralDomain, airports }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        company_name: '',
        owner_name: '',
        phone: '',
        email: '',
        city_iata: '',
        password: '',
        password_confirmation: '',
        commercial_register: null,
        passport: null,
    });

    const [airportSearch, setAirportSearch] = useState('');

    const filteredAirports = useMemo(() => {
        const q = airportSearch.toLowerCase();
        if (!q) return airports;
        return airports.filter(
            (a) =>
                a.city.toLowerCase().includes(q) ||
                a.country.toLowerCase().includes(q) ||
                a.iata_code.toLowerCase().includes(q),
        );
    }, [airports, airportSearch]);

    const selectedAirport = airports.find((a) => a.iata_code === data.city_iata);

    const submit = (e) => {
        e.preventDefault();
        post('/register-agency');
    };

    return (
        <AuthSplitLayout
            brandHref={route('agency.register')}
            title={t('landlord.auth.register_title')}
            description={t('landlord.auth.register_description')}
            footer={
                <p className="text-center text-sm text-muted-foreground text-pretty">
                    {t('landlord.auth.already_have_workspace')}
                </p>
            }
        >
            <Head title={t('landlord.auth.register_head_title')} />

            <form onSubmit={submit} className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="company_name">{t('landlord.auth.company_name')}</Label>
                        <Input
                            id="company_name"
                            type="text"
                            value={data.company_name}
                            onChange={(e) => setData('company_name', e.target.value)}
                            autoFocus
                            required
                        />
                        {errors.company_name && <p className="text-sm text-destructive">{errors.company_name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="owner_name">{t('landlord.auth.owner_name')}</Label>
                        <Input
                            id="owner_name"
                            type="text"
                            value={data.owner_name}
                            onChange={(e) => setData('owner_name', e.target.value)}
                        />
                        {errors.owner_name && <p className="text-sm text-destructive">{errors.owner_name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="phone">{t('common.phone')}</Label>
                        <Input
                            id="phone"
                            type="text"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                        />
                        {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="email">{t('common.email')}</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            autoComplete="username"
                            required
                        />
                        {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                    </div>

                    {/* City / Office Location */}
                    <div className="space-y-2 sm:col-span-2">
                        <Label>{t('landlord.auth.agency_city')}</Label>
                        <Input
                            type="text"
                            placeholder={t('landlord.auth.agency_city_search_placeholder')}
                            value={airportSearch}
                            onChange={(e) => {
                                setAirportSearch(e.target.value);
                                setData('city_iata', '');
                            }}
                        />
                        {airportSearch && !selectedAirport && (
                            <div className="max-h-48 overflow-y-auto rounded-md border bg-popover shadow-md">
                                {filteredAirports.length === 0 ? (
                                    <p className="px-3 py-2 text-sm text-muted-foreground">{t('common.no_results')}</p>
                                ) : (
                                    filteredAirports.slice(0, 50).map((airport) => (
                                        <button
                                            key={airport.iata_code}
                                            type="button"
                                            className="flex w-full flex-col px-3 py-2 text-left hover:bg-accent"
                                            onClick={() => {
                                                setData('city_iata', airport.iata_code);
                                                setAirportSearch(airport.city);
                                            }}
                                        >
                                            <span className="font-medium">{airport.city}</span>
                                            <span className="text-xs text-muted-foreground">{airport.country} · {airport.iata_code}</span>
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                        {selectedAirport && (
                            <p className="text-xs text-muted-foreground">
                                {selectedAirport.city}, {selectedAirport.country} ({selectedAirport.iata_code})
                            </p>
                        )}
                        {errors.city_iata && <p className="text-sm text-destructive">{errors.city_iata}</p>}
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="commercial_register">{t('landlord.auth.commercial_register')}</Label>
                        <Input
                            id="commercial_register"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={(e) => setData('commercial_register', e.target.files[0])}
                            required
                        />
                        <p className="text-xs text-muted-foreground">{t('landlord.auth.commercial_register_hint')}</p>
                        {errors.commercial_register && <p className="text-sm text-destructive">{errors.commercial_register}</p>}
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="passport">{t('landlord.auth.passport')}</Label>
                        <Input
                            id="passport"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={(e) => setData('passport', e.target.files[0])}
                            required
                        />
                        <p className="text-xs text-muted-foreground">{t('landlord.auth.passport_hint')}</p>
                        {errors.passport && <p className="text-sm text-destructive">{errors.passport}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password">{t('common.password')}</Label>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password_confirmation">{t('common.confirm_password')}</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        {errors.password_confirmation && <p className="text-sm text-destructive">{errors.password_confirmation}</p>}
                    </div>
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing ? t('landlord.auth.registering') : t('landlord.auth.register_agency')}
                </Button>
            </form>
        </AuthSplitLayout>
    );
}
