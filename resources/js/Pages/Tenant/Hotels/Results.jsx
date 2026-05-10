import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { formatMoney } from '@/lib/currency';
import { useTranslation } from '@/hooks/useTranslation';
import {
    BedDouble,
    ChevronLeft,
    ChevronDown,
    HotelIcon,
    ImageOff,
    LayoutGrid,
    List,
    LoaderCircle,
    Map as MapIcon,
    MapPin,
    ShieldCheck,
    ShieldOff,
    SlidersHorizontal,
    Star,
    Utensils,
    X,
    ChevronLeft as ArrowLeft,
    ChevronRight as ArrowRight,
    Tag,
    Sparkles,
    CheckCircle2,
    Settings2,
} from 'lucide-react';

// ─── Price Range Slider ──────────────────────────────────────────────────────

function PriceRangeSlider({ min, max, valueMin, valueMax, onChange }) {
    const range = max - min || 1;
    const pctMin = ((valueMin - min) / range) * 100;
    const pctMax = ((valueMax - min) / range) * 100;

    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

    const handleMinChange = (e) => {
        const v = clamp(Number(e.target.value), min, valueMax - 1);
        onChange(v, valueMax);
    };

    const handleMaxChange = (e) => {
        const v = clamp(Number(e.target.value), valueMin + 1, max);
        onChange(valueMin, v);
    };

    return (
        <div className="relative h-5 w-full select-none">
            {/* Track */}
            <div className="absolute top-1/2 h-1.5 w-full -translate-y-1/2 rounded-full bg-muted" />
            {/* Active fill */}
            <div
                className="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-primary"
                style={{ left: `${pctMin}%`, right: `${100 - pctMax}%` }}
            />
            {/* Min thumb */}
            <input
                type="range"
                min={min}
                max={max}
                step={1}
                value={valueMin}
                onChange={handleMinChange}
                className="pointer-events-none absolute inset-0 h-full w-full appearance-none bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-primary [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm [&::-webkit-slider-thumb]:transition-shadow [&::-webkit-slider-thumb]:hover:shadow-md [&::-moz-range-thumb]:pointer-events-auto [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:appearance-none [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-2 [&::-moz-range-thumb]:border-primary [&::-moz-range-thumb]:bg-background"
                style={{ zIndex: valueMin > max - 10 ? 5 : 3 }}
            />
            {/* Max thumb */}
            <input
                type="range"
                min={min}
                max={max}
                step={1}
                value={valueMax}
                onChange={handleMaxChange}
                className="pointer-events-none absolute inset-0 h-full w-full appearance-none bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-primary [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm [&::-webkit-slider-thumb]:transition-shadow [&::-webkit-slider-thumb]:hover:shadow-md [&::-moz-range-thumb]:pointer-events-auto [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:appearance-none [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-2 [&::-moz-range-thumb]:border-primary [&::-moz-range-thumb]:bg-background"
                style={{ zIndex: 4 }}
            />
        </div>
    );
}

// ─── Helpers ────────────────────────────────────────────────────────────────

const getHotelMeta = (hotel) => hotel?.hotel || {};

const getHotelLocation = (hotel, fallbackCity) =>
    [getHotelMeta(hotel).cityName || fallbackCity, getHotelMeta(hotel).countryName]
        .filter(Boolean)
        .join(', ');

/** ratingId comes as a string like "5" or "4" from 3T — parse to int */
const getRating = (hotel) => {
    const meta = getHotelMeta(hotel);
    const raw = meta.ratingId ?? meta.rating ?? 0;
    const n = parseInt(String(raw), 10);
    return Number.isFinite(n) ? n : 0;
};

const getHotelCoords = (hotel) => {
    const meta = getHotelMeta(hotel);
    const lat = parseFloat(meta.latitude);
    const lng = parseFloat(meta.longitude);
    if (Number.isFinite(lat) && Number.isFinite(lng) && lat !== 0 && lng !== 0) {
        return { lat, lng };
    }
    return null;
};

const getLowestAvailableRoom = (rooms) =>
    [...(rooms || [])]
        .filter((room) => room.available && Number(room.price) > 0)
        .sort((a, b) => Number(a.price) - Number(b.price))[0] || null;

const getAvailableRoomsCount = (rooms) => (rooms || []).filter((r) => r.available).length;

const hasCancellationRules = (room) =>
    Array.isArray(room?.cancellation_policies) && room.cancellation_policies.length > 0;

const hasRefundableRate = (rooms) => (rooms || []).some(hasCancellationRules);

// Groups flat rooms array into:
// [ { roomIndex, roomTypes: [ { name, boards: [room, …], lowestPrice, currency } ] } ]
const groupRoomsByIndex = (rooms) => {
    // Step 1: bucket by roomIndex
    const byIndex = new globalThis.Map();
    (rooms || []).forEach((room) => {
        const idx = room.raw?.roomIndex ?? 1;
        if (!byIndex.has(idx)) byIndex.set(idx, []);
        byIndex.get(idx).push(room);
    });

    // Step 2: within each roomIndex, bucket by room_name (room type)
    const groups = [];
    [...byIndex.entries()]
        .sort(([a], [b]) => a - b)
        .forEach(([idx, rates]) => {
            const byType = new globalThis.Map();
            rates.forEach((room) => {
                const typeName = room.room_name || 'Room';
                if (!byType.has(typeName)) byType.set(typeName, []);
                byType.get(typeName).push(room);
            });

            const roomTypes = [...byType.entries()].map(([name, boards]) => {
                const sorted = [...boards].sort((a, b) => Number(a.price) - Number(b.price));
                return {
                    name,
                    boards: sorted,
                    lowestPrice: sorted[0]?.price ?? 0,
                    currency: sorted[0]?.currency ?? 'USD',
                };
            }).sort((a, b) => a.lowestPrice - b.lowestPrice);

            groups.push({ roomIndex: idx, roomTypes });
        });

    return groups;
};

const getCancellationLabel = (room, t) =>
    hasCancellationRules(room)
        ? t('common.cancellation_rules_available')
        : t('common.cancellation_not_provided');

// Collect unique board names from all rooms across all hotels
const collectBoardTypes = (hotels) => {
    const boards = new Set();
    hotels.forEach((hotel) => {
        (hotel.rooms || []).forEach((room) => {
            if (room.board_name) boards.add(room.board_name);
        });
    });
    return [...boards].sort();
};

// ─── Star Rating display ─────────────────────────────────────────────────────

const StarRating = ({ rating, size = 'sm' }) => {
    if (!rating || rating <= 0) return null;
    const sz = size === 'sm' ? 'size-3.5' : 'size-4';
    return (
        <span className="flex items-center gap-0.5 text-amber-400">
            {Array.from({ length: 5 }).map((_, i) => (
                <Star
                    key={i}
                    className={`${sz} ${i < rating ? 'fill-current' : 'fill-none opacity-30'}`}
                />
            ))}
        </span>
    );
};

// ─── Room rate row ────────────────────────────────────────────────────────────

