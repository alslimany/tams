import React from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { useTranslation } from '@/hooks/useTranslation';
import { formatMoney } from '@/lib/currency';
import { cn } from '@/lib/utils';
import {
    AlertCircle,
    ArrowLeft,
    ChevronRight,
    Clock,
    Database,
    Globe,
    Loader2,
    Signal,
    SlidersHorizontal,
    SmartphoneNfc,
    X,
} from 'lucide-react';

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatDataLabel = (dataMb, t) => {
    if (dataMb <= 0) return '∞';
    return dataMb >= 1024
        ? `${(dataMb / 1024) % 1 === 0 ? dataMb / 1024 : (dataMb / 1024).toFixed(1)} ${t('esim.results.gb_short')}`
        : `${dataMb} ${t('esim.results.mb_short')}`;
};

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
                className="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-primary"
                style={{ left: `${pctMin}%`, right: `${100 - pctMax}%` }}
            />
            <input
                type="range" min={min} max={max} step={1} value={valueMin}
                onChange={(e) => onChange(clamp(Number(e.target.value), min, valueMax - 1), valueMax)}
                className="pointer-events-none absolute inset-0 h-full w-full appearance-none bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-primary [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm"
                style={{ zIndex: valueMin > max - 10 ? 5 : 3 }}
            />
            <input
                type="range" min={min} max={max} step={1} value={valueMax}
                onChange={(e) => onChange(valueMin, clamp(Number(e.target.value), valueMin + 1, max))}
                className="pointer-events-none absolute inset-0 h-full w-full appearance-none bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-primary [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm"
                style={{ zIndex: 4 }}
            />
        </div>
    );
}

// ─── Chip Group ───────────────────────────────────────────────────────────────

