import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useTranslation } from '@/hooks/useTranslation';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/Select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/Tabs';
import { ArrowLeft, Save, Eye, EyeOff } from 'lucide-react';

export default function Edit({ airport }) {
    const { t } = useTranslation();

    const { data, setData, put, processing, errors } = useForm({
        name: airport.name || { en: '', ar: '', fr: '' },
        city: airport.city || { en: '', ar: '', fr: '' },
        country: airport.country || { en: '', ar: '', fr: '' },
        iata_code: airport.iata_code || '',
        icao_code: airport.icao_code || '',
        latitude: airport.latitude || '',
        longitude: airport.longitude || '',
        elevation_ft: airport.elevation_ft || '',
        type: airport.type || 'large_airport',
        show_in_registration: airport.show_in_registration ?? false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('landlord.airports.update', airport.id));
    };

    const updateTranslation = (field, locale, value) => {
        setData(field, {
            ...data[field],
            [locale]: value,
        });
    };

    return (
        <LandlordLayout>
            <Head title={`Edit Airport - ${airport.iata_code}`} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('landlord.airports.index')}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to Airports
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Edit Airport</h1>
                        <p className="text-muted-foreground">
                            Update airport information with multi-language support
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    <div className="grid gap-6 lg:grid-cols-3">
                        {/* Translations */}
                        <div className="lg:col-span-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Airport Information</CardTitle>
                                    <CardDescription>
                                        Update airport details in all supported languages
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Tabs defaultValue="en" className="w-full">
                                        <TabsList className="grid w-full grid-cols-3">
                                            <TabsTrigger value="en">English</TabsTrigger>
                                            <TabsTrigger value="ar">العربية</TabsTrigger>
                                            <TabsTrigger value="fr">Français</TabsTrigger>
                                        </TabsList>

                                        <TabsContent value="en" className="space-y-4 mt-4">
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="name_en">Airport Name (EN)</Label>
                                                    <Input
                                                        id="name_en"
                                                        value={data.name.en}
                                                        onChange={(e) => updateTranslation('name', 'en', e.target.value)}
                                                        placeholder="John F. Kennedy International Airport"
                                                    />
                                                    {errors['name.en'] && <p className="text-sm text-destructive">{errors['name.en']}</p>}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="city_en">City (EN)</Label>
                                                    <Input
                                                        id="city_en"
                                                        value={data.city.en}
                                                        onChange={(e) => updateTranslation('city', 'en', e.target.value)}
                                                        placeholder="New York"
                                                    />
                                                    {errors['city.en'] && <p className="text-sm text-destructive">{errors['city.en']}</p>}
                                                </div>
                                                <div className="space-y-2 md:col-span-2">
                                                    <Label htmlFor="country_en">Country (EN)</Label>
                                                    <Input
                                                        id="country_en"
                                                        value={data.country.en}
                                                        onChange={(e) => updateTranslation('country', 'en', e.target.value)}
                                                        placeholder="United States"
                                                    />
                                                    {errors['country.en'] && <p className="text-sm text-destructive">{errors['country.en']}</p>}
                                                </div>
                                            </div>
                                        </TabsContent>

                                        <TabsContent value="ar" className="space-y-4 mt-4">
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="name_ar">اسم المطار (AR)</Label>
                                                    <Input
                                                        id="name_ar"
                                                        value={data.name.ar}
                                                        onChange={(e) => updateTranslation('name', 'ar', e.target.value)}
                                                        placeholder="مطار جون إف كينيدي الدولي"
                                                        dir="rtl"
                                                    />
                                                    {errors['name.ar'] && <p className="text-sm text-destructive">{errors['name.ar']}</p>}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="city_ar">المدينة (AR)</Label>
                                                    <Input
                                                        id="city_ar"
                                                        value={data.city.ar}
                                                        onChange={(e) => updateTranslation('city', 'ar', e.target.value)}
                                                        placeholder="نيويورك"
                                                        dir="rtl"
                                                    />
                                                    {errors['city.ar'] && <p className="text-sm text-destructive">{errors['city.ar']}</p>}
                                                </div>
                                                <div className="space-y-2 md:col-span-2">
                                                    <Label htmlFor="country_ar">الدولة (AR)</Label>
                                                    <Input
                                                        id="country_ar"
                                                        value={data.country.ar}
                                                        onChange={(e) => updateTranslation('country', 'ar', e.target.value)}
                                                        placeholder="الولايات المتحدة"
                                                        dir="rtl"
                                                    />
                                                    {errors['country.ar'] && <p className="text-sm text-destructive">{errors['country.ar']}</p>}
                                                </div>
                                            </div>
                                        </TabsContent>

                                        <TabsContent value="fr" className="space-y-4 mt-4">
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="name_fr">Nom de l'aéroport (FR)</Label>
                                                    <Input
                                                        id="name_fr"
                                                        value={data.name.fr}
                                                        onChange={(e) => updateTranslation('name', 'fr', e.target.value)}
                                                        placeholder="Aéroport international John F. Kennedy"
                                                    />
                                                    {errors['name.fr'] && <p className="text-sm text-destructive">{errors['name.fr']}</p>}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="city_fr">Ville (FR)</Label>
                                                    <Input
                                                        id="city_fr"
                                                        value={data.city.fr}
                                                        onChange={(e) => updateTranslation('city', 'fr', e.target.value)}
                                                        placeholder="New York"
                                                    />
                                                    {errors['city.fr'] && <p className="text-sm text-destructive">{errors['city.fr']}</p>}
                                                </div>
                                                <div className="space-y-2 md:col-span-2">
                                                    <Label htmlFor="country_fr">Pays (FR)</Label>
                                                    <Input
                                                        id="country_fr"
                                                        value={data.country.fr}
                                                        onChange={(e) => updateTranslation('country', 'fr', e.target.value)}
                                                        placeholder="États-Unis"
                                                    />
                                                    {errors['country.fr'] && <p className="text-sm text-destructive">{errors['country.fr']}</p>}
                                                </div>
                                            </div>
                                        </TabsContent>
                                    </Tabs>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Airport Codes & Details */}
                        <div className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Airport Codes</CardTitle>
                                    <CardDescription>
                                        IATA and ICAO codes for the airport
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="iata_code">IATA Code</Label>
                                        <Input
                                            id="iata_code"
                                            value={data.iata_code}
                                            onChange={(e) => setData('iata_code', e.target.value.toUpperCase())}
                                            placeholder="JFK"
                                            maxLength={3}
                                        />
                                        {errors.iata_code && <p className="text-sm text-destructive">{errors.iata_code}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="icao_code">ICAO Code</Label>
                                        <Input
                                            id="icao_code"
                                            value={data.icao_code}
                                            onChange={(e) => setData('icao_code', e.target.value.toUpperCase())}
                                            placeholder="KJFK"
                                            maxLength={4}
                                        />
                                        {errors.icao_code && <p className="text-sm text-destructive">{errors.icao_code}</p>}
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Location & Details</CardTitle>
                                    <CardDescription>
                                        Geographic and operational information
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="latitude">Latitude</Label>
                                            <Input
                                                id="latitude"
                                                type="number"
                                                step="0.000001"
                                                value={data.latitude}
                                                onChange={(e) => setData('latitude', e.target.value)}
                                                placeholder="40.6413"
                                            />
                                            {errors.latitude && <p className="text-sm text-destructive">{errors.latitude}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="longitude">Longitude</Label>
                                            <Input
                                                id="longitude"
                                                type="number"
                                                step="0.000001"
                                                value={data.longitude}
                                                onChange={(e) => setData('longitude', e.target.value)}
                                                placeholder="-73.7781"
                                            />
                                            {errors.longitude && <p className="text-sm text-destructive">{errors.longitude}</p>}
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="elevation_ft">Elevation (ft)</Label>
                                        <Input
                                            id="elevation_ft"
                                            type="number"
                                            value={data.elevation_ft}
                                            onChange={(e) => setData('elevation_ft', e.target.value)}
                                            placeholder="13"
                                        />
                                        {errors.elevation_ft && <p className="text-sm text-destructive">{errors.elevation_ft}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="type">Airport Type</Label>
                                        <Select value={data.type} onValueChange={(value) => setData('type', value)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="large_airport">Large Airport</SelectItem>
                                                <SelectItem value="medium_airport">Medium Airport</SelectItem>
                                                <SelectItem value="small_airport">Small Airport</SelectItem>
                                                <SelectItem value="heliport">Heliport</SelectItem>
                                                <SelectItem value="seaplane_base">Seaplane Base</SelectItem>
                                                <SelectItem value="balloonport">Balloonport</SelectItem>
                                                <SelectItem value="closed">Closed</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {errors.type && <p className="text-sm text-destructive">{errors.type}</p>}
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Registration Visibility</CardTitle>
                                    <CardDescription>
                                        Show this airport in the agency registration city picker
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <button
                                        type="button"
                                        onClick={() => setData('show_in_registration', !data.show_in_registration)}
                                        className={`inline-flex w-full items-center justify-center gap-2 rounded-lg border px-4 py-3 text-sm font-semibold transition-colors ${
                                            data.show_in_registration
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                                                : 'border-slate-200 bg-slate-50 text-slate-500 hover:bg-slate-100'
                                        }`}
                                    >
                                        {data.show_in_registration
                                            ? <><Eye className="h-4 w-4" /> Visible in Registration</>
                                            : <><EyeOff className="h-4 w-4" /> Hidden from Registration</>
                                        }
                                    </button>
                                </CardContent>
                            </Card>

                            <div className="flex justify-end gap-4">
                                <Button variant="outline" asChild>
                                    <Link href={route('landlord.airports.index')}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <Save className="mr-2 h-4 w-4" />
                                    {processing ? 'Updating...' : 'Update Airport'}
                                </Button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </LandlordLayout>
    );
}