const RoomRate = ({ hotel, room, selectRoom, t }) => (
    <div className="space-y-3 rounded-xl border bg-muted/20 p-3">
        <div className="flex flex-wrap items-center gap-2">
            <Badge variant={room.available ? 'success' : 'destructive'}>
                {room.available ? t('common.available') : t('common.unavailable')}
            </Badge>
            {hasCancellationRules(room) ? (
                <Badge variant="outline" className="gap-1">
                    <ShieldCheck className="size-3 text-green-600" />
                    {t('common.refundable')}
                </Badge>
            ) : (
                <Badge variant="secondary" className="gap-1">
                    <ShieldOff className="size-3" />
                    {t('common.non_refundable')}
                </Badge>
            )}
            {room.raw?.rateClass && (
                <Badge variant="secondary">{room.raw.rateClass}</Badge>
            )}
        </div>
        <div className="space-y-1">
            <p className="flex items-center gap-1 text-sm font-semibold">
                <Utensils className="size-4 text-muted-foreground" />
                {room.board_name || t('common.board_not_specified')}
            </p>
            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                <ShieldCheck className="size-3.5" />
                {getCancellationLabel(room, t)}
            </p>
        </div>
        <div className="flex items-center justify-between gap-3">
            <div>
                <p className="text-lg font-black text-primary">
                    {formatMoney(room.price, room.currency)}
                </p>
                <p className="text-xs font-bold text-muted-foreground">
                    {t('common.total_for_stay')}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                onClick={() => selectRoom(hotel, room)}
                disabled={!room.available}
            >
                {t('common.select_rate')}
            </Button>
        </div>
    </div>
);

// ─── Gallery ──────────────────────────────────────────────────────────────────