function ChipGroup({ label, options, value, onChange, anyLabel }) {
    return (
        <div className="space-y-2">
            <p className="text-sm font-medium">{label}</p>
            <div className="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    onClick={() => onChange(null)}
                    className={cn(
                        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                        value === null
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                    )}
                >
                    {anyLabel}
                </button>
                {options.map((opt) => (
                    <button
                        key={String(opt.value)}
                        type="button"
                        onClick={() => onChange(opt.value)}
                        className={cn(
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            value === opt.value
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {opt.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

// ─── Package Card ─────────────────────────────────────────────────────────────

function PackageCard({ pkg, onSelect, t }) {
    const dataMb = Number(pkg.data_mb ?? 0);
    const isUnlimited = Boolean(pkg.unlimited);
    const dataLabel = isUnlimited ? '∞' : formatDataLabel(dataMb, t);
    const speeds = Array.isArray(pkg.speeds) ? pkg.speeds : [];

    return (
        <Card className="group relative overflow-hidden transition-shadow hover:shadow-md">
            <div className="absolute inset-x-0 top-0 h-1 bg-primary" />
            <CardContent className="p-5">
                {/* Header: country + badges */}
                <div className="mb-4 flex items-center justify-between gap-3">
                    <p className="flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
                        <Globe className="size-4 shrink-0" />
                        {pkg.country}
                    </p>
                    <div className="flex shrink-0 items-center gap-1">
                        {isUnlimited && (
                            <Badge className="bg-primary text-xs text-primary-foreground hover:bg-primary">
                                {t('esim.results.unlimited')}
                            </Badge>
                        )}
                        <Badge variant="secondary" className="text-xs capitalize">
                            {pkg.provider}
                        </Badge>
                    </div>
                </div>

                {/* Stats */}
                <div className="mb-4 grid grid-cols-2 gap-3">
                    <div className="rounded-lg bg-muted/50 p-3 text-center">
                        <Database className="mx-auto mb-1 size-4 text-primary" />
                        <p className={`font-black text-foreground ${isUnlimited ? 'text-2xl' : 'text-lg'}`}>{dataLabel}</p>
                        <p className="text-xs text-muted-foreground">{t('esim.results.data_size')}</p>
                    </div>
                    <div className="rounded-lg bg-muted/50 p-3 text-center">
                        <Clock className="mx-auto mb-1 size-4 text-primary" />
                        <p className="text-lg font-black text-foreground">{pkg.validity_days}</p>
                        <p className="text-xs text-muted-foreground">{t('esim.results.validity_days', { days: '' }).replace(':days', '').trim() || 'days'}</p>
                    </div>
                </div>

                {speeds.length > 0 && (
                    <div className="mb-4 flex flex-wrap gap-1">
                        {speeds.map((speed) => (
                            <Badge key={speed} variant="outline" className="text-xs">
                                {speed}
                            </Badge>
                        ))}
                    </div>
                )}

                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-2xl font-black text-primary">
                            {formatMoney(pkg.price, pkg.currency ?? 'USD')}
                        </p>
                        <p className="text-xs text-muted-foreground">{t('esim.results.per_package')}</p>
                    </div>
                    <Button type="button" onClick={() => onSelect(pkg)}>
                        {t('esim.results.select_package')}
                        <ChevronRight className="ml-1 size-4" />
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

// ─── Networks Strip ───────────────────────────────────────────────────────────

function NetworksStrip({ networks, countryDisplayName, t }) {
    if (!networks || networks.length === 0) return null;

    return (
        <div className="mt-0 mb-6">
            <div className="mb-3 flex items-center gap-2">
                <Signal className="size-4 text-primary" />
                <h2 className="text-sm font-semibold">
                    {t('esim.results.networks_available', { country: countryDisplayName })}
                </h2>
            </div>
            <div className="flex flex-wrap gap-3">
                {networks.map((network, i) => (
                    <div
                        key={network.brandName || i}
                        className="flex items-center gap-3 rounded-lg border bg-muted/30 px-4 py-2.5"
                    >
                        <div>
                            <p className="text-sm font-semibold leading-tight">
                                {network.brandName || network.name}
                            </p>
                            {network.brandName && network.name !== network.brandName && (
                                <p className="text-xs text-muted-foreground">{network.name}</p>
                            )}
                        </div>
                        {network.speed.length > 0 && (
                            <div className="flex gap-1">
                                {network.speed.map((s) => (
                                    <Badge key={s} variant="secondary" className="px-1.5 py-0 text-xs">
                                        {s}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

// ─── Filter Sidebar ───────────────────────────────────────────────────────────

function FilterSidebar({ packages, filters, setFilters, t }) {
    const prices = packages.map((p) => Number(p.price ?? 0));
    const globalMin = prices.length ? Math.floor(Math.min(...prices)) : 0;
    const globalMax = prices.length ? Math.ceil(Math.max(...prices)) : 500;

    const providers = [...new Set(packages.map((p) => p.provider).filter(Boolean))];

    // Derive unique data size chips from packages
    const dataOptions = React.useMemo(() => {
        const opts = [];
        if (packages.some((p) => p.unlimited)) {
            opts.push({ value: 'unlimited', label: t('esim.results.unlimited') });
        }
        const sizes = [...new Set(
            packages.filter((p) => !p.unlimited).map((p) => Number(p.data_mb ?? 0)).filter((v) => v > 0),
        )].sort((a, b) => a - b);
        sizes.forEach((mb) => opts.push({ value: mb, label: formatDataLabel(mb, t) }));
        return opts;
    }, [packages, t]);

    // Derive unique validity day chips from packages
    const validityOptions = React.useMemo(() => {
        const days = [...new Set(
            packages.map((p) => Number(p.validity_days ?? 0)).filter((v) => v > 0),
        )].sort((a, b) => a - b);
        return days.map((d) => ({ value: d, label: t('esim.results.validity_days', { days: d }) }));
    }, [packages]);

    const hasActiveFilters =
        filters.priceMin > globalMin ||
        filters.priceMax < globalMax ||
        filters.provider !== '' ||
        filters.selectedData !== null ||
        filters.selectedValidity !== null;

    const clearFilters = () =>
        setFilters({
            priceMin: globalMin,
            priceMax: globalMax,
            provider: '',
            selectedData: null,
            selectedValidity: null,
        });

    return (
        <aside className="space-y-6">
            <div className="flex items-center justify-between">
                <h3 className="flex items-center gap-2 font-semibold">
                    <SlidersHorizontal className="size-4 text-primary" />
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

            {/* Data size chips */}
            {dataOptions.length > 0 && (
                <ChipGroup
                    label={t('esim.results.data_size')}
                    options={dataOptions}
                    value={filters.selectedData}
                    onChange={(v) => setFilters((f) => ({ ...f, selectedData: v }))}
                    anyLabel={t('esim.results.any')}
                />
            )}
            {/* Validity chips */}
            {validityOptions.length > 0 && (
                <ChipGroup
                    label={t('esim.results.validity')}
                    options={validityOptions}
                    value={filters.selectedValidity}
                    onChange={(v) => setFilters((f) => ({ ...f, selectedValidity: v }))}
                    anyLabel={t('esim.results.any')}
                />
            )}

            
           

            {/* Provider */}
            {providers.length > 1 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">{t('esim.results.provider')}</p>
                    <div className="space-y-1">
                        {['', ...providers].map((p) => (
                            <button
                                key={p || '__all__'}
                                type="button"
                                onClick={() => setFilters((f) => ({ ...f, provider: p }))}
                                className={cn(
                                    'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm capitalize transition-colors',
                                    filters.provider === p
                                        ? 'bg-primary/10 font-medium text-primary'
                                        : 'hover:bg-muted',
                                )}
                            >
                                {p === '' ? t('esim.results.all_providers') : p}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </aside>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ESimResults({ searchUuid, search, countryNames = null }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const locale = props.locale || 'en';

    const countryDisplayName = countryNames?.[locale] || countryNames?.en || search?.country || '';

    const [state, setState] = React.useState({ loading: true, packages: [], error: '' });
    const [networks, setNetworks] = React.useState([]);
    const [filters, setFilters] = React.useState({
        priceMin: 0,
        priceMax: 99999,
        provider: '',
        selectedData: null,
        selectedValidity: null,
    });
    const [sort, setSort] = React.useState('price_asc');
    const [selecting, setSelecting] = React.useState(null);

    React.useEffect(() => {
        let cancelled = false;

        const load = async () => {
            setState({ loading: true, packages: [], error: '' });
            try {
                const [pkgRes, netRes] = await Promise.all([
                    fetch(route('esim.packages', searchUuid), {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    }),
                    fetch(route('esim.networks', searchUuid), {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    }),
                ]);

                const pkgData = await pkgRes.json();

                if (cancelled) return;

                if (!pkgRes.ok) {
                    setState({ loading: false, packages: [], error: pkgData?.message || t('esim.results.error') });
                } else {
                    const pkgs = Array.isArray(pkgData?.packages) ? pkgData.packages : [];
                    const prices = pkgs.map((p) => Number(p.price ?? 0));
                    const globalMin = prices.length ? Math.floor(Math.min(...prices)) : 0;
                    const globalMax = prices.length ? Math.ceil(Math.max(...prices)) : 500;
                    setFilters((f) => ({ ...f, priceMin: globalMin, priceMax: globalMax }));
                    setState({ loading: false, packages: pkgs, error: '' });
                }

                // Networks: silently ignore errors — supplementary info only
                if (netRes.ok) {
                    const netData = await netRes.json();
                    if (!cancelled && Array.isArray(netData?.networks)) {
                        setNetworks(netData.networks);
                    }
                }
            } catch {
                if (!cancelled) setState({ loading: false, packages: [], error: t('esim.results.error') });
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
            if (filters.selectedData !== null) {
                if (filters.selectedData === 'unlimited') {
                    if (!p.unlimited) return false;
                } else {
                    if (p.unlimited || Number(p.data_mb ?? 0) !== filters.selectedData) return false;
                }
            }
            if (filters.selectedValidity !== null && Number(p.validity_days ?? 0) !== filters.selectedValidity) return false;
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
                                {t('esim.results.searching_for', { country: countryDisplayName })}
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
                                className="rounded-md border bg-background px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            >
                                <option value="price_asc">{t('esim.results.sort_price_asc')}</option>
                                <option value="price_desc">{t('esim.results.sort_price_desc')}</option>
                                <option value="data_desc">{t('esim.results.sort_data_desc')}</option>
                                <option value="validity_desc">{t('esim.results.sort_validity_desc')}</option>
                            </select>
                        </div>
                    )}
                </div>

                {/* Loading */}
                {state.loading && (
                    <div className="flex flex-col items-center justify-center gap-4 py-24 text-muted-foreground">
                        <Loader2 className="size-8 animate-spin text-primary" />
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
                            <NetworksStrip
                                networks={networks}
                                countryDisplayName={countryDisplayName}
                                t={t}
                            />

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
                                                        <Loader2 className="size-6 animate-spin text-primary" />
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
