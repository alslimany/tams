import React, { useRef, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { useTranslation } from '@/hooks/useTranslation';
import { CheckIcon, ChevronDownIcon, Loader2, SearchIcon, SmartphoneNfc, X } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Convert an ISO alpha2 code to its flag emoji. e.g. 'TR' → '🇹🇷' */
const toFlagEmoji = (alpha2) =>
    [...alpha2.toUpperCase()].map((c) => String.fromCodePoint(0x1f1e6 - 65 + c.charCodeAt(0))).join('');

// ─── Searchable Country Select ────────────────────────────────────────────────

function CountrySelect({ countries, value, onChange, placeholder, id, error }) {
    const { props } = usePage();
    const locale = props.locale || 'en';

    const getLabel = (c) => {
        if (locale === 'ar' && c.name_ar) return c.name_ar;
        if (locale === 'fr' && c.name_fr) return c.name_fr;
        return c.name_en;
    };

    const selectedCountry = countries.find((c) => c.alpha2 === value) ?? null;

    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [highlighted, setHighlighted] = useState(0);
    const wrapperRef = useRef(null);
    const listRef = useRef(null);
    const inputRef = useRef(null);

    const filtered = query.trim() === ''
        ? countries
        : countries.filter((c) =>
            getLabel(c).toLowerCase().includes(query.toLowerCase()) ||
            c.alpha2.toLowerCase().includes(query.toLowerCase()),
        );

    const openDropdown = () => {
        setQuery('');
        setHighlighted(0);
        setOpen(true);
        setTimeout(() => inputRef.current?.focus(), 0);
    };

    const closeDropdown = () => {
        setOpen(false);
        setQuery('');
    };

    const select = (c) => {
        onChange(c.alpha2);
        closeDropdown();
    };

    const clear = (e) => {
        e.stopPropagation();
        onChange('');
        setOpen(false);
    };

    React.useEffect(() => {
        const handler = (e) => {
            if (wrapperRef.current && !wrapperRef.current.contains(e.target)) closeDropdown();
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    React.useEffect(() => {
        if (!open || highlighted < 0) return;
        const el = listRef.current?.querySelector(`[data-idx="${highlighted}"]`);
        el?.scrollIntoView({ block: 'nearest' });
    }, [highlighted, open]);

    const handleKeyDown = (e) => {
        if (!open) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setHighlighted((i) => Math.min(i + 1, filtered.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setHighlighted((i) => Math.max(i - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); if (filtered[highlighted]) select(filtered[highlighted]); }
        else if (e.key === 'Escape') closeDropdown();
    };

    return (
        <div ref={wrapperRef} className="relative">
            <button
                id={id}
                type="button"
                onClick={() => (open ? closeDropdown() : openDropdown())}
                className={cn(
                    'flex h-10 w-full items-center justify-between rounded-md border bg-white px-3 py-2 text-sm ring-offset-background',
                    'focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 dark:bg-slate-900',
                    error ? 'border-destructive' : 'border-input',
                )}
            >
                <span className={cn('flex items-center gap-2 truncate', !selectedCountry && 'text-muted-foreground')}>
                    {selectedCountry ? (
                        <>
                            <span className="text-lg leading-none">{toFlagEmoji(selectedCountry.alpha2)}</span>
                            {getLabel(selectedCountry)}
                        </>
                    ) : placeholder}
                </span>
                <span className="flex items-center gap-1">
                    {selectedCountry && (
                        <span role="button" tabIndex={-1} onMouseDown={clear} className="rounded p-0.5 hover:bg-muted">
                            <X className="size-3 text-muted-foreground" />
                        </span>
                    )}
                    <ChevronDownIcon className={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
                </span>
            </button>

            {open && (
                <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-md border bg-popover shadow-md">
                    <div className="border-b p-2">
                        <Input
                            ref={inputRef}
                            value={query}
                            onChange={(e) => { setQuery(e.target.value); setHighlighted(0); }}
                            onKeyDown={handleKeyDown}
                            placeholder="Search country…"
                            className="h-8 text-sm"
                        />
                    </div>
                    <ul ref={listRef} className="max-h-60 overflow-auto py-1 text-sm text-popover-foreground">
                        {filtered.length === 0 ? (
                            <li className="px-3 py-2 text-muted-foreground">No countries found.</li>
                        ) : filtered.map((c, idx) => (
                            <li
                                key={c.alpha2}
                                data-idx={idx}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => select(c)}
                                onMouseEnter={() => setHighlighted(idx)}
                                className={cn(
                                    'flex cursor-pointer items-center justify-between px-3 py-1.5',
                                    highlighted === idx ? 'bg-accent text-accent-foreground' : 'hover:bg-accent hover:text-accent-foreground',
                                )}
                            >
                                <span>
                                    <span className="mr-2 text-base leading-none">{toFlagEmoji(c.alpha2)}</span>
                                    {getLabel(c)}
                                </span>
                                {value === c.alpha2 && <CheckIcon className="size-4 shrink-0 text-primary" />}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

// ─── Featured Country Card ────────────────────────────────────────────────────

function FeaturedCountryCard({ country, locale, onSelect }) {
    const getLabel = (c) => {
        if (locale === 'ar' && c.name_ar) return c.name_ar;
        if (locale === 'fr' && c.name_fr) return c.name_fr;
        return c.name_en;
    };

    return (
        <button
            type="button"
            onClick={() => onSelect(country.alpha2)}
            className="group flex flex-col items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-4 py-4 text-white backdrop-blur-sm transition-all hover:border-white/40 hover:bg-white/20 hover:scale-105 focus:outline-none focus:ring-2 focus:ring-white/50"
        >
            <span className="text-4xl leading-none drop-shadow-sm">{toFlagEmoji(country.alpha2)}</span>
            <span className="text-sm font-medium leading-tight">{getLabel(country)}</span>
        </button>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function ESimSearch({ countries = [], featuredCountries = [] }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const locale = props.locale || 'en';

    const form = useForm({ country: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('esim.search'), { preserveScroll: true });
    };

    const selectFeatured = (alpha2) => {
        form.setData('country', alpha2);
        // Small timeout so the state update propagates before submitting
        setTimeout(() => {
            form.post(route('esim.search'), { preserveScroll: true });
        }, 0);
    };

    return (
        <TenantNavbarLayout>
            <Head title={t('esim.search.title')} />

            <section className="relative min-h-dvh bg-slate-900">
                <div
                    className="absolute inset-0 bg-cover bg-center bg-no-repeat"
                    style={{ backgroundImage: "url('/img/search-hero-esim.png')" }}
                />
                <div className="absolute inset-0 bg-gradient-to-b from-slate-900/70 via-slate-900/60 to-slate-900/90" />

                <div className="relative z-10 mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div className="mb-10 text-center">
                        <Badge variant="secondary" className="mb-4 border-primary/30 bg-primary/10 text-white/90">
                            <SmartphoneNfc className="mr-1 size-3" />
                            {t('esim.search.badge')}
                        </Badge>
                        <h1 className="text-balance text-4xl font-bold text-white sm:text-5xl lg:text-6xl">
                            {t('esim.search.heading')}
                        </h1>
                        <p className="mx-auto mt-4 max-w-2xl text-pretty text-lg text-slate-300">
                            {t('esim.search.subheading')}
                        </p>
                    </div>

                    <Card className="mx-auto max-w-5xl overflow-visible border-0 bg-white/95 shadow-2xl backdrop-blur-md dark:bg-slate-800/95">
                        <CardHeader className="pb-4">
                            <CardTitle className="flex items-center gap-2">
                                <SmartphoneNfc className="size-5 text-primary" />
                                {t('esim.search.form_title')}
                            </CardTitle>
                            <CardDescription>{t('esim.search.form_description')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit}>
                                <div className="grid items-end gap-3 rounded-2xl border bg-muted/20 p-3 sm:grid-cols-[1fr_auto]">
                                    <div className="space-y-2">
                                        <Label htmlFor="esim-country">{t('esim.search.country')}</Label>
                                        <CountrySelect
                                            id="esim-country"
                                            countries={countries}
                                            value={form.data.country}
                                            onChange={(alpha2) => form.setData('country', alpha2)}
                                            placeholder={t('esim.search.country_placeholder')}
                                            error={!!form.errors.country}
                                        />
                                        {form.errors.country && (
                                            <p className="text-xs text-destructive">{form.errors.country}</p>
                                        )}
                                    </div>

                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="bg-primary hover:bg-primary/90"
                                        disabled={form.processing || !form.data.country}
                                    >
                                        {form.processing ? (
                                            <>
                                                <Loader2 className="mr-2 size-4 animate-spin" />
                                                {t('esim.search.searching')}
                                            </>
                                        ) : (
                                            <>
                                                <SearchIcon className="mr-2 size-4" />
                                                {t('esim.search.search_button')}
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {featuredCountries.length > 0 && (
                        <div className="mx-auto mt-8 max-w-5xl">
                            <p className="mb-4 text-center text-sm font-medium text-white/70">
                                {t('esim.search.featured_destinations')}
                            </p>
                            <div className="flex flex-wrap justify-center gap-3">
                                {featuredCountries.map((country) => (
                                    <FeaturedCountryCard
                                        key={country.alpha2}
                                        country={country}
                                        locale={locale}
                                        onSelect={selectFeatured}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </section>
        </TenantNavbarLayout>
    );
}