const HotelGallery = ({ images, hotelName, t }) => {
    const [current, setCurrent] = React.useState(0);

    if (!images || images.length === 0) {
        return (
            <div className="flex h-64 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                <div className="flex flex-col items-center gap-2">
                    <ImageOff className="size-10" />
                    <p className="text-sm">{t('common.no_images_available')}</p>
                </div>
            </div>
        );
    }

    const prev = () => setCurrent((c) => (c === 0 ? images.length - 1 : c - 1));
    const next = () => setCurrent((c) => (c === images.length - 1 ? 0 : c + 1));

    return (
        <div className="space-y-3">
            <div className="relative h-72 overflow-hidden rounded-xl bg-muted">
                <img
                    src={images[current]}
                    alt={`${hotelName} ${current + 1}`}
                    className="h-full w-full object-cover"
                    loading="lazy"
                />
                {images.length > 1 && (
                    <>
                        <button
                            onClick={prev}
                            aria-label="Previous image"
                            className="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-black/50 p-2 text-white hover:bg-black/70"
                        >
                            <ArrowLeft className="size-4" />
                        </button>
                        <button
                            onClick={next}
                            aria-label="Next image"
                            className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-black/50 p-2 text-white hover:bg-black/70"
                        >
                            <ArrowRight className="size-4" />
                        </button>
                        <div className="absolute bottom-3 right-3 rounded-full bg-black/50 px-2 py-1 text-xs text-white">
                            {current + 1} / {images.length}
                        </div>
                    </>
                )}
            </div>
            {images.length > 1 && (
                <div className="flex gap-2 overflow-x-auto pb-1">
                    {images.map((src, i) => (
                        <button
                            key={i}
                            onClick={() => setCurrent(i)}
                            className={`h-16 w-24 shrink-0 overflow-hidden rounded-lg border-2 transition-all ${
                                i === current
                                    ? 'border-primary opacity-100'
                                    : 'border-transparent opacity-60 hover:opacity-80'
                            }`}
                        >
                            <img src={src} alt={`thumb-${i}`} className="h-full w-full object-cover" loading="lazy" />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

// ─── Hotel Info Modal ─────────────────────────────────────────────────────────

const HotelInfoModal = ({ hotel, search, selectRoom, onClose, t }) => {
    const meta = getHotelMeta(hotel);
    const rating = getRating(hotel);
    const roomGroups = groupRoomsByIndex(hotel.rooms);
    const isMultiRoom = roomGroups.length > 1;

    // { [roomIndex]: room } — the selected board (room object) per room slot
    const [selectedBoards, setSelectedBoards] = React.useState({});

    // Which room-slot accordion panels are open (all open by default)
    const [openPanels, setOpenPanels] = React.useState(() =>
        Object.fromEntries(roomGroups.map((g) => [g.roomIndex, true])),
    );

    // Which room-type row is expanded within each slot: { [roomIndex]: roomTypeName }
    const [openRoomTypes, setOpenRoomTypes] = React.useState({});

    const [infoState, setInfoState] = React.useState({ loading: true, data: null, error: '' });

    React.useEffect(() => {
        const controller = new AbortController();
        const load = async () => {
            setInfoState({ loading: true, data: null, error: '' });
            try {
                const params = new URLSearchParams({
                    hotel_id: String(meta.hotelId || hotel.hotel_id || ''),
                    city_id: String(meta.cityId || ''),
                    source: String(hotel.source || ''),
                });
                const res = await fetch(`${route('hotels.hotel-info')}?${params}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const payload = await res.json();
                if (controller.signal.aborted) return;
                if (!res.ok) {
                    setInfoState({ loading: false, data: null, error: payload?.message || t('common.hotel_info_error') });
                    return;
                }
                setInfoState({ loading: false, data: payload?.hotel || null, error: '' });
            } catch (err) {
                if (!controller.signal.aborted && err.name !== 'AbortError') {
                    setInfoState({ loading: false, data: null, error: t('common.hotel_info_error') });
                }
            }
        };
        load();
        return () => controller.abort();
    }, []);

    const images = React.useMemo(() => {
        const response = infoState.data?.response;
        const fromGallery = Array.isArray(response?.gallery)
            ? response.gallery.map((img) => img?.path).filter(Boolean)
            : [];
        if (fromGallery.length > 0) return fromGallery;
        if (meta.thumbImage) return [meta.thumbImage];
        return [];
    }, [infoState.data, meta.thumbImage]);

    const description = React.useMemo(() => {
        const response = infoState.data?.response;
        return response?.hotelDetails?.description || response?.hotel?.description || meta.description || '';
    }, [infoState.data, meta.description]);

    const promotionTitle = React.useMemo(() => {
        const raw = (infoState.data?.response?.promotionTitle || '').trim();
        return raw.replace(/\s+/g, ' ').trim();
    }, [infoState.data]);

    const amunities = React.useMemo(() => {
        const list = infoState.data?.response?.amunities;
        return Array.isArray(list) ? list.filter(Boolean) : [];
    }, [infoState.data]);

    const options = React.useMemo(() => {
        const list = infoState.data?.response?.options;
        return Array.isArray(list) ? list.filter(Boolean) : [];
    }, [infoState.data]);

    React.useEffect(() => {
        const handleKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', handleKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', handleKey);
            document.body.style.overflow = '';
        };
    }, [onClose]);

    const allRoomsSelected = roomGroups.length > 0 && roomGroups.every((g) => selectedBoards[g.roomIndex]);

    const totalPrice = React.useMemo(
        () => Object.values(selectedBoards).reduce((sum, r) => sum + Number(r?.price ?? 0), 0),
        [selectedBoards],
    );

    const firstCurrency = Object.values(selectedBoards)[0]?.currency
        || roomGroups[0]?.roomTypes[0]?.currency
        || 'USD';

    const handleSelectBoard = (roomIndex, room) => {
        setSelectedBoards((prev) => ({ ...prev, [roomIndex]: room }));
    };

    const handleRoomTypeClick = (roomIndex, roomTypeName, boards) => {
        // Toggle: if already open, close it; otherwise open and default-select cheapest board
        setOpenRoomTypes((prev) => {
            const alreadyOpen = prev[roomIndex] === roomTypeName;
            return { ...prev, [roomIndex]: alreadyOpen ? null : roomTypeName };
        });
        // Select cheapest board of this room type if not already selected from this type
        const cheapest = boards[0];
        if (cheapest) {
            setSelectedBoards((prev) => {
                const current = prev[roomIndex];
                const sameType = current?.room_name === roomTypeName;
                return sameType ? prev : { ...prev, [roomIndex]: cheapest };
            });
        }
    };

    const handleContinue = () => {
        if (!allRoomsSelected) return;
        const rooms = roomGroups.map((g) => selectedBoards[g.roomIndex]);
        onClose();
        selectRoom(hotel, rooms);
    };

    const togglePanel = (roomIndex) =>
        setOpenPanels((prev) => ({ ...prev, [roomIndex]: !prev[roomIndex] }));

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-sm sm:items-center sm:p-4"
            onClick={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="relative flex h-[95dvh] w-full max-w-4xl flex-col overflow-hidden rounded-t-2xl bg-background shadow-2xl sm:h-[90dvh] sm:rounded-2xl">
                {/* Header */}
                <div className="flex shrink-0 items-start justify-between gap-4 border-b p-5">
                    <div className="min-w-0 space-y-1">
                        <h2 className="truncate text-xl font-black tracking-tight">{hotel.name}</h2>
                        <p className="flex items-center gap-1 text-sm text-muted-foreground">
                            <MapPin className="size-4 shrink-0" />
                            {getHotelLocation(hotel, search.city)}
                        </p>
                        <div className="flex flex-wrap items-center gap-2 pt-1">
                            {rating > 0 && <StarRating rating={rating} size="sm" />}
                            <Badge variant="outline" className="gap-1">
                                <HotelIcon className="size-3" />
                                {meta.hotelType || t('common.hotel')}
                            </Badge>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="shrink-0 rounded-full p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label={t('common.close')}
                    >
                        <X className="size-5" />
                    </button>
                </div>

                {/* Scrollable body */}
                <div className="flex-1 overflow-y-auto">
                    {infoState.loading && (
                        <div className="flex items-center justify-center gap-3 p-12 text-muted-foreground">
                            <LoaderCircle className="size-5 animate-spin" />
                            {t('common.hotel_info_loading')}
                        </div>
                    )}
                    {infoState.error && (
                        <div className="p-6">
                            <p className="rounded-md border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
                                {infoState.error}
                            </p>
                        </div>
                    )}

                    {promotionTitle && (
                        <div className="flex items-start gap-3 border-b bg-amber-50 px-5 py-3 dark:bg-amber-950/30">
                            <Tag className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">
                                {promotionTitle}
                            </p>
                        </div>
                    )}

                    <div className="space-y-8 p-5">
                        <section>
                            <h3 className="mb-3 text-base font-black">{t('common.hotel_gallery')}</h3>
                            <HotelGallery images={images} hotelName={hotel.name} t={t} />
                        </section>

                        {description && (
                            <section>
                                <h3 className="mb-2 text-base font-black">{t('common.hotel_description')}</h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">{description}</p>
                            </section>
                        )}

                        {amunities.length > 0 && (
                            <section>
                                <h3 className="mb-3 flex items-center gap-2 text-base font-black">
                                    <Sparkles className="size-4 text-primary" />
                                    {t('common.hotel_amenities')}
                                </h3>
                                <div className="flex flex-wrap gap-2">
                                    {amunities.map((item, i) => (
                                        <div key={i} className="flex items-center gap-1.5 rounded-full border bg-muted/40 px-3 py-1 text-sm">
                                            <CheckCircle2 className="size-3.5 shrink-0 text-primary" />
                                            <span>{item?.title || item?.name || String(item)}</span>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        )}

                        {options.length > 0 && (
                            <section>
                                <h3 className="mb-3 flex items-center gap-2 text-base font-black">
                                    <Settings2 className="size-4 text-primary" />
                                    {t('common.hotel_options')}
                                </h3>
                                <div className="flex flex-wrap gap-2">
                                    {options.map((item, i) => (
                                        <div key={i} className="flex items-center gap-1.5 rounded-full border bg-muted/40 px-3 py-1 text-sm">
                                            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-primary" />
                                            <p className="text-sm">{item?.title || item?.name || String(item)}</p>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        )}

                        {/* ── Room selection ── */}
                        <section>
                            <h3 className="mb-3 text-base font-black">{t('common.hotel_rooms_and_boards')}</h3>
                            {roomGroups.length === 0 ? (
                                <p className="text-sm text-muted-foreground">{t('common.unavailable')}</p>
                            ) : (
                                <div className="space-y-3">
                                    {roomGroups.map((group, groupIdx) => {
                                        const selectedBoard = selectedBoards[group.roomIndex];
                                        const isOpen = openPanels[group.roomIndex] ?? true;
                                        const expandedType = openRoomTypes[group.roomIndex];

                                        return (
                                            <div key={group.roomIndex} className="overflow-hidden rounded-xl border">
                                                {/* Room slot accordion header */}
                                                <button
                                                    type="button"
                                                    onClick={() => togglePanel(group.roomIndex)}
                                                    className="flex w-full items-center justify-between gap-3 bg-muted/30 px-4 py-3 text-left hover:bg-muted/50"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <BedDouble className="size-4 shrink-0 text-primary" />
                                                        <span className="font-bold">
                                                            {isMultiRoom
                                                                ? `${t('common.room')} ${groupIdx + 1}`
                                                                : t('common.select_your_room')}
                                                        </span>
                                                        {selectedBoard && (
                                                            <Badge variant="outline" className="gap-1 text-xs">
                                                                <CheckCircle2 className="size-3 text-green-600" />
                                                                {selectedBoard.room_name}
                                                                {' · '}
                                                                {selectedBoard.board_name}
                                                                {' · '}
                                                                {formatMoney(selectedBoard.price, selectedBoard.currency)}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <ChevronDown
                                                        className={`size-4 shrink-0 text-muted-foreground transition-transform ${isOpen ? 'rotate-180' : ''}`}
                                                    />
                                                </button>

                                                {/* Room types list */}
                                                {isOpen && (
                                                    <div className="divide-y">
                                                        {group.roomTypes.map((roomType) => {
                                                            const isTypeExpanded = expandedType === roomType.name;
                                                            const isTypeSelected = selectedBoard?.room_name === roomType.name;

                                                            return (
                                                                <div key={roomType.name}>
                                                                    {/* Room type row — click to expand boards */}
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleRoomTypeClick(group.roomIndex, roomType.name, roomType.boards)}
                                                                        className={`flex w-full items-center justify-between gap-4 px-4 py-3 text-left transition-colors ${
                                                                            isTypeSelected ? 'bg-primary/5' : 'hover:bg-muted/30'
                                                                        }`}
                                                                    >
                                                                        <div className="flex min-w-0 items-center gap-3">
                                                                            {/* Radio indicator */}
                                                                            <span className={`flex size-4 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${
                                                                                isTypeSelected
                                                                                    ? 'border-primary bg-primary'
                                                                                    : 'border-muted-foreground/40'
                                                                            }`}>
                                                                                {isTypeSelected && <span className="size-1.5 rounded-full bg-white" />}
                                                                            </span>
                                                                            <div className="min-w-0">
                                                                                <p className="truncate text-sm font-semibold">{roomType.name}</p>
                                                                                            <p className="text-xs text-muted-foreground">
                                                                                                {t('common.from_price')} {formatMoney(roomType.lowestPrice, roomType.currency)}
                                                                                                {' · '}
                                                                                                {roomType.boards.length} {t('common.board_options')}
                                                                                            </p>
                                                                            </div>
                                                                        </div>
                                                                        <ChevronDown
                                                                            className={`size-4 shrink-0 text-muted-foreground transition-transform ${isTypeExpanded ? 'rotate-180' : ''}`}
                                                                        />
                                                                    </button>

                                                                    {/* Board options for this room type */}
                                                                    {isTypeExpanded && (
                                                                        <div className="divide-y border-t bg-muted/10">
                                                                            {roomType.boards.map((room) => {
                                                                                const isBoardSelected = selectedBoard?.rate_key === room.rate_key;
                                                                                return (
                                                                                    <button
                                                                                        key={room.rate_key}
                                                                                        type="button"
                                                                                        disabled={!room.available}
                                                                                        onClick={() => handleSelectBoard(group.roomIndex, room)}
                                                                                        className={`flex w-full items-center justify-between gap-4 py-2.5 pe-4 ps-11 text-left transition-colors ${
                                                                                            isBoardSelected
                                                                                                ? 'bg-primary/10 ring-1 ring-inset ring-primary/20'
                                                                                                : 'hover:bg-muted/40'
                                                                                        } ${!room.available ? 'cursor-not-allowed opacity-50' : ''}`}
                                                                                    >
                                                                                        <div className="flex min-w-0 items-center gap-2.5">
                                                                                            <span className={`flex size-3.5 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${
                                                                                                isBoardSelected
                                                                                                    ? 'border-primary bg-primary'
                                                                                                    : 'border-muted-foreground/40'
                                                                                            }`}>
                                                                                                {isBoardSelected && <span className="size-1 rounded-full bg-white" />}
                                                                                            </span>
                                                                                            <p className="truncate text-sm">
                                                                                                {room.board_name || t('common.board_not_specified')}
                                                                                            </p>
                                                                                        </div>
                                                                                        <p className="shrink-0 text-sm font-bold text-primary">
                                                                                            {formatMoney(room.price, room.currency)}
                                                                                        </p>
                                                                                    </button>
                                                                                );
                                                                            })}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </section>
                    </div>
                </div>

                {/* Sticky footer — Continue button */}
                {roomGroups.length > 0 && (
                    <div className="shrink-0 border-t bg-background px-5 py-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                {allRoomsSelected ? (
                                    <>
                                        <p className="text-xs text-muted-foreground">{t('common.total_for_stay')}</p>
                                        <p className="text-lg font-black text-primary">
                                            {formatMoney(totalPrice, firstCurrency)}
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {isMultiRoom
                                            ? t('common.select_board_per_room')
                                            : t('common.select_rate')}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="button"
                                disabled={!allRoomsSelected}
                                onClick={handleContinue}
                                className="min-w-32"
                            >
                                {t('common.continue')}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

// ─── Leaflet Map Modal ────────────────────────────────────────────────────────
// Uses Leaflet loaded via CDN (no npm install needed). Markers are placed at
// hotel lat/lng. The map fits bounds to all mappable hotels on open. As the
// user pans/zooms the sidebar list updates to show only hotels visible in the
// current viewport. Hovering a marker highlights the matching sidebar row and
// vice-versa.

const useLeaflet = () => {
    const [L, setL] = React.useState(null);

    React.useEffect(() => {
        if (window.L) { setL(window.L); return; }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);

        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => setL(window.L);
        document.head.appendChild(script);
    }, []);

    return L;
};

const MapModal = ({ hotels, search, onClose, t }) => {
    const cityName = search.city || '';
    const L = useLeaflet();
    const mapRef = React.useRef(null);
    const mapInstanceRef = React.useRef(null);
    const markersRef = React.useRef([]);
    const listRefs = React.useRef({});

    const [hoveredIndex, setHoveredIndex] = React.useState(null);
    const [visibleIndices, setVisibleIndices] = React.useState(() =>
        hotels.map((_, i) => i),
    );

    // Hotels that have valid coordinates
    const mappableHotels = React.useMemo(
        () => hotels.map((h, i) => ({ hotel: h, index: i, coords: getHotelCoords(h) })).filter((e) => e.coords !== null),
        [hotels],
    );

    // Keyboard close
    React.useEffect(() => {
        const handleKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', handleKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', handleKey);
            document.body.style.overflow = '';
        };
    }, [onClose]);

    // Build map once Leaflet is ready and the container is mounted
    React.useEffect(() => {
        if (!L || !mapRef.current || mapInstanceRef.current) return;

        const map = L.map(mapRef.current, { zoomControl: true });
        mapInstanceRef.current = map;

        // https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png
        L.tileLayer('https://api.maptiler.com/maps/streets-v4/{z}/{x}/{y}.png?key=32cP6Csjp2eFLDD6OqpR', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(map);

        // Custom price-label icon factory
        const makeIcon = (label, highlighted) =>
            L.divIcon({
                className: '',
                html: `<div style="
                    background:${highlighted ? '#2563eb' : '#1e293b'};
                    color:#fff;
                    padding:4px 8px;
                    border-radius:20px;
                    font-size:11px;
                    font-weight:700;
                    white-space:nowrap;
                    box-shadow:0 2px 6px rgba(0,0,0,.35);
                    border:2px solid ${highlighted ? '#93c5fd' : 'transparent'};
                    transform:translateX(-50%);
                    position:relative;
                ">${label}</div>`,
                iconAnchor: [0, 0],
            });

        const bounds = [];
        markersRef.current = [];

        mappableHotels.forEach(({ hotel, index, coords }) => {
            const lowestRoom = getLowestAvailableRoom(hotel.rooms);
            const label = lowestRoom
                ? formatMoney(lowestRoom.price, lowestRoom.currency)
                : hotel.name.slice(0, 12);

            const marker = L.marker([coords.lat, coords.lng], {
                icon: makeIcon(label, false),
            }).addTo(map);

            marker.bindTooltip(hotel.name, { direction: 'top', offset: [0, -4] });

            marker.on('mouseover', () => {
                setHoveredIndex(index);
                marker.setIcon(makeIcon(label, true));
                // Scroll sidebar row into view
                listRefs.current[index]?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
            marker.on('mouseout', () => {
                setHoveredIndex(null);
                marker.setIcon(makeIcon(label, false));
            });

            markersRef.current.push({ marker, index, coords, label });
            bounds.push([coords.lat, coords.lng]);
        });

        // Fit bounds to all mappable hotels, or fall back to world view
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
        } else {
            map.setView([20, 0], 2);
        }

        // Update visible hotels list on every map move/zoom
        const updateVisible = () => {
            const mapBounds = map.getBounds();
            const visible = hotels
                .map((h, i) => {
                    const coords = getHotelCoords(h);
                    if (!coords) return i; // hotels without coords always shown
                    return mapBounds.contains([coords.lat, coords.lng]) ? i : null;
                })
                .filter((i) => i !== null);
            setVisibleIndices(visible);
        };

        map.on('moveend', updateVisible);
        map.on('zoomend', updateVisible);

        return () => {
            map.remove();
            mapInstanceRef.current = null;
        };
    }, [L, mappableHotels]);

    // Highlight marker when sidebar row is hovered
    const handleListHover = (index) => {
        setHoveredIndex(index);
        const entry = markersRef.current.find((m) => m.index === index);
        if (entry) {
            entry.marker.setIcon(
                window.L?.divIcon({
                    className: '',
                    html: `<div style="
                        background:#2563eb;color:#fff;padding:4px 8px;border-radius:20px;
                        font-size:11px;font-weight:700;white-space:nowrap;
                        box-shadow:0 2px 6px rgba(0,0,0,.35);border:2px solid #93c5fd;
                        transform:translateX(-50%);position:relative;
                    ">${entry.label}</div>`,
                    iconAnchor: [0, 0],
                }),
            );
        }
    };

    const handleListLeave = (index) => {
        setHoveredIndex(null);
        const entry = markersRef.current.find((m) => m.index === index);
        if (entry) {
            entry.marker.setIcon(
                window.L?.divIcon({
                    className: '',
                    html: `<div style="
                        background:#1e293b;color:#fff;padding:4px 8px;border-radius:20px;
                        font-size:11px;font-weight:700;white-space:nowrap;
                        box-shadow:0 2px 6px rgba(0,0,0,.35);border:2px solid transparent;
                        transform:translateX(-50%);position:relative;
                    ">${entry.label}</div>`,
                    iconAnchor: [0, 0],
                }),
            );
        }
    };

    const visibleHotels = hotels
        .map((h, i) => ({ hotel: h, index: i }))
        .filter(({ index }) => visibleIndices.includes(index));

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-sm sm:items-center sm:p-4"
            onClick={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="relative flex h-[95dvh] w-full max-w-6xl flex-col overflow-hidden rounded-t-2xl bg-background shadow-2xl sm:h-[90dvh] sm:rounded-2xl">
                {/* Header */}
                <div className="flex shrink-0 items-center justify-between gap-4 border-b px-5 py-4">
                    <div className="flex items-center gap-2">
                        <MapIcon className="size-5 text-primary" />
                        <h2 className="text-lg font-black">{t('common.map_view')} — {cityName}</h2>
                        <Badge variant="secondary">
                            {visibleHotels.length} / {hotels.length} {t('common.hotel')}
                        </Badge>
                        {mappableHotels.length === 0 && (
                            <Badge variant="outline" className="text-xs text-muted-foreground">
                                {t('common.no_coordinates')}
                            </Badge>
                        )}
                    </div>
                    <button
                        onClick={onClose}
                        className="rounded-full p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label={t('common.close')}
                    >
                        <X className="size-5" />
                    </button>
                </div>

                {/* Body: map + hotel list */}
                <div className="flex flex-1 overflow-hidden">
                    {/* Map */}
                    <div className="relative flex-1 bg-muted">
                        {!L && (
                            <div className="absolute inset-0 flex items-center justify-center gap-2 text-muted-foreground">
                                <LoaderCircle className="size-5 animate-spin" />
                                <span className="text-sm">{t('common.loading_map')}</span>
                            </div>
                        )}
                        <div ref={mapRef} className="h-full w-full" />
                    </div>

                    {/* Hotel list sidebar */}
                    <div className="hidden w-80 shrink-0 flex-col overflow-hidden border-s md:flex">
                        <div className="border-b px-4 py-3">
                            <p className="text-sm font-semibold text-muted-foreground">
                                {t('common.showing_results', {
                                    shown: visibleHotels.length,
                                    total: hotels.length,
                                })}
                            </p>
                        </div>
                        <div className="flex-1 divide-y overflow-y-auto">
                            {visibleHotels.map(({ hotel, index }) => {
                                const meta = getHotelMeta(hotel);
                                const rating = getRating(hotel);
                                const lowestRoom = getLowestAvailableRoom(hotel.rooms);
                                const isHovered = hoveredIndex === index;
                                return (
                                    <div
                                        key={index}
                                        ref={(el) => { listRefs.current[index] = el; }}
                                        onMouseEnter={() => handleListHover(index)}
                                        onMouseLeave={() => handleListLeave(index)}
                                        className={`flex cursor-default gap-3 p-3 transition-colors ${
                                            isHovered ? 'bg-primary/10 ring-1 ring-inset ring-primary/20' : 'hover:bg-muted/40'
                                        }`}
                                    >
                                        {/* Thumb */}
                                        <div className="size-14 shrink-0 overflow-hidden rounded-lg bg-muted">
                                            {meta.thumbImage ? (
                                                <img
                                                    src={meta.thumbImage}
                                                    alt={hotel.name}
                                                    className="h-full w-full object-cover"
                                                    loading="lazy"
                                                />
                                            ) : (
                                                <div className="flex h-full items-center justify-center text-muted-foreground">
                                                    <ImageOff className="size-5" />
                                                </div>
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1 space-y-0.5">
                                            <p className="truncate text-sm font-bold">{hotel.name}</p>
                                            {rating > 0 && <StarRating rating={rating} size="sm" />}
                                            <p className="text-xs text-muted-foreground">
                                                {getHotelLocation(hotel, '')}
                                            </p>
                                            {lowestRoom && (
                                                <p className="text-sm font-black text-primary">
                                                    {formatMoney(lowestRoom.price, lowestRoom.currency)}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

// ─── Filter sidebar ───────────────────────────────────────────────────────────

const FilterSidebar = ({ hotels, filters, setFilters, t }) => {
    const boardTypes = React.useMemo(() => collectBoardTypes(hotels), [hotels]);

    const allPrices = hotels
        .map((h) => getLowestAvailableRoom(h.rooms))
        .filter(Boolean)
        .map((r) => Number(r.price));

    const globalMin = allPrices.length ? Math.floor(Math.min(...allPrices)) : 0;
    const globalMax = allPrices.length ? Math.ceil(Math.max(...allPrices)) : 9999;

    const hasActiveFilters =
        filters.stars.length > 0 ||
        filters.refundableOnly ||
        filters.boards.length > 0 ||
        filters.minPrice !== '' ||
        filters.maxPrice !== '';

    const toggleStar = (star) => {
        setFilters((f) => ({
            ...f,
            stars: f.stars.includes(star) ? f.stars.filter((s) => s !== star) : [...f.stars, star],
        }));
    };

    const toggleBoard = (board) => {
        setFilters((f) => ({
            ...f,
            boards: f.boards.includes(board) ? f.boards.filter((b) => b !== board) : [...f.boards, board],
        }));
    };

    const clearAll = () =>
        setFilters({ stars: [], refundableOnly: false, boards: [], minPrice: '', maxPrice: '' });

    return (
        <aside className="w-64 shrink-0 space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <SlidersHorizontal className="size-4 text-primary" />
                    <span className="font-black">{t('common.filters')}</span>
                </div>
                {hasActiveFilters && (
                    <button
                        onClick={clearAll}
                        className="text-xs font-semibold text-primary hover:underline"
                    >
                        {t('common.clear_filters')}
                    </button>
                )}
            </div>

            {/* Star Rating */}
            <div className="space-y-3">
                <p className="text-sm font-semibold">{t('common.filter_stars')}</p>
                <div className="space-y-2">
                    {[5, 4, 3, 2, 1].map((star) => (
                        <label key={star} className="flex cursor-pointer items-center gap-2.5">
                            <input
                                type="checkbox"
                                checked={filters.stars.includes(star)}
                                onChange={() => toggleStar(star)}
                                className="size-4 rounded border-input accent-primary"
                            />
                            <span className="flex items-center gap-1 text-amber-400">
                                {Array.from({ length: star }).map((_, i) => (
                                    <Star key={i} className="size-3.5 fill-current" />
                                ))}
                            </span>
                        </label>
                    ))}
                </div>
            </div>

            {/* Price Range — dual-handle slider */}
            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <p className="text-sm font-semibold">{t('common.filter_price_range')}</p>
                    <span className="text-xs text-muted-foreground">
                        {filters.minPrice !== '' ? Math.round(filters.minPrice) : globalMin}
                        {' – '}
                        {filters.maxPrice !== '' ? Math.round(filters.maxPrice) : globalMax}
                    </span>
                </div>
                <PriceRangeSlider
                    min={globalMin}
                    max={globalMax}
                    valueMin={filters.minPrice !== '' ? Number(filters.minPrice) : globalMin}
                    valueMax={filters.maxPrice !== '' ? Number(filters.maxPrice) : globalMax}
                    onChange={(lo, hi) =>
                        setFilters((f) => ({
                            ...f,
                            minPrice: lo <= globalMin ? '' : String(lo),
                            maxPrice: hi >= globalMax ? '' : String(hi),
                        }))
                    }
                />
            </div>

            {/* Refundable only */}
            <div className="space-y-2">
                <label className="flex cursor-pointer items-center gap-2.5">
                    <input
                        type="checkbox"
                        checked={filters.refundableOnly}
                        onChange={(e) => setFilters((f) => ({ ...f, refundableOnly: e.target.checked }))}
                        className="size-4 rounded border-input accent-primary"
                    />
                    <span className="text-sm font-semibold">{t('common.filter_refundable_only')}</span>
                </label>
            </div>

            {/* Board type */}
            {boardTypes.length > 0 && (
                <div className="space-y-3">
                    <p className="text-sm font-semibold">{t('common.filter_board_type')}</p>
                    <div className="space-y-2">
                        {boardTypes.map((board) => (
                            <label key={board} className="flex cursor-pointer items-center gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={filters.boards.includes(board)}
                                    onChange={() => toggleBoard(board)}
                                    className="size-4 rounded border-input accent-primary"
                                />
                                <span className="text-sm">{board}</span>
                            </label>
                        ))}
                    </div>
                </div>
            )}
        </aside>
    );
};

// ─── Hotel card — Grid variant ────────────────────────────────────────────────

const HotelCardGrid = ({ hotel, hotelIndex, search, selectRoom, t }) => {
    const meta = getHotelMeta(hotel);
    const rating = getRating(hotel);
    const lowestRoom = getLowestAvailableRoom(hotel.rooms);
    const availableRoomsCount = getAvailableRoomsCount(hotel.rooms);
    const refundable = hasRefundableRate(hotel.rooms);
    const [modalOpen, setModalOpen] = React.useState(false);

    return (
        <>
            <Card
                key={`${hotel.hotel_id}-${hotel.source}-${hotelIndex}`}
                className="flex h-full flex-col overflow-hidden border shadow-sm transition-shadow hover:shadow-md"
            >
                {/* Image */}
                <div className="relative h-48 overflow-hidden bg-muted">
                    {meta.thumbImage ? (
                        <img
                            src={meta.thumbImage}
                            alt={hotel.name}
                            className="h-full w-full object-cover"
                            loading="lazy"
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center text-muted-foreground">
                            <ImageOff className="size-10" />
                        </div>
                    )}
                    {/* Price badge */}
                    <div className="absolute bottom-3 end-3 rounded-xl bg-white/95 px-3 py-1.5 text-right shadow dark:bg-slate-950/95">
                        <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground">
                            {t('common.from_price')}
                        </p>
                        <p className="text-base font-black text-primary">
                            {lowestRoom ? formatMoney(lowestRoom.price, lowestRoom.currency) : t('common.not_available')}
                        </p>
                    </div>
                    {/* Star rating badge (replaces supplier badge) */}
                    {rating > 0 && (
                        <div className="absolute start-3 top-3 flex items-center gap-1 rounded-full bg-black/60 px-2.5 py-1 text-amber-400">
                            {Array.from({ length: rating }).map((_, i) => (
                                <Star key={i} className="size-3 fill-current" />
                            ))}
                        </div>
                    )}
                </div>

                {/* Body */}
                <div className="flex flex-1 flex-col gap-3 p-4">
                    {/* Type badge */}
                    <div className="flex items-center justify-between gap-2">
                        <Badge variant="outline" className="gap-1 text-xs">
                            <HotelIcon className="size-3" />
                            {meta.hotelType || t('common.hotel')}
                        </Badge>
                        {/* Refund badge */}
                        {refundable ? (
                            <Badge variant="outline" className="gap-1 border-green-300 text-xs text-green-700 dark:border-green-700 dark:text-green-400">
                                <ShieldCheck className="size-3" />
                                {t('common.refundable')}
                            </Badge>
                        ) : (
                            <Badge variant="secondary" className="gap-1 text-xs">
                                <ShieldOff className="size-3" />
                                {t('common.non_refundable')}
                            </Badge>
                        )}
                    </div>

                    {/* Name + location */}
                    <div className="space-y-1">
                        <h2 className="line-clamp-2 font-black leading-snug tracking-tight">{hotel.name}</h2>
                        <p className="flex items-start gap-1 text-xs text-muted-foreground">
                            <MapPin className="mt-0.5 size-3.5 shrink-0" />
                            <span className="line-clamp-1">{getHotelLocation(hotel, search.city)}</span>
                        </p>
                    </div>

                    {/* Available rooms badge */}
                    <div>
                        <Badge variant={availableRoomsCount > 0 ? 'success' : 'destructive'} className="text-xs">
                            {availableRoomsCount > 0
                                ? t('common.available_rates_count', { count: availableRoomsCount })
                                : t('common.unavailable')}
                        </Badge>
                    </div>

                    {/* CTA */}
                    <div className="mt-auto pt-1">
                        <Button type="button" className="w-full" onClick={() => setModalOpen(true)}>
                            {t('common.see_availability')}
                        </Button>
                    </div>
                </div>
            </Card>

            {modalOpen && (
                <HotelInfoModal
                    hotel={hotel}
                    search={search}
                    selectRoom={selectRoom}
                    onClose={() => setModalOpen(false)}
                    t={t}
                />
            )}
        </>
    );
};

// ─── Hotel card — List variant ────────────────────────────────────────────────

const HotelCardList = ({ hotel, hotelIndex, search, selectRoom, t }) => {
    const meta = getHotelMeta(hotel);
    const rating = getRating(hotel);
    const lowestRoom = getLowestAvailableRoom(hotel.rooms);
    const availableRoomsCount = getAvailableRoomsCount(hotel.rooms);
    const refundable = hasRefundableRate(hotel.rooms);
    const [modalOpen, setModalOpen] = React.useState(false);

    return (
        <>
            <Card className="overflow-hidden border shadow-sm transition-shadow hover:shadow-md">
                <div className="flex gap-0">
                    {/* Image */}
                    <div className="relative h-auto w-48 shrink-0 overflow-hidden bg-muted sm:w-56">
                        {meta.thumbImage ? (
                            <img
                                src={meta.thumbImage}
                                alt={hotel.name}
                                className="h-full w-full object-cover"
                                loading="lazy"
                            />
                        ) : (
                            <div className="flex h-full min-h-40 items-center justify-center text-muted-foreground">
                                <ImageOff className="size-8" />
                            </div>
                        )}
                        {/* Star rating badge on image */}
                        {rating > 0 && (
                            <div className="absolute start-2 top-2 flex items-center gap-0.5 rounded-full bg-black/60 px-2 py-1 text-amber-400">
                                {Array.from({ length: rating }).map((_, i) => (
                                    <Star key={i} className="size-3 fill-current" />
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Content */}
                    <div className="flex flex-1 flex-col justify-between gap-3 p-4">
                        <div className="space-y-2">
                            {/* Type + refund */}
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline" className="gap-1 text-xs">
                                    <HotelIcon className="size-3" />
                                    {meta.hotelType || t('common.hotel')}
                                </Badge>
                                {refundable ? (
                                    <Badge variant="outline" className="gap-1 border-green-300 text-xs text-green-700 dark:border-green-700 dark:text-green-400">
                                        <ShieldCheck className="size-3" />
                                        {t('common.refundable')}
                                    </Badge>
                                ) : (
                                    <Badge variant="secondary" className="gap-1 text-xs">
                                        <ShieldOff className="size-3" />
                                        {t('common.non_refundable')}
                                    </Badge>
                                )}
                            </div>

                            <h2 className="line-clamp-1 text-lg font-black tracking-tight">{hotel.name}</h2>
                            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                <MapPin className="size-3.5 shrink-0" />
                                {getHotelLocation(hotel, search.city)}
                            </p>

                            <Badge variant={availableRoomsCount > 0 ? 'success' : 'destructive'} className="text-xs">
                                {availableRoomsCount > 0
                                    ? t('common.available_rates_count', { count: availableRoomsCount })
                                    : t('common.unavailable')}
                            </Badge>
                        </div>

                        {/* Price + CTA */}
                        <div className="flex items-end justify-between gap-4">
                            <div>
                                <p className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                    {t('common.from_price')}
                                </p>
                                <p className="text-xl font-black text-primary">
                                    {lowestRoom ? formatMoney(lowestRoom.price, lowestRoom.currency) : t('common.not_available')}
                                </p>
                            </div>
                            <Button type="button" onClick={() => setModalOpen(true)}>
                                {t('common.see_availability')}
                            </Button>
                        </div>
                    </div>
                </div>
            </Card>

            {modalOpen && (
                <HotelInfoModal
                    hotel={hotel}
                    search={search}
                    selectRoom={selectRoom}
                    onClose={() => setModalOpen(false)}
                    t={t}
                />
            )}
        </>
    );
};

// ─── Page ─────────────────────────────────────────────────────────────────────

const SORT_OPTIONS = [
    { value: 'price_asc',   labelKey: 'common.sort_price_asc' },
    { value: 'price_desc',  labelKey: 'common.sort_price_desc' },
    { value: 'stars_desc',  labelKey: 'common.sort_stars_desc' },
    { value: 'name_asc',    labelKey: 'common.sort_name_asc' },
];

const applySort = (hotels, sort) => {
    const sorted = [...hotels];
    switch (sort) {
        case 'price_desc':
            return sorted.sort((a, b) => {
                const pa = getLowestAvailableRoom(a.rooms)?.price ?? Infinity;
                const pb = getLowestAvailableRoom(b.rooms)?.price ?? Infinity;
                return Number(pb) - Number(pa);
            });
        case 'stars_desc':
            return sorted.sort((a, b) => getRating(b) - getRating(a));
        case 'name_asc':
            return sorted.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        case 'price_asc':
        default:
            return sorted.sort((a, b) => {
                const pa = getLowestAvailableRoom(a.rooms)?.price ?? Infinity;
                const pb = getLowestAvailableRoom(b.rooms)?.price ?? Infinity;
                return Number(pa) - Number(pb);
            });
    }
};

const applyFilters = (hotels, filters) => {
    return hotels.filter((hotel) => {
        const rating = getRating(hotel);
        const lowestRoom = getLowestAvailableRoom(hotel.rooms);
        const price = lowestRoom ? Number(lowestRoom.price) : null;

        // Star filter — compare against ratingId-based integer rating
        if (filters.stars.length > 0 && !filters.stars.includes(rating)) return false;

        // Price range
        if (filters.minPrice !== '' && price !== null && price < Number(filters.minPrice)) return false;
        if (filters.maxPrice !== '' && price !== null && price > Number(filters.maxPrice)) return false;

        // Refundable only
        if (filters.refundableOnly && !hasRefundableRate(hotel.rooms)) return false;

        // Board type
        if (filters.boards.length > 0) {
            const hotelBoards = (hotel.rooms || []).map((r) => r.board_name).filter(Boolean);
            if (!filters.boards.some((b) => hotelBoards.includes(b))) return false;
        }

        return true;
    });
};

export default function HotelResults({ searchUuid, search }) {
    const { t } = useTranslation();
    const tRef = React.useRef(t);
    const [hotels, setHotels] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [error, setError] = React.useState('');

    // UI state
    const [layout, setLayout] = React.useState('grid'); // 'grid' | 'list'
    const [sort, setSort] = React.useState('price_asc');
    const [mapOpen, setMapOpen] = React.useState(false);
    const [filters, setFilters] = React.useState({
        stars: [],
        refundableOnly: false,
        boards: [],
        minPrice: '',
        maxPrice: '',
    });

    React.useEffect(() => { tRef.current = t; }, [t]);

    React.useEffect(() => {
        const controller = new AbortController();
        const loadAvailability = async () => {
            setLoading(true);
            setError('');
            try {
                const response = await fetch(route('hotels.availability', searchUuid), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const payload = await response.json();
                if (controller.signal.aborted) return;
                if (!response.ok) {
                    setError(payload?.message || tRef.current('common.unable_to_load_hotels'));
                    setHotels([]);
                    return;
                }
                setHotels(Array.isArray(payload?.hotels) ? payload.hotels : []);
            } catch (fetchError) {
                if (!controller.signal.aborted && fetchError.name !== 'AbortError') {
                    setError(tRef.current('common.unable_to_load_hotels'));
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        };
        loadAvailability();
        return () => controller.abort();
    }, [searchUuid]);

    const selectRoom = (hotel, roomOrRooms) => {
        // roomOrRooms is either a single room object (legacy) or an array of rooms (multi-room)
        const rooms = Array.isArray(roomOrRooms) ? roomOrRooms : [roomOrRooms];
        const totalPrice = rooms.reduce((sum, r) => sum + Number(r.price), 0);
        const rateKeys = rooms.map((r) => r.rate_key);
        const firstRoom = rooms[0];

        router.post(route('hotels.select'), {
            search_uuid: searchUuid,
            hotel_id: String(hotel.hotel_id),
            hotel_uid: String(hotel.hotel_uid || ''),
            hotel_name: String(hotel.name || 'Hotel'),
            source: hotel.source,
            rate_keys: rateKeys,
            rate_key: rateKeys[0],
            room_name: rooms.map((r) => r.room_name).join(', '),
            board_name: rooms.map((r) => r.board_name).join(', '),
            price: Math.round(totalPrice * 100) / 100,
            currency: firstRoom.currency,
            available: rooms.every((r) => r.available),
            cancellation_policies: firstRoom.cancellation_policies || [],
            raw: { ...firstRoom.raw, search_code: firstRoom.search_code, selected_rooms: rooms },
        });
    };

    const filteredHotels = React.useMemo(
        () => applySort(applyFilters(hotels, filters), sort),
        [hotels, filters, sort],
    );

    const hasResults = !loading && !error && hotels.length > 0;

    return (
        <TenantNavbarLayout>
            <Head title={t('common.hotel_results')} />

            <div className="mx-auto max-w-7xl px-4 py-8">
                {/* Page header */}
                <div className="mb-6">
                    <Link
                        href={route('hotels.index')}
                        className="mb-3 flex items-center text-sm font-bold text-muted-foreground hover:text-primary"
                    >
                        <ChevronLeft className="mr-1 size-4" /> {t('common.back_to_hotel_search')}
                    </Link>
                    <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">{t('common.hotel_results')}</h1>
                            <p className="mt-1 text-muted-foreground">
                                {search.city} · {search.check_in} {t('common.to')} {search.check_out}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {hasResults && (
                                <Badge variant="success" className="w-fit">
                                    {t('common.hotel_offers_found', { count: hotels.length })}
                                </Badge>
                            )}
                            <Badge variant="secondary" className="w-fit">
                                {t('common.rooms_count', { count: search.rooms?.length || 1 })}
                            </Badge>
                        </div>
                    </div>
                </div>

                {/* Toolbar */}
                {hasResults && (
                    <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3">
                        {/* Left: result count + sort */}
                        <div className="flex items-center gap-3">
                            <span className="text-sm text-muted-foreground">
                                {t('common.showing_results', {
                                    shown: filteredHotels.length,
                                    total: hotels.length,
                                })}
                            </span>
                            <div className="flex items-center gap-1.5">
                                <label htmlFor="sort-select" className="text-sm font-semibold">
                                    {t('common.sort_by')}:
                                </label>
                                <select
                                    id="sort-select"
                                    value={sort}
                                    onChange={(e) => setSort(e.target.value)}
                                    className="rounded-md border border-input bg-background px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                >
                                    {SORT_OPTIONS.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {t(opt.labelKey)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* Right: layout + map */}
                        <div className="flex items-center gap-2">
                            <div className="flex items-center rounded-lg border bg-muted p-1">
                                <button
                                    onClick={() => setLayout('grid')}
                                    aria-label={t('common.layout_grid')}
                                    className={`rounded-md p-1.5 transition-colors ${
                                        layout === 'grid'
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    <LayoutGrid className="size-4" />
                                </button>
                                <button
                                    onClick={() => setLayout('list')}
                                    aria-label={t('common.layout_list')}
                                    className={`rounded-md p-1.5 transition-colors ${
                                        layout === 'list'
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    <List className="size-4" />
                                </button>
                            </div>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="gap-2"
                                onClick={() => setMapOpen(true)}
                            >
                                <MapIcon className="size-4" />
                                {t('common.view_on_map')}
                            </Button>
                        </div>
                    </div>
                )}

                {/* Loading */}
                {loading && (
                    <Card className="border-2 border-dashed">
                        <CardContent className="flex items-center gap-3 p-6 text-muted-foreground">
                            <LoaderCircle className="size-5 animate-spin" />
                            {t('common.loading_hotel_availability')}
                        </CardContent>
                    </Card>
                )}

                {/* Error */}
                {error && (
                    <p className="rounded-md border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
                        {error}
                    </p>
                )}

                {/* Empty */}
                {!loading && !error && hotels.length === 0 && (
                    <Card>
                        <CardContent className="p-6 text-muted-foreground">
                            {t('common.no_hotels_found_for_search')}
                        </CardContent>
                    </Card>
                )}

                {/* Results: sidebar + grid/list */}
                {hasResults && (
                    <div className="flex gap-6">
                        {/* Filter sidebar */}
                        <div className="hidden lg:block">
                            <FilterSidebar
                                hotels={hotels}
                                filters={filters}
                                setFilters={setFilters}
                                t={t}
                            />
                        </div>

                        {/* Hotel cards */}
                        <div className="min-w-0 flex-1">
                            {filteredHotels.length === 0 ? (
                                <Card>
                                    <CardContent className="p-6 text-center text-muted-foreground">
                                        <p className="font-semibold">{t('common.no_hotels_found_for_search')}</p>
                                        <button
                                            onClick={() =>
                                                setFilters({ stars: [], refundableOnly: false, boards: [], minPrice: '', maxPrice: '' })
                                            }
                                            className="mt-2 text-sm text-primary hover:underline"
                                        >
                                            {t('common.clear_filters')}
                                        </button>
                                    </CardContent>
                                </Card>
                            ) : layout === 'grid' ? (
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                                    {filteredHotels.map((hotel, i) => (
                                        <HotelCardGrid
                                            key={`${hotel.hotel_id}-${hotel.source}-${i}`}
                                            hotel={hotel}
                                            hotelIndex={i}
                                            search={search}
                                            selectRoom={selectRoom}
                                            t={t}
                                        />
                                    ))}
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    {filteredHotels.map((hotel, i) => (
                                        <HotelCardList
                                            key={`${hotel.hotel_id}-${hotel.source}-${i}`}
                                            hotel={hotel}
                                            hotelIndex={i}
                                            search={search}
                                            selectRoom={selectRoom}
                                            t={t}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Map modal */}
            {mapOpen && (
                <MapModal
                    hotels={filteredHotels}
                    search={search}
                    onClose={() => setMapOpen(false)}
                    t={t}
                />
            )}
        </TenantNavbarLayout>
    );
}
