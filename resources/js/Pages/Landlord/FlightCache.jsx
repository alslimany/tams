import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';

export default function FlightCache({ flightCacheSettings, flightCacheSummary }) {
    const { data, setData, patch, processing } = useForm({
        route_availability_enabled: Boolean(flightCacheSettings?.route_availability_enabled ?? true),
        schedule_cache_enabled: Boolean(flightCacheSettings?.schedule_cache_enabled ?? true),
    });

    const overviewCards = [
        { label: 'Providers with route data', value: flightCacheSummary.overview.providers_with_route_data },
        { label: 'Providers with schedule data', value: flightCacheSummary.overview.providers_with_schedule_data },
        { label: 'Learned routes', value: flightCacheSummary.overview.learned_routes },
        { label: 'Available routes', value: flightCacheSummary.overview.available_routes },
        { label: 'Inactive routes', value: flightCacheSummary.overview.inactive_routes },
        { label: 'Cached fare entries', value: flightCacheSummary.overview.cached_fare_entries },
    ];

    return (
        <LandlordLayout>
            <Head title="Flight Cache" />

            <div className="mx-auto max-w-7xl space-y-8 p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Flight Cache</h1>
                        <p className="text-muted-foreground">
                            Control global route learning and inspect which providers have learned routes and cached schedules.
                        </p>
                    </div>

                    <Link href={route('landlord.dashboard')} className="text-sm font-medium text-primary">
                        Back to dashboard
                    </Link>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {overviewCards.map((card) => (
                        <Card key={card.label}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">{card.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-3xl font-bold">{card.value}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader className="space-y-2">
                        <CardTitle>Cache Controls</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Enable or disable route discovery and schedule caching without touching tenant configuration.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                patch(route('landlord.settings.flight-cache.update'));
                            }}
                        >
                            <label className="flex items-center justify-between gap-4 rounded-lg border p-4">
                                <div className="space-y-1">
                                    <p className="font-medium">Route Availability Cache</p>
                                    <p className="text-sm text-muted-foreground">
                                        Learns provider routes that repeatedly return no flights and helps skip empty searches.
                                    </p>
                                </div>
                                <input
                                    type="checkbox"
                                    checked={data.route_availability_enabled}
                                    onChange={(event) => setData('route_availability_enabled', event.target.checked)}
                                    className="h-4 w-4"
                                />
                            </label>

                            <label className="flex items-center justify-between gap-4 rounded-lg border p-4">
                                <div className="space-y-1">
                                    <p className="font-medium">Schedule Price Cache</p>
                                    <p className="text-sm text-muted-foreground">
                                        Stores cached fare rows per provider route and date for calendar hints and prefetching.
                                    </p>
                                </div>
                                <input
                                    type="checkbox"
                                    checked={data.schedule_cache_enabled}
                                    onChange={(event) => setData('schedule_cache_enabled', event.target.checked)}
                                    className="h-4 w-4"
                                />
                            </label>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save Cache Settings'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="space-y-2">
                        <CardTitle>Cache Window</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Current cached schedule coverage across all providers.
                        </p>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-3 text-sm text-muted-foreground">
                        <Badge variant="outline">First cached date: {flightCacheSummary.overview.first_cached_date ?? 'N/A'}</Badge>
                        <Badge variant="outline">Last cached date: {flightCacheSummary.overview.last_cached_date ?? 'N/A'}</Badge>
                        <Badge variant="outline">Cached provider-routes: {flightCacheSummary.overview.cached_provider_routes}</Badge>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="space-y-2">
                        <CardTitle>Provider Summary</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            See which providers have learned routes, which routes are still available, and how much schedule data is cached for each provider.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {flightCacheSummary.providers.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                No global cache data has been learned yet. Run searches or the schedule prefetch command to populate this summary.
                            </div>
                        ) : flightCacheSummary.providers.map((provider) => (
                            <div key={provider.airline_code} className="space-y-4 rounded-xl border p-5">
                                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <h2 className="text-lg font-semibold">{provider.airline_code}</h2>
                                        <p className="text-sm text-muted-foreground">
                                            {provider.available_route_count} available routes, {provider.cached_fare_entry_count} cached fare rows.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">Learned: {provider.learned_route_count}</Badge>
                                        <Badge variant="outline">Inactive: {provider.inactive_route_count}</Badge>
                                        <Badge variant="outline">Cached routes: {provider.cached_provider_route_count}</Badge>
                                    </div>
                                </div>

                                <div className="grid gap-3 md:grid-cols-3">
                                    <div className="rounded-lg bg-muted/30 p-4">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Available Routes</p>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {provider.available_routes.length === 0 ? (
                                                <span className="text-sm text-muted-foreground">No active routes learned yet.</span>
                                            ) : provider.available_routes.map((routeEntry) => (
                                                <Badge key={routeEntry.route} variant="success">{routeEntry.route}</Badge>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="rounded-lg bg-muted/30 p-4">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Inactive Routes</p>
                                        <div className="mt-3 space-y-2">
                                            {provider.inactive_routes.length === 0 ? (
                                                <span className="text-sm text-muted-foreground">No inactive routes recorded.</span>
                                            ) : provider.inactive_routes.map((routeEntry) => (
                                                <div key={routeEntry.route} className="rounded-md border bg-background p-3 text-sm">
                                                    <div className="font-medium">{routeEntry.route}</div>
                                                    <div className="text-muted-foreground">
                                                        Empty streak: {routeEntry.consecutive_empty}
                                                        {routeEntry.last_checked_at ? ` • Last checked ${routeEntry.last_checked_at}` : ''}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="rounded-lg bg-muted/30 p-4">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Cached Schedule Routes</p>
                                        <div className="mt-3 space-y-2">
                                            {provider.cached_routes.length === 0 ? (
                                                <span className="text-sm text-muted-foreground">No cached schedule rows yet.</span>
                                            ) : provider.cached_routes.map((routeEntry) => (
                                                <div key={routeEntry.route} className="rounded-md border bg-background p-3 text-sm">
                                                    <div className="font-medium">{routeEntry.route}</div>
                                                    <div className="text-muted-foreground">
                                                        {routeEntry.cached_entries} rows
                                                        {routeEntry.first_cached_date ? ` • ${routeEntry.first_cached_date}` : ''}
                                                        {routeEntry.last_cached_date && routeEntry.last_cached_date !== routeEntry.first_cached_date ? ` to ${routeEntry.last_cached_date}` : ''}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </LandlordLayout>
    );
}