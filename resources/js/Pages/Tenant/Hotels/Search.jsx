import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { Calendar } from '@/Components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { cn } from '@/lib/utils';
import { useTranslation } from '@/hooks/useTranslation';
import {
    BedDouble,
    CalendarDays,
    ChevronDown,
    HotelIcon,
    Loader2,
    MapPin,
    Minus,
    Plus,
    SearchIcon,
    Users,
} from 'lucide-react';

const MAX_ROOMS = 6;
const MAX_ADULTS = 9;
const MAX_CHILDREN = 6;
const MAX_CHILD_AGE = 17;

const clamp = (value, min, max) => Math.min(Math.max(Number(value) || min, min), max);

const destinationLabel = (destination) => [destination.label, destination.country].filter(Boolean).join(', ');

export default function HotelSearch() {
    const { t } = useTranslation();
    const [destinations, setDestinations] = useState([]);
    const [destinationQuery, setDestinationQuery] = useState('');
    const [selectedDestination, setSelectedDestination] = useState(null);
    const [destinationError, setDestinationError] = useState('');
    const [isDestinationOpen, setIsDestinationOpen] = useState(false);
    const [isLoadingDestinations, setIsLoadingDestinations] = useState(false);
    const [isTravellersOpen, setIsTravellersOpen] = useState(false);
    const [isDateOpen, setIsDateOpen] = useState(false);
    const [visibleMonth, setVisibleMonth] = useState(new Date());
    const destinationLoadError = t('common.unable_to_load_hotel_destinations');
    const destinationRef = useRef(null);
    const travellersRef = useRef(null);

    const form = useForm({
        city: '',
        city_id: '',
        check_in: '',
        check_out: '',
        rooms: [
            { adult: 2, children: [] },
        ],
    });
    const destinationValidationError = selectedDestination ? '' : (form.errors.city_id || destinationError);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (destinationRef.current && !destinationRef.current.contains(event.target)) {
                setIsDestinationOpen(false);
            }

            if (travellersRef.current && !travellersRef.current.contains(event.target)) {
                setIsTravellersOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);

        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        if (!isDestinationOpen) {
            return undefined;
        }

        const controller = new AbortController();
        const timeoutId = window.setTimeout(async () => {
            setIsLoadingDestinations(true);
            setDestinationError('');

            try {
                const url = route('hotels.autocomplete', { q: destinationQuery.trim() });
                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const payload = await response.json();

                if (!response.ok) {
                    setDestinationError(payload?.message || destinationLoadError);
                    setDestinations([]);

                    return;
                }

                setDestinations(Array.isArray(payload?.destinations) ? payload.destinations : []);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setDestinationError(destinationLoadError);
                    setDestinations([]);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoadingDestinations(false);
                }
            }
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timeoutId);
        };
    }, [destinationQuery, destinationLoadError, isDestinationOpen]);

    const filteredDestinations = useMemo(() => {
        const query = destinationQuery.trim().toLowerCase();
        const filtered = query === ''
            ? destinations
            : destinations.filter((destination) => {
                const text = `${destination.label ?? ''} ${destination.country ?? ''} ${destination.category ?? ''}`.toLowerCase();

                return text.includes(query);
            });

        return filtered.slice(0, 12);
    }, [destinations, destinationQuery]);

    const totals = useMemo(() => form.data.rooms.reduce((summary, room) => ({
        rooms: summary.rooms + 1,
        adults: summary.adults + Number(room.adult || 0),
        children: summary.children + room.children.length,
    }), { rooms: 0, adults: 0, children: 0 }), [form.data.rooms]);

    const travellersSummary = [
        t('common.rooms_count', { count: totals.rooms }),
        t('common.adults_count', { count: totals.adults }),
        t('common.children_count', { count: totals.children }),
    ].join(', ');

    const dateRange = useMemo(() => {
        const from = form.data.check_in ? new Date(form.data.check_in) : undefined;
        const to = form.data.check_out ? new Date(form.data.check_out) : undefined;

        return {
            from: from && !Number.isNaN(from.getTime()) ? from : undefined,
            to: to && !Number.isNaN(to.getTime()) ? to : undefined,
        };
    }, [form.data.check_in, form.data.check_out]);

    const dateRangeLabel = dateRange.from
        ? dateRange.to
            ? `${format(dateRange.from, 'LLL dd, y')} - ${format(dateRange.to, 'LLL dd, y')}`
            : format(dateRange.from, 'LLL dd, y')
        : t('common.pick_date_range');

    const applyDateRange = (range) => {
        if (!range) {
            return;
        }

        form.setData({
            ...form.data,
            check_in: range.from ? format(range.from, 'yyyy-MM-dd') : '',
            check_out: range.to ? format(range.to, 'yyyy-MM-dd') : '',
        });

        if (range.from && range.to) {
            setIsDateOpen(false);
        }
    };

    const selectDestination = (destination) => {
        form.setData({
            ...form.data,
            city: String(destination.label ?? ''),
            city_id: '',
        });
        setSelectedDestination(destination);
        setDestinationQuery(destinationLabel(destination));
        setDestinationError('');
        form.clearErrors('city', 'city_id');
        setIsDestinationOpen(false);
    };

    const updateRoom = (index, key, value) => {
        form.setData('rooms', form.data.rooms.map((room, roomIndex) => {
            if (roomIndex !== index) {
                return room;
            }

            return { ...room, [key]: clamp(value, 1, MAX_ADULTS) };
        }));
    };

    const updateChildAge = (roomIndex, childIndex, value) => {
        form.setData('rooms', form.data.rooms.map((room, currentRoomIndex) => {
            if (currentRoomIndex !== roomIndex) {
                return room;
            }

            return {
                ...room,
                children: room.children.map((age, currentChildIndex) => currentChildIndex === childIndex ? clamp(value, 0, MAX_CHILD_AGE) : age),
            };
        }));
    };

    const addRoom = () => {
        if (form.data.rooms.length >= MAX_ROOMS) {
            return;
        }

        form.setData('rooms', [...form.data.rooms, { adult: 2, children: [] }]);
    };

    const removeRoom = (index) => {
        if (form.data.rooms.length <= 1) {
            return;
        }

        form.setData('rooms', form.data.rooms.filter((_, roomIndex) => roomIndex !== index));
    };

    const addChild = (roomIndex) => {
        form.setData('rooms', form.data.rooms.map((room, index) => {
            if (index !== roomIndex || room.children.length >= MAX_CHILDREN) {
                return room;
            }

            return { ...room, children: [...room.children, 8] };
        }));
    };

    const removeChild = (roomIndex, childIndex) => {
        form.setData('rooms', form.data.rooms.map((room, index) => {
            if (index !== roomIndex) {
                return room;
            }

            return { ...room, children: room.children.filter((_, currentChildIndex) => currentChildIndex !== childIndex) };
        }));
    };

    const submit = (event) => {
        event.preventDefault();

        if (selectedDestination) {
            router.post(route('hotels.search'), {
                ...form.data,
                city: String(selectedDestination.label ?? ''),
                city_id: '',
            }, {
                preserveScroll: true,
                onError: (errors) => form.setError(errors),
            });

            return;
        }

        setDestinationError(t('common.select_destination_from_list'));
    };

    return (
        <TenantNavbarLayout>
            <Head title={t('common.hotel_search')} />

            <section className="relative min-h-dvh bg-slate-900">
               <div
                    className="absolute inset-0 bg-cover bg-center opacity-50"
                    style={{ backgroundImage: "url('/img/search-hero-hotels.png')" }}
                />

                <div className="relative z-10 mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div className="mb-10 text-center">
                        <Badge variant="secondary" className="mb-4 border-sky-500/30 bg-sky-500/10 text-sky-300">
                            <HotelIcon className="mr-1 size-3" />
                            {t('common.hotels_by_3t')}
                        </Badge>
                        <h1 className="text-balance text-4xl font-bold text-white sm:text-5xl lg:text-6xl">{t('common.find_the_right_stay')}</h1>
                        <p className="mx-auto mt-4 max-w-2xl text-pretty text-lg text-slate-300">{t('common.search_compare_book_hotels')}</p>
                    </div>

                    <Card className="mx-auto max-w-6xl overflow-visible border-0 bg-white shadow-2xl dark:bg-slate-800">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <SearchIcon className="size-5 text-sky-600" />
                                {t('common.hotel_search_form')}
                            </CardTitle>
                            <CardDescription>{t('common.select_destination_dates_occupancies')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-6" onSubmit={submit}>
                                <div className="grid gap-3 rounded-2xl border bg-muted/20 p-3 lg:grid-cols-12 lg:items-end">
                                    <div ref={destinationRef} className="relative space-y-2 lg:col-span-4">
                                        <Label htmlFor="hotel-destination">{t('common.destination')}</Label>
                                        <div className="relative">
                                            <MapPin className="absolute left-3 top-3 size-4 text-muted-foreground" />
                                                    <Input
                                                        id="hotel-destination"
                                                        autoComplete="off"
                                                        className="bg-white pl-9 dark:bg-slate-900"
                                                        value={destinationQuery}
                                                        onChange={(event) => {
                                                            setDestinationQuery(event.target.value);
                                                            setSelectedDestination(null);
                                                            setIsDestinationOpen(true);
                                                            setDestinationError('');
                                                            form.clearErrors('city', 'city_id');
                                                            form.setData({ ...form.data, city: '', city_id: '' });
                                                        }}
                                                        onFocus={() => {
                                                            if (!selectedDestination) {
                                                                setIsDestinationOpen(true);
                                                            }
                                                        }}
                                                        placeholder={t('common.search_destination')}
                                                    />
                                                </div>

                                                {isDestinationOpen && !selectedDestination && (
                                                    <div className="absolute z-50 mt-1 max-h-80 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
                                                {isLoadingDestinations ? (
                                                    <div className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
                                                        <Loader2 className="size-4 animate-spin" />
                                                        {t('common.searching_destinations')}
                                                    </div>
                                                ) : filteredDestinations.length > 0 ? (
                                                            filteredDestinations.map((destination) => (
                                                                <button
                                                                    key={`${destination.category}-${destination.label}`}
                                                            type="button"
                                                            className="flex w-full items-start gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-muted focus:bg-muted focus:outline-hidden"
                                                            onClick={() => selectDestination(destination)}
                                                        >
                                                            <MapPin className="mt-0.5 size-4 text-sky-600" />
                                                            <span className="min-w-0 flex-1">
                                                                <span className="block truncate font-medium">{destination.label}</span>
                                                                <span className="block truncate text-xs text-muted-foreground">{destination.country} · {destination.category}</span>
                                                            </span>
                                                        </button>
                                                    ))
                                                ) : (
                                                    <div className="p-3 text-sm text-muted-foreground">{t('common.no_destinations_found')}</div>
                                                )}
                                            </div>
                                        )}
{/* 
                                        {destinationValidationError && <p className="text-xs text-destructive">{destinationValidationError}</p>}
                                        {!selectedDestination && !destinationValidationError && (
                                            <p className="text-xs text-muted-foreground">{t('common.select_destination_from_list')}</p>
                                        )} */}
                                    </div>

                                    <div className="space-y-2 lg:col-span-4">
                                        <Label>{t('common.stay_dates')}</Label>
                                        <Popover open={isDateOpen} onOpenChange={setIsDateOpen}>
                                            <PopoverTrigger asChild>
                                                <Button type="button" variant="outline" className="w-full justify-start bg-white text-left font-normal dark:bg-slate-900">
                                                    <CalendarDays className="mr-2 size-4 text-muted-foreground" />
                                                    {dateRangeLabel}
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent className="w-auto p-0" align="start">
                                                <Calendar
                                                    mode="range"
                                                    selected={dateRange}
                                                    onSelect={applyDateRange}
                                                    onMonthChange={setVisibleMonth}
                                                    month={visibleMonth}
                                                    numberOfMonths={2}
                                                    disabled={{ before: new Date() }}
                                                    initialFocus
                                                />
                                            </PopoverContent>
                                        </Popover>
                                        {form.errors.check_in && <p className="text-xs text-destructive">{form.errors.check_in}</p>}
                                        {form.errors.check_out && <p className="text-xs text-destructive">{form.errors.check_out}</p>}
                                    </div>

                                    <div ref={travellersRef} className="relative space-y-2 lg:col-span-3">
                                        <Label htmlFor="hotel-travellers">{t('common.travellers')}</Label>
                                        <button
                                            id="hotel-travellers"
                                            type="button"
                                            className="flex h-10 w-full items-center justify-between rounded-md border bg-white px-3 text-sm dark:bg-slate-900"
                                            onClick={() => setIsTravellersOpen((isOpen) => !isOpen)}
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                <Users className="size-4 text-muted-foreground" />
                                                <span className="truncate">{travellersSummary}</span>
                                            </span>
                                            <ChevronDown className={cn('size-4 text-muted-foreground', isTravellersOpen && 'rotate-180')} />
                                        </button>

                                        {isTravellersOpen && (
                                            <div className="absolute z-50 mt-1 max-h-96 w-full overflow-auto rounded-md border bg-popover p-3 text-popover-foreground shadow-md lg:w-96">
                                                <div className="mb-3 flex items-center justify-between gap-3">
                                                    <div>
                                                        <p className="font-medium">{t('common.rooms_and_guests')}</p>
                                                        <p className="text-xs text-muted-foreground">{t('common.configure_rooms_guests')}</p>
                                                    </div>
                                                    <Button type="button" variant="outline" size="sm" onClick={addRoom} disabled={form.data.rooms.length >= MAX_ROOMS}>
                                                        <Plus className="mr-1 size-4" /> {t('common.room')}
                                                    </Button>
                                                </div>

                                                <div className="space-y-3">
                                                    {form.data.rooms.map((room, roomIndex) => (
                                                        <div key={roomIndex} className="rounded-lg border bg-background p-3">
                                                            <div className="mb-3 flex items-center justify-between gap-3">
                                                                <p className="flex items-center gap-2 font-medium">
                                                                    <BedDouble className="size-4 text-sky-600" />
                                                                    {t('common.room_number', { number: roomIndex + 1 })}
                                                                </p>
                                                                <Button type="button" variant="ghost" size="sm" onClick={() => removeRoom(roomIndex)} disabled={form.data.rooms.length <= 1}>
                                                                    <Minus className="mr-1 size-4" /> {t('common.remove')}
                                                                </Button>
                                                            </div>

                                                            <div className="grid gap-3 sm:grid-cols-2">
                                                                <div className="space-y-2">
                                                                    <Label htmlFor={`hotel-room-${roomIndex}-adults`}>{t('common.adults')}</Label>
                                                                    <Input id={`hotel-room-${roomIndex}-adults`} type="number" min="1" max={MAX_ADULTS} value={room.adult} onChange={(event) => updateRoom(roomIndex, 'adult', event.target.value)} />
                                                                </div>
                                                                <div className="space-y-2">
                                                                    <Label>{t('common.children')}</Label>
                                                                    <Button type="button" variant="outline" className="w-full justify-start" onClick={() => addChild(roomIndex)} disabled={room.children.length >= MAX_CHILDREN}>
                                                                        <Plus className="mr-1 size-4" /> {t('common.add_child')}
                                                                    </Button>
                                                                </div>
                                                            </div>

                                                            {room.children.length > 0 && (
                                                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                                    {room.children.map((age, childIndex) => (
                                                                        <div key={childIndex} className="flex items-end gap-2">
                                                                            <div className="min-w-0 flex-1 space-y-2">
                                                                                <Label htmlFor={`hotel-room-${roomIndex}-child-${childIndex}`}>{t('common.child_age_number', { number: childIndex + 1 })}</Label>
                                                                                <Input id={`hotel-room-${roomIndex}-child-${childIndex}`} type="number" min="0" max={MAX_CHILD_AGE} value={age} onChange={(event) => updateChildAge(roomIndex, childIndex, event.target.value)} />
                                                                            </div>
                                                                            <Button type="button" variant="ghost" size="icon" aria-label={t('common.remove_child')} onClick={() => removeChild(roomIndex, childIndex)}>
                                                                                <Minus className="size-4" />
                                                                            </Button>
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {form.errors.rooms && <p className="text-xs text-destructive">{form.errors.rooms}</p>}
                                    </div>

                                </div>

                                <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-sm text-muted-foreground">{t('common.hotel_search_hint')}</p>
                                    <Button type="button" onClick={submit} className="bg-sky-600 hover:bg-sky-700" disabled={form.processing}>
                                        {form.processing ? (
                                            <>
                                                <Loader2 className="mr-2 size-4 animate-spin" />
                                                {t('common.searching_hotels')}
                                            </>
                                        ) : (
                                            <>
                                                <SearchIcon className="mr-2 size-4" />
                                                {t('common.search_hotels')}
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </TenantNavbarLayout>
    );
}
