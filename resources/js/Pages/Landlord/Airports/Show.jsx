import React from 'react';
import { Head, Link } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/Tabs';
import { ArrowLeft, Edit, MapPin, Plane } from 'lucide-react';

export default function Show({ airport }) {
    return (
        <LandlordLayout>
            <Head title={`Airport - ${airport.iata_code}`} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('landlord.airports.index')}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to Airports
                        </Link>
                    </Button>
                    <div className="flex-1">
                        <div className="flex items-center gap-4">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight flex items-center gap-3">
                                    <Plane className="h-8 w-8" />
                                    {airport.name?.en || airport.iata_code}
                                </h1>
                                <p className="text-muted-foreground flex items-center gap-2">
                                    <MapPin className="h-4 w-4" />
                                    {airport.city?.en}, {airport.country?.en}
                                </p>
                            </div>
                            <Badge variant="outline" className="text-lg px-3 py-1">
                                {airport.iata_code}
                            </Badge>
                        </div>
                    </div>
                    <Button asChild>
                        <Link href={route('landlord.airports.edit', airport.id)}>
                            <Edit className="mr-2 h-4 w-4" />
                            Edit Airport
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Airport Information */}
                    <div className="lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Airport Information</CardTitle>
                                <CardDescription>
                                    Multi-language airport details
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
                                            <div>
                                                <Label className="text-sm font-medium text-muted-foreground">Airport Name</Label>
                                                <p className="text-lg font-semibold">{airport.name?.en}</p>
                                            </div>
                                            <div>
                                                <Label className="text-sm font-medium text-muted-foreground">City</Label>
                                                <p className="text-lg font-semibold">{airport.city?.en}</p>
                                            </div>
                                            <div className="md:col-span-2">
                                                <Label className="text-sm font-medium text-muted-foreground">Country</Label>
                                                <p className="text-lg font-semibold">{airport.country?.en}</p>
                                            </div>
                                        </div>
                                    </TabsContent>

                                    <TabsContent value="ar" className="space-y-4 mt-4">
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <Label className="text-sm font-medium text-muted-foreground">اسم المطار</Label>
                                                <p className="text-lg font-semibold" dir="rtl">{airport.name?.ar}</p>
                                            </div>
                                            <div>
                                                <Label className="text-sm font-medium text-muted-foreground">المدينة</Label>
                                                <p className="text-lg font-semibold" dir="rtl">{airport.city?.ar}</p>
                                            </div>
                                            <div className="md:col-span-2">
                                                <Label className="text-sm font-medium text-muted-foreground">الدولة</Label>
                                                <p className="text-lg font-semibold" dir="rtl">{airport.country?.ar}</p>
                                            </div>
                                        </div>
                                    </TabsContent>

                                    <TabsContent value="fr" className="space-y-4 mt-4">
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <Label className="text-sm font-medium text-muted-foreground">Nom de l'aéroport</Label>
                                                <p className="text-lg font-semibold">{airport.name?.fr}</p>
                                            </div>
                                            <div>
                                                <Label className="text-sm font-medium text-muted-foreground">Ville</Label>
                                                <p className="text-lg font-semibold">{airport.city?.fr}</p>
                                            </div>
                                            <div className="md:col-span-2">
                                                <Label className="text-sm font-medium text-muted-foreground">Pays</Label>
                                                <p className="text-lg font-semibold">{airport.country?.fr}</p>
                                            </div>
                                        </div>
                                    </TabsContent>
                                </Tabs>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Airport Details */}
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Airport Codes</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-muted-foreground">IATA Code</span>
                                    <Badge variant="outline">{airport.iata_code}</Badge>
                                </div>
                                {airport.icao_code && (
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-muted-foreground">ICAO Code</span>
                                        <Badge variant="secondary">{airport.icao_code}</Badge>
                                    </div>
                                )}
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-muted-foreground">Type</span>
                                    <Badge>{airport.type || 'Unknown'}</Badge>
                                </div>
                            </CardContent>
                        </Card>

                        {(airport.latitude || airport.longitude) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Location</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {airport.latitude && (
                                        <div className="flex justify-between items-center">
                                            <span className="text-sm text-muted-foreground">Latitude</span>
                                            <span className="font-mono">{airport.latitude}</span>
                                        </div>
                                    )}
                                    {airport.longitude && (
                                        <div className="flex justify-between items-center">
                                            <span className="text-sm text-muted-foreground">Longitude</span>
                                            <span className="font-mono">{airport.longitude}</span>
                                        </div>
                                    )}
                                    {airport.elevation_ft && (
                                        <div className="flex justify-between items-center">
                                            <span className="text-sm text-muted-foreground">Elevation</span>
                                            <span>{airport.elevation_ft} ft</span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle>Timestamps</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-muted-foreground">Created</span>
                                    <span className="text-sm">{new Date(airport.created_at).toLocaleDateString()}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-muted-foreground">Updated</span>
                                    <span className="text-sm">{new Date(airport.updated_at).toLocaleDateString()}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </LandlordLayout>
    );
}