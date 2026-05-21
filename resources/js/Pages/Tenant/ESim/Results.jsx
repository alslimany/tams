import React from 'react';
import { Head, router } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { Input } from '@/Components/ui/Input';
import { useTranslation } from '@/hooks/useTranslation';
import { formatMoney } from '@/lib/currency';
import {
    ArrowLeft,
    Loader2,
    SmartphoneNfc,
    SlidersHorizontal,
    X,
    Wifi,
    Clock,
    Globe,
    ChevronRight,
    AlertCircle,
} from 'lucide-react';

// ─── Price Range Slider ───────────────────────────────────────────────────────

function PriceRangeSlider({ min, max, valueMin, valueMax, onChange }) {
    const range = max - min || 1;
    const pctMin = ((valueMin - min) / range) * 100;
    const pctMax = ((valueMax - min) / range) * 100;
    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

    return (
        <div className="relative h-5 w-full select-none">
            <div className="absolute top-1/2 h-1.5 w-full -translate-y-1/2 rounded-full bg-muted" />
            <div
                className="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-violet-500"
                style={{ left: `${pctMin}%`, right: `${100 - pctMax}%` }}
            />
            <input
                type="range" min={min} max={max} step={1} value={valueMin}
                onChange={(e) => onChange(clamp(Number(e.target.value), min, valueMax - 1), valueMax)}
                className="pointer-events-none absolute inset-0 h-full w-full appearance-none bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-violet-500 [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm"
                style={{ zIndex: valueMin > max - 10 ? 5 : 3 }}
            />
            <input
                type="range" min={min} max={max} step={1} value={valueMax}
                onChange={(e) => onChange(valueMin, clamp(Number(e.target.value), valueMin + 1, max))}
                className="pointer-events-none absolute inset-0 h-full w-full appearance-none bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-violet-500 [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm"
                style={{ zIndex: 4 }}
            />
        </div>
    );
}

// ─── Package Card ─────────────────────────────────────────────────────────────

