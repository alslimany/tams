import React, { useState, useRef, useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/Select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import {
    CalendarIcon,
    Plane,
    Users,
    Minus,
    Plus,
    ChevronDown,
    ArrowRightLeft,
    ArrowRight,
    Shield,
    Clock,
    Tag,
    Globe,
    Star,
    Percent,
    MapPin,
    TrendingDown,
} from 'lucide-react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Calendar } from '@/Components/ui/calendar';
import { AsyncAirportSelect } from '@/Components/ui/AsyncAirportSelect';
import { useTranslation } from '@/hooks/useTranslation';

export default function Search({ searchDisplayMode, bookings, filters, airlines, searchDefaults = {} }) {
    const [isPaxDropdownOpen, setIsPaxDropdownOpen] = useState(false);
    const [visibleMonth, setVisibleMonth] = useState(() => (searchDefaults.date ? new Date(searchDefaults.date) : new Date()));
    const [calendarHints, setCalendarHints] = useState({});
    const paxDropdownRef = useRef(null);

    const { data, setData, get, processing, errors } = useForm({
        origin: searchDefaults.origin || '',
        destination: searchDefaults.destination || '',
        date: searchDefaults.date || '',
        return_date: searchDefaults.return_date || '',
        adults: Number(searchDefaults.adults ?? 1),
        children: Number(searchDefaults.children ?? 0),
        infants: Number(searchDefaults.infants ?? 0),
        is_return: Boolean(searchDefaults.is_return ?? false),
        cabin_class: searchDefaults.cabin_class || 'economy',
    });

    const { t } = useTranslation();

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (paxDropdownRef.current && !paxDropdownRef.current.contains(event.target)) {
                setIsPaxDropdownOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        const origin = (data.origin || '').trim().toUpperCase();
        const destination = (data.destination || '').trim().toUpperCase();

        if (origin.length !== 3 || destination.length !== 3 || Number.isNaN(visibleMonth.getTime())) {
            setCalendarHints({});
            return;
        }

        const month = format(visibleMonth, 'yyyy-MM');
        const url = route('flights.calendar-hints', {
            origin,
            destination,
            month,
        });

        let aborted = false;

        fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Calendar hints request failed.');
                }

                return response.json();
            })
            .then((payload) => {
                if (!aborted) {
                    setCalendarHints(payload?.hints || {});
                }
            })
            .catch(() => {
                if (!aborted) {
                    setCalendarHints({});
                }
            });

        return () => {
            aborted = true;
        };
    }, [data.origin, data.destination, visibleMonth]);

    const submit = (e) => {
        e.preventDefault();
        get(route('flights.search'));
    };

    const updatePax = (type, delta) => {
        const newVal = Math.max(type === 'adults' ? 1 : 0, Math.min(9, data[type] + delta));
        setData(type, newVal);
    };

    const totalPax = data.adults + data.children + data.infants;

    const departureDate = data.date ? new Date(data.date) : undefined;
    const returnDate = data.return_date ? new Date(data.return_date) : undefined;
    const tripRange = {
        from: departureDate,
        to: returnDate,
    };

    const applySingleDate = (selectedDate) => {
        if (!selectedDate) {
            return;
        }

        setData('date', format(selectedDate, 'yyyy-MM-dd'));
    };

    const applyRangeDate = (selectedRange) => {
        setData('date', selectedRange?.from ? format(selectedRange.from, 'yyyy-MM-dd') : '');
        setData('return_date', selectedRange?.to ? format(selectedRange.to, 'yyyy-MM-dd') : '');
    };

    const renderDayButton = ({ day, children, ...dayButtonProps }) => {
        const key = format(day.date, 'yyyy-MM-dd');
        const hint = calendarHints[key];
        const hasHint = hint && typeof hint.price === 'number';

        return (
            <button {...dayButtonProps} type="button" className={`${dayButtonProps.className || ''} h-12`}>
                <span>{children}</span>
                {hasHint ? (
                    <span className="mt-0.5 flex items-center text-[10px] leading-none text-emerald-600">
                        {Math.round(hint.price)} {hint.currency}
                    </span>
                ) : null}
            </button>
        );
    };

    const filterBookings = (event) => {
        event.preventDefault();

        const formData = new FormData(event.currentTarget);
        const query = Object.fromEntries(Array.from(formData.entries()).filter(([, value]) => value));
        router.get(route('flights.index'), query, { preserveState: true, preserveScroll: true });
    };

    const popularRoutes = [
        { from: 'Tripoli (MJI)', to: 'Istanbul (IST)', price: 285, airline: 'Berniq Airways', tag: 'Popular' },
        { from: 'Benghazi (BEN)', to: 'Cairo (CAI)', price: 220, airline: 'Buraq Air', tag: 'Cheapest' },
        { from: 'Tripoli (MJI)', to: 'Dubai (DXB)', price: 390, airline: 'Libyan Wings', tag: 'Direct' },
        { from: 'Misrata (MRA)', to: 'Istanbul (IST)', price: 310, airline: 'Medsky Airways', tag: 'New' },
    ];

    const specialOffers = [
        {
            title: 'Summer Getaway',
            description: 'Save up to 20% on round-trip flights to Istanbul. Book before June 30th.',
            discount: '20% OFF',
            icon: Percent,
            gradient: 'from-amber-500 to-orange-600',
        },
        {
            title: 'Early Bird Special',
            description: 'Book 30 days in advance and enjoy reduced fares on select routes.',
            discount: '15% OFF',
            icon: Clock,
            gradient: 'from-blue-500 to-indigo-600',
        },
        {
            title: 'Group Discount',
            description: 'Traveling with 4+ passengers? Get special group rates on all airlines.',
            discount: '25% OFF',
            icon: Users,
            gradient: 'from-emerald-500 to-teal-600',
        },
    ];

    const features = [
        {
            icon: Shield,
            title: 'Secure Booking',
            description: 'Your transactions are protected with industry-standard encryption.',
        },
        {
            icon: Clock,
            title: 'Instant Confirmation',
            description: 'Receive your booking confirmation and e-ticket within minutes.',
        },
        {
            icon: Tag,
            title: 'Best Prices',
            description: 'We compare fares across all airlines to find you the best deal.',
        },
        {
            icon: Globe,
            title: 'Multiple Airlines',
            description: 'Access flights from 7+ Libyan airlines in one search.',
        },
    ];

    return (
        <TenantNavbarLayout>
            <Head title={t('common.search_flights')} />

            {/* Hero Section */}
            <section className="relative min-h-[600px]  bg-slate-900">
                {/* Background Image */}
                <div
                    className="absolute inset-0 bg-cover bg-center bg-no-repeat"
                    style={{
                        backgroundImage:
                            "url('/img/search-hero-istanbul.png')",
                    }}
                />
                {/* Gradient Overlay */}
                <div className="absolute inset-0 bg-gradient-to-b from-slate-900/70 via-slate-900/60 to-slate-900/90" />

                {/* Hero Content */}
                <div className="relative z-10 mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    {/* Headline */}
                    <div className="mb-10 text-center">
                        <Badge variant="secondary" className="mb-4 border-sky-500/30 bg-sky-500/10 text-sky-300">
                            <Plane className="mr-1 h-3 w-3" />
                            {t('common.fly_smarter')}
                        </Badge>
                        <h1 className="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">
                            {t('common.explore_skies')}
                        </h1>
                        <p className="mx-auto mt-4 max-w-2xl text-lg text-slate-300">
                            {t('common.search_compare_flights')}
                        </p>
                    </div>

                    {/* Search Card */}
                    <Card className="mx-auto max-w-5xl overflow-visible border-0 bg-white/95 shadow-2xl backdrop-blur-md dark:bg-slate-800/95">
                        <CardHeader className="pb-4">
                            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        {t('common.book_next_flight')}
                                    </CardTitle>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant={!data.is_return ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setData('is_return', false)}
                                        className="gap-2"
                                    >
                                        <ArrowRight className="h-4 w-4" />
                                        {t('common.one_way')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant={data.is_return ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setData('is_return', true)}
                                        className="gap-2"
                                    >
                                        <ArrowRightLeft className="h-4 w-4" />
                                        {t('common.round_trip')}
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-6">
                                <div className="grid gap-4 md:grid-cols-12">
                                    <div className="space-y-2 md:col-span-4">
                                        <Label htmlFor="origin">{t('common.from')}</Label>
                                        <AsyncAirportSelect
                                            id="origin"
                                            placeholder=""
                                            value={data.origin}
                                            onChange={(event) => setData('origin', event.target.value.toUpperCase())}
                                        />
                                        {errors.origin && <p className="text-xs text-destructive">{errors.origin}</p>}
                                    </div>

                                    <div className="hidden items-end justify-center pb-2 md:col-span-1 md:flex">
                                        <div className="rounded-md border p-2">
                                            <ArrowRightLeft className="h-4 w-4 text-muted-foreground" />
                                        </div>
                                    </div>

                                    <div className="space-y-2 md:col-span-4">
                                        <Label htmlFor="destination">{t('common.to')}</Label>
                                        <AsyncAirportSelect
                                            id="destination"
                                            placeholder=""
                                            value={data.destination}
                                            onChange={(event) => setData('destination', event.target.value.toUpperCase())}
                                            isDestination
                                        />
                                        {errors.destination && <p className="text-xs text-destructive">{errors.destination}</p>}
                                    </div>

                                    <div className="space-y-2 md:col-span-3">
                                        <Label htmlFor="cabin_class">{t('common.class')}</Label>
                                        <Select
                                            value={data.cabin_class}
                                            onValueChange={(value) => setData('cabin_class', value)}
                                        >
                                            <SelectTrigger id="cabin_class" className="w-full rounded-md border-input bg-background">
                                                <SelectValue placeholder={t('common.select_class')} />
                                            </SelectTrigger>
                                            <SelectContent className="rounded-md" align="start">
                                                <SelectItem value="economy">{t('common.economy')}</SelectItem>
                                                <SelectItem value="premium_economy">{t('common.premium_economy')}</SelectItem>
                                                <SelectItem value="business">{t('common.business')}</SelectItem>
                                                <SelectItem value="first">{t('common.first_class')}</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-12">
                                    {!data.is_return ? (
                                        <div className="space-y-2 md:col-span-6">
                                            <Label htmlFor="date">{t('common.departure_date')}</Label>
                                            <Popover>
                                                <PopoverTrigger asChild>
                                                <Button id="date" variant="outline" className="w-full justify-start text-left font-normal">
                                                    <CalendarIcon className="mr-2 h-4 w-4" />
                                                    {departureDate ? format(departureDate, 'PPP') : t('common.pick_date')}
                                                </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-auto p-0" align="start">
                                                    <Calendar
                                                        mode="single"
                                                        selected={departureDate}
                                                        onSelect={applySingleDate}
                                                        onMonthChange={setVisibleMonth}
                                                        month={visibleMonth}
                                                        components={{ DayButton: renderDayButton }}
                                                        initialFocus
                                                    />
                                                </PopoverContent>
                                            </Popover>
                                            {errors.date && <p className="text-xs text-destructive">{errors.date}</p>}
                                        </div>
                                    ) : (
                                        <div className="space-y-2 md:col-span-6">
                                            <Label htmlFor="date-range">{t('common.trip_dates')}</Label>
                                            <Popover>
                                                <PopoverTrigger asChild>
                                                    <Button id="date-range" variant="outline" className="w-full justify-start text-left font-normal">
                                                        <CalendarIcon className="mr-2 h-4 w-4" />
                                                        {tripRange.from
                                                            ? tripRange.to
                                                                ? `${format(tripRange.from, 'LLL dd, y')} - ${format(tripRange.to, 'LLL dd, y')}`
                                                                : format(tripRange.from, 'LLL dd, y')
                                                            : t('common.pick_date_range')}
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-auto p-0" align="start">
                                                    <Calendar
                                                        mode="range"
                                                        selected={tripRange}
                                                        onSelect={applyRangeDate}
                                                        onMonthChange={setVisibleMonth}
                                                        month={visibleMonth}
                                                        components={{ DayButton: renderDayButton }}
                                                        numberOfMonths={2}
                                                        initialFocus
                                                    />
                                                </PopoverContent>
                                            </Popover>
                                            {errors.date && <p className="text-xs text-destructive">{errors.date}</p>}
                                            {errors.return_date && <p className="text-xs text-destructive">{errors.return_date}</p>}
                                        </div>
                                    )}

                                    <div className="relative space-y-2 md:col-span-6" ref={paxDropdownRef}>
                                        <Label>{t('common.passengers')}</Label>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setIsPaxDropdownOpen(!isPaxDropdownOpen)}
                                            className="w-full justify-between"
                                        >
                                            <div className="flex items-center gap-2">
                                                <Users className="h-4 w-4 text-muted-foreground" />
                                                <span>{totalPax} {t('common.passenger')}{totalPax > 1 ? t('common.plural_suffix') : ''}</span>
                                                <Badge variant="secondary" className="ml-1 text-xs font-medium">
                                                    {data.adults}A, {data.children}C, {data.infants}I
                                                </Badge>
                                            </div>
                                            <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform ${isPaxDropdownOpen ? 'rotate-180' : ''}`} />
                                        </Button>

                                        {isPaxDropdownOpen && (
                                            <Card className="absolute left-0 top-full z-50 mt-2 w-full md:w-80">
                                                <CardContent className="space-y-4 p-4">
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <p className="text-sm font-medium">{t('common.adults')}</p>
                                                        <p className="text-xs text-muted-foreground">{t('common.age_12_plus')}</p>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <Button type="button" variant="outline" size="icon" onClick={() => updatePax('adults', -1)} disabled={data.adults <= 1}>
                                                            <Minus className="h-3 w-3" />
                                                        </Button>
                                                        <span className="w-4 text-center text-sm font-medium">{data.adults}</span>
                                                        <Button type="button" variant="outline" size="icon" onClick={() => updatePax('adults', 1)} disabled={totalPax >= 9}>
                                                            <Plus className="h-3 w-3" />
                                                        </Button>
                                                    </div>
                                                </div>

                                                <div className="flex items-center justify-between border-t pt-3">
                                                    <div>
                                                        <p className="text-sm font-medium">{t('common.children')}</p>
                                                        <p className="text-xs text-muted-foreground">{t('common.age_2_11')}</p>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <Button type="button" variant="outline" size="icon" onClick={() => updatePax('children', -1)} disabled={data.children <= 0}>
                                                            <Minus className="h-3 w-3" />
                                                        </Button>
                                                        <span className="w-4 text-center text-sm font-medium">{data.children}</span>
                                                        <Button type="button" variant="outline" size="icon" onClick={() => updatePax('children', 1)} disabled={totalPax >= 9}>
                                                            <Plus className="h-3 w-3" />
                                                        </Button>
                                                    </div>
                                                </div>

                                                <div className="flex items-center justify-between border-t pt-3">
                                                    <div>
                                                        <p className="text-sm font-medium">{t('common.infants')}</p>
                                                        <p className="text-xs text-muted-foreground">{t('common.under_2')}</p>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <Button type="button" variant="outline" size="icon" onClick={() => updatePax('infants', -1)} disabled={data.infants <= 0}>
                                                            <Minus className="h-3 w-3" />
                                                        </Button>
                                                        <span className="w-4 text-center text-sm font-medium">{data.infants}</span>
                                                        <Button type="button" variant="outline" size="icon" onClick={() => updatePax('infants', 1)} disabled={totalPax >= 9 || data.infants >= data.adults}>
                                                            <Plus className="h-3 w-3" />
                                                        </Button>
                                                    </div>
                                                </div>
                                                
                                                <div className="pt-2 border-t">
                                                    <Button type="button" className="w-full" onClick={() => setIsPaxDropdownOpen(false)}>
                                                        {t('common.done')}
                                                    </Button>
                                                </div>
                                                </CardContent>
                                            </Card>
                                        )}
                                    </div>
                                </div>

                                <div className="flex justify-end pt-3 border-t">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="w-full bg-sky-600 px-8 text-base hover:bg-sky-700 md:w-auto"
                                        disabled={processing}
                                    >
                                        <Plane className="mr-2 h-4 w-4" />
                                        {processing ? t('common.searching_flights') : t('common.find_flights')}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </section>

            {/* Popular Routes Section */}
            <section className="bg-background py-16">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-8 text-center">
                        <div className="mb-2 flex items-center justify-center gap-2 text-primary">
                            <TrendingDown className="h-5 w-5" />
                            <span className="text-sm font-semibold uppercase tracking-wider">{t('common.popular_routes')}</span>
                        </div>
                        <h2 className="text-3xl font-bold tracking-tight">{t('common.cheapest_flights')}</h2>
                        <p className="mt-2 text-muted-foreground">
                            {t('common.top_selling_routes')}
                        </p>
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {popularRoutes.map((route, index) => (
                            <Card
                                key={index}
                                className="group cursor-pointer transition-all duration-300 hover:-translate-y-1 hover:shadow-lg"
                            >
                                <CardContent className="p-6">
                                    <div className="mb-3 flex items-center justify-between">
                                        <Badge variant="secondary" className="text-xs">
                                            {route.tag}
                                        </Badge>
                                        <span className="text-xs text-muted-foreground">{route.airline}</span>
                                    </div>

                                    <div className="mb-4 flex items-center gap-3">
                                        <div className="text-center">
                                            <p className="text-sm font-semibold">{route.from.split('(')[0].trim()}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {route.from.match(/\(([^)]+)\)/)?.[1]}
                                            </p>
                                        </div>
                                        <div className="flex flex-1 items-center">
                                            <div className="h-px flex-1 bg-border" />
                                            <Plane className="mx-2 h-4 w-4 text-primary" />
                                            <div className="h-px flex-1 bg-border" />
                                        </div>
                                        <div className="text-center">
                                            <p className="text-sm font-semibold">{route.to.split('(')[0].trim()}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {route.to.match(/\(([^)]+)\)/)?.[1]}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-end justify-between">
                                        <div>
                                            <p className="text-xs text-muted-foreground">{t('common.starting_from')}</p>
                                            <p className="text-2xl font-bold text-primary">${route.price}</p>
                                        </div>
                                        <Button variant="outline" size="sm" className="group-hover:bg-primary group-hover:text-primary-foreground">
                                            {t('common.book_now')}
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>
            </section>

            {/* Special Offers Section */}
            <section className="bg-muted/40 py-16">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-8 text-center">
                        <div className="mb-2 flex items-center justify-center gap-2 text-primary">
                            <Star className="h-5 w-5" />
                            <span className="text-sm font-semibold uppercase tracking-wider">{t('common.special_offers')}</span>
                        </div>
                        <h2 className="text-3xl font-bold tracking-tight">{t('common.exclusive_deals')}</h2>
                        <p className="mt-2 text-muted-foreground">
                            {t('common.limited_promotions')}
                        </p>
                    </div>

                    <div className="grid gap-6 md:grid-cols-3">
                        {specialOffers.map((offer, index) => {
                            const Icon = offer.icon;
                            return (
                                <Card
                                    key={index}
                                    className="group overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg"
                                >
                                    <div className={`h-2 bg-gradient-to-r ${offer.gradient}`} />
                                    <CardContent className="p-6">
                                        <div className="mb-4 flex items-start justify-between">
                                            <div className={`rounded-lg bg-gradient-to-r ${offer.gradient} p-3`}>
                                                <Icon className="h-6 w-6 text-white" />
                                            </div>
                                            <Badge className={`bg-gradient-to-r ${offer.gradient} border-0 text-sm font-bold text-white`}>
                                                {offer.discount}
                                            </Badge>
                                        </div>
                                        <h3 className="mb-2 text-xl font-semibold">{offer.title}</h3>
                                        <p className="mb-4 text-sm text-muted-foreground">{offer.description}</p>
                                        <Button variant="outline" className="w-full group-hover:bg-primary group-hover:text-primary-foreground">
                                            {t('common.view_details')}
                                        </Button>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* Why Book With Us Section */}
            <section className="bg-background py-16">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-8 text-center">
                        <div className="mb-2 flex items-center justify-center gap-2 text-primary">
                            <Shield className="h-5 w-5" />
                            <span className="text-sm font-semibold uppercase tracking-wider">{t('common.why_choose_us')}</span>
                        </div>
                        <h2 className="text-3xl font-bold tracking-tight">{t('common.travel_confidence')}</h2>
                        <p className="mt-2 text-muted-foreground">
                            {t('common.booking_simple_secure')}
                        </p>
                    </div>

                    <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        {features.map((feature, index) => {
                            const Icon = feature.icon;
                            return (
                                <div key={index} className="text-center">
                                    <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10">
                                        <Icon className="h-7 w-7 text-primary" />
                                    </div>
                                    <h3 className="mb-2 text-lg font-semibold">{feature.title}</h3>
                                    <p className="text-sm text-muted-foreground">{feature.description}</p>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* CTA Banner */}
            <section className="relative overflow-hidden bg-gradient-to-r from-sky-600 to-indigo-700 py-12">
                <div className="absolute inset-0 opacity-10">
                    <div className="absolute -left-20 -top-20 h-72 w-72 rounded-full bg-white" />
                    <div className="absolute -bottom-10 -right-10 h-56 w-56 rounded-full bg-white" />
                </div>
                <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-col items-center justify-between gap-6 md:flex-row">
                        <div className="text-center md:text-left">
                            <h2 className="text-2xl font-bold text-white sm:text-3xl">{t('common.ready_take_off')}</h2>
                            <p className="mt-2 text-sky-100">
                                {t('common.search_hundreds_flights')}
                            </p>
                        </div>
                        <Button
                            size="lg"
                            variant="secondary"
                            className="bg-white px-8 text-sky-700 hover:bg-sky-50"
                            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
                        >
                            <Plane className="mr-2 h-4 w-4" />
                            {t('common.search_flights_now')}
                        </Button>
                    </div>
                </div>
            </section>
        </TenantNavbarLayout>
    );
}