function PackageCard({ pkg, onSelect, t }) {
    const dataMb = Number(pkg.data_mb ?? 0);
    const dataLabel = dataMb >= 1024
        ? `${(dataMb / 1024).toFixed(1)} ${t('esim.results.gb_short')}`
        : `${dataMb} ${t('esim.results.mb_short')}`;

    return (
        <Card className="group relative overflow-hidden transition-shadow hover:shadow-md">
            <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-violet-500 to-purple-600" />
            <CardContent className="p-5">
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <div className="rounded-lg bg-violet-100 p-2 dark:bg-violet-900/30">
                            <SmartphoneNfc className="size-5 text-violet-600 dark:text-violet-400" />
                        </div>
                        <div>
                            <p className="font-semibold leading-tight">{pkg.name}</p>
                            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                <Globe className="size-3" />
                                {pkg.country}
                            </p>
                        </div>
                    </div>
                    <Badge variant="secondary" className="shrink-0 text-xs capitalize">
                        {pkg.provider}
                    </Badge>
                </div>

                <div className="mb-4 grid grid-cols-2 gap-3">
                    <div className="rounded-lg bg-muted/50 p-3 text-center">
                        <Wifi className="mx-auto mb-1 size-4 text-violet-500" />
                        <p className="text-lg font-black text-foreground">{dataLabel}</p>
                        <p className="text-xs text-muted-foreground">{t('esim.results.data_size')}</p>
                    </div>
                    <div className="rounded-lg bg-muted/50 p-3 text-center">
                        <Clock className="mx-auto mb-1 size-4 text-violet-500" />
                        <p className="text-lg font-black text-foreground">{pkg.validity_days}</p>
                        <p className="text-xs text-muted-foreground">{t('esim.results.validity_days', { days: '' }).replace(':days', '').trim() || 'days'}</p>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-2xl font-black text-violet-600">
                            {formatMoney(pkg.price, pkg.currency ?? 'USD')}
                        </p>
                        <p className="text-xs text-muted-foreground">{t('esim.results.per_package')}</p>
                    </div>
                    <Button
                        type="button"
                        className="bg-violet-600 hover:bg-violet-700"
                        onClick={() => onSelect(pkg)}
                    >
                        {t('esim.results.select_package')}
                        <ChevronRight className="ml-1 size-4" />
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

// ─── Filter Sidebar ───────────────────────────────────────────────────────────

function FilterSidebar({ packages, filters, setFilters, t }) {
    const prices = packages.map((p) => Number(p.price ?? 0));
    const globalMin = prices.length ? Math.floor(Math.min(...prices)) : 0;
    const globalMax = prices.length ? Math.ceil(Math.max(...prices)) : 500;

    const providers = [...new Set(packages.map((p) => p.provider).filter(Boolean))];

    const hasActiveFilters =
        filters.priceMin > globalMin ||
        filters.priceMax < globalMax ||
        filters.provider !== '' ||
        filters.minData > 0 ||
        filters.minValidity > 0;

    const clearFilters = () =>
        setFilters({
            priceMin: globalMin,
            priceMax: globalMax,
            provider: '',
            minData: 0,
            minValidity: 0,
        });

    return (
        <aside className="space-y-6">
            <div className="flex items-center justify-between">
                <h3 className="flex items-center gap-2 font-semibold">
                    <SlidersHorizontal className="size-4 text-violet-500" />
                    {t('esim.results.filters')}
                </h3>
                {hasActiveFilters && (
                    <button
                        type="button"
                        onClick={clearFilters}
                        className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <X className="size-3" />
                        {t('esim.results.clear_filters')}
                    </button>
                )}
            </div>

            {/* Price range */}
            <div className="space-y-3">
                <p className="text-sm font-medium">{t('esim.results.price_range')}</p>
                <PriceRangeSlider
                    min={globalMin}
                    max={globalMax}
                    valueMin={filters.priceMin}
                    valueMax={filters.priceMax}
                    onChange={(lo, hi) => setFilters((f) => ({ ...f, priceMin: lo, priceMax: hi }))}
                />
                <div className="flex items-center justify-between text-xs text-muted-foreground">
                    <span>${filters.priceMin}</span>
                    <span>${filters.priceMax}</span>
                </div>
            </div>

            {/* Min data */}
            <div className="space-y-2">
                <p className="text-sm font-medium">{t('esim.results.data_size')} (MB min)</p>
                <Input
                    type="number"
                    min="0"
                    value={filters.minData || ''}
                    onChange={(e) => setFilters((f) => ({ ...f, minData: Number(e.target.value) || 0 }))}
                    placeholder={t('esim.results.any')}
                    className="h-8 text-sm"
                />
            </div>

            {/* Min validity */}
            <div className="space-y-2">
                <p className="text-sm font-medium">{t('esim.results.validity')} (days min)</p>
                <Input
                    type="number"
                    min="0"
                    value={filters.minValidity || ''}
                    onChange={(e) => setFilters((f) => ({ ...f, minValidity: Number(e.target.value) || 0 }))}
                    placeholder={t('esim.results.any')}
                    className="h-8 text-sm"
                />
            </div>

            {/* Provider */}
            {providers.length > 1 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">{t('esim.results.provider')}</p>
                    <div className="space-y-1">
                        <button
                            type="button"
                            onClick={() => setFilters((f) => ({ ...f, provider: '' }))}
                            className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors ${
                                filters.provider === '' ? 'bg-violet-100 font-medium text-violet-700 dark:bg-violet-900/30 dark:text-violet-300' : 'hover:bg-muted'
                            }`}
                        >
                            {t('esim.results.all_providers')}
                        </button>
                        {providers.map((p) => (
                            <button
                                key={p}
                                type="button"
                                onClick={() => setFilters((f) => ({ ...f, provider: p }))}
                                className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm capitalize transition-colors ${
                                    filters.provider === p ? 'bg-violet-100 font-medium text-violet-700 dark:bg-violet-900/30 dark:text-violet-300' : 'hover:bg-muted'
                                }`}
                            >
                                {p}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </aside>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ESimResults({ searchUuid, search }) {
    const { t } = useTranslation();

    const [state, setState] = React.useState({ loading: true, packages: [], error: '' });
    const [filters, setFilters] = React.useState({
        priceMin: 0,
        priceMax: 99999,
        provider: '',
        minData: 0,
        minValidity: 0,
    });
    const [sort, setSort] = React.useState('price_asc');
    const [selecting, setSelecting] = React.useState(null);

    // Fetch packages async
    React.useEffect(() => {
        let cancelled = false;

        const load = async () => {
            setState({ loading: true, packages: [], error: '' });
            try {
                const res = await fetch(route('esim.packages', searchUuid), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (cancelled) return;
                if (!res.ok) {
                    setState({ loading: false, packages: [], error: data?.message || t('esim.results.error') });
                    return;
                }
                const pkgs = Array.isArray(data?.packages) ? data.packages : [];
                const prices = pkgs.map((p) => Number(p.price ?? 0));
                const globalMin = prices.length ? Math.floor(Math.min(...prices)) : 0;
                const globalMax = prices.length ? Math.ceil(Math.max(...prices)) : 500;
                setFilters((f) => ({ ...f, priceMin: globalMin, priceMax: globalMax }));
                setState({ loading: false, packages: pkgs, error: '' });
            } catch {
                if (!cancelled) {
                    setState({ loading: false, packages: [], error: t('esim.results.error') });
                }
            }
        };

        load();
        return () => { cancelled = true; };
    }, [searchUuid]);

    const filtered = React.useMemo(() => {
        let list = state.packages.filter((p) => {
            const price = Number(p.price ?? 0);
            if (price < filters.priceMin || price > filters.priceMax) return false;
            if (filters.provider && p.provider !== filters.provider) return false;
            if (filters.minData > 0 && Number(p.data_mb ?? 0) < filters.minData) return false;
            if (filters.minValidity > 0 && Number(p.validity_days ?? 0) < filters.minValidity) return false;
            return true;
        });

        list = [...list].sort((a, b) => {
            if (sort === 'price_asc') return Number(a.price) - Number(b.price);
            if (sort === 'price_desc') return Number(b.price) - Number(a.price);
            if (sort === 'data_desc') return Number(b.data_mb) - Number(a.data_mb);
            if (sort === 'validity_desc') return Number(b.validity_days) - Number(a.validity_days);
            return 0;
        });

        return list;
    }, [state.packages, filters, sort]);

    const handleSelect = (pkg) => {
        setSelecting(pkg.id);
        router.post(
            route('esim.select', searchUuid),
            { package_id: pkg.id },
            { onFinish: () => setSelecting(null) },
        );
    };

    return (
        <TenantNavbarLayout>
            <Head title={t('esim.results.title')} />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <button
                            type="button"
                            onClick={() => router.visit(route('esim.index'))}
                            className="mb-2 flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" />
                            {t('esim.results.back_to_search')}
                        </button>
                        <h1 className="text-2xl font-black tracking-tight">
                            {t('esim.results.heading')}
                        </h1>
                        {search?.country && (
                            <p className="mt-1 flex items-center gap-1 text-sm text-muted-foreground">
                                <Globe className="size-4" />
                                {t('esim.results.searching_for', { country: search.country })}
                            </p>
                        )}
                    </div>

                    {/* Sort */}
                    {!state.loading && filtered.length > 0 && (
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-muted-foreground">{t('esim.results.sort_by')}:</span>
                            <select
                                value={sort}
                                onChange={(e) => setSort(e.target.value)}
                                className="rounded-md border bg-background px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                            >
                                <option value="price_asc">{t('esim.results.sort_price_asc')}</option>
                                <option value="price_desc">{t('esim.results.sort_price_desc')}</option>
                                <option value="data_desc">{t('esim.results.sort_data_desc')}</option>
                                <option value="validity_desc">{t('esim.results.sort_validity_desc')}</option>
                            </select>
                        </div>
                    )}
                </div>

                {/* Loading skeleton */}
                {state.loading && (
                    <div className="flex flex-col items-center justify-center gap-4 py-24 text-muted-foreground">
                        <Loader2 className="size-8 animate-spin text-violet-500" />
                        <p>{t('esim.results.loading')}</p>
                    </div>
                )}

                {/* Error */}
                {!state.loading && state.error && (
                    <div className="flex flex-col items-center gap-3 py-20 text-center">
                        <AlertCircle className="size-10 text-destructive" />
                        <p className="font-medium text-destructive">{state.error}</p>
                        <Button variant="outline" onClick={() => router.visit(route('esim.index'))}>
                            {t('esim.results.back_to_search')}
                        </Button>
                    </div>
                )}

                {/* Results */}
                {!state.loading && !state.error && (
                    <div className="flex gap-8">
                        {/* Sidebar */}
                        <div className="hidden w-64 shrink-0 lg:block">
                            <FilterSidebar
                                packages={state.packages}
                                filters={filters}
                                setFilters={setFilters}
                                t={t}
                            />
                        </div>

                        {/* Package grid */}
                        <div className="min-w-0 flex-1">
                            {filtered.length === 0 ? (
                                <div className="flex flex-col items-center gap-3 py-20 text-center">
                                    <SmartphoneNfc className="size-10 text-muted-foreground" />
                                    <p className="font-medium">{t('esim.results.no_packages')}</p>
                                    <p className="text-sm text-muted-foreground">{t('esim.results.try_again')}</p>
                                </div>
                            ) : (
                                <>
                                    <p className="mb-4 text-sm text-muted-foreground">
                                        {t('esim.results.package_count', { count: filtered.length })}
                                    </p>
                                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                        {filtered.map((pkg) => (
                                            <div key={pkg.id} className="relative">
                                                {selecting === pkg.id && (
                                                    <div className="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-background/70">
                                                        <Loader2 className="size-6 animate-spin text-violet-500" />
                                                    </div>
                                                )}
                                                <PackageCard pkg={pkg} onSelect={handleSelect} t={t} />
                                            </div>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </TenantNavbarLayout>
    );
}
