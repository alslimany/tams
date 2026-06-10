import React, { useState, useEffect, useMemo, useRef } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import TenantNavbarLayout from "@/Layouts/TenantNavbarLayout";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/Components/ui/Card";
import { Label } from "@/Components/ui/Label";
import { Input } from "@/Components/ui/Input";
import { Button } from "@/Components/ui/Button";
import { AlertCircle, BedDouble, Building2, CalendarDays, CheckCircle2, ChevronLeft, Users } from "lucide-react";
import { useTranslation } from "@/hooks/useTranslation";

// ── module-level helpers ──────────────────────────────────────────────────────

const flagEmoji = (alpha2) => {
    if (!alpha2 || alpha2.length < 2) return "";
    const upper = alpha2.toUpperCase();
    return String.fromCodePoint(
        0x1f1e6 + upper.charCodeAt(0) - 65,
        0x1f1e6 + upper.charCodeAt(1) - 65,
    );
};

const getCountryLabel = (c, locale) => {
    if (locale === "ar" && c.name_ar) return c.name_ar;
    if (locale === "fr" && c.name_fr) return c.name_fr;
    return c.name_en;
};

/**
 * Country selector that stores the localized country name (not alpha3 code)
 * so the hotel API receives plain text.
 */
const HotelCountrySelect = ({ value, onChange, countries, locale, t, error }) => {
    const [query, setQuery] = useState("");
    const [open, setOpen] = useState(false);
    const [dropdownStyle, setDropdownStyle] = useState({});
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const inputRef = useRef(null);
    const containerRef = useRef(null);
    const listRef = useRef(null);

    // Match by stored name across all locales
    const selected = countries.find(
        (c) =>
            getCountryLabel(c, locale) === value ||
            c.name_en === value ||
            c.name_ar === value ||
            c.name_fr === value,
    );

    const displayValue = selected
        ? `${flagEmoji(selected.alpha2)} ${getCountryLabel(selected, locale)}`
        : value || "";

    const filtered = useMemo(() => {
        if (!query) return countries;
        const q = query.toLowerCase();
        return countries.filter(
            (c) =>
                (c.alpha3 || "").toLowerCase().includes(q) ||
                (c.alpha2 || "").toLowerCase().includes(q) ||
                (c.name_en || "").toLowerCase().includes(q) ||
                (c.name_ar || "").includes(q) ||
                (c.name_fr || "").toLowerCase().includes(q),
        );
    }, [query, countries]);

    useEffect(() => {
        setHighlightedIndex(-1);
    }, [filtered]);

    useEffect(() => {
        if (highlightedIndex < 0 || !listRef.current) return;
        listRef.current.children[highlightedIndex]?.scrollIntoView({ block: "nearest" });
    }, [highlightedIndex]);

    const updateDropdownPosition = () => {
        if (!inputRef.current) return;
        const rect = inputRef.current.getBoundingClientRect();
        setDropdownStyle({ position: "fixed", top: rect.bottom + 4, left: rect.left, width: rect.width, zIndex: 9999 });
    };

    const closeDropdown = () => {
        setOpen(false);
        setQuery("");
        setHighlightedIndex(-1);
    };

    useEffect(() => {
        const handler = (e) => {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                closeDropdown();
            }
        };
        document.addEventListener("mousedown", handler);
        return () => document.removeEventListener("mousedown", handler);
    }, []);

    const selectCountry = (c) => {
        onChange(getCountryLabel(c, locale));
        closeDropdown();
    };

    const handleKeyDown = (e) => {
        if (!open) return;
        if (e.key === "ArrowDown") {
            e.preventDefault();
            setHighlightedIndex((i) => Math.min(i + 1, filtered.length - 1));
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            setHighlightedIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === "Enter") {
            e.preventDefault();
            if (highlightedIndex >= 0 && filtered[highlightedIndex]) {
                selectCountry(filtered[highlightedIndex]);
            }
        } else if (e.key === "Escape") {
            e.preventDefault();
            closeDropdown();
        }
    };

    return (
        <div ref={containerRef} className="relative">
            <Input
                ref={inputRef}
                placeholder={`${t("common.search")} ${t("common.country")}…`}
                value={open ? query : displayValue}
                className={error ? "border-destructive" : ""}
                onFocus={() => {
                    updateDropdownPosition();
                    setOpen(true);
                    setQuery("");
                }}
                onChange={(e) => {
                    setQuery(e.target.value);
                    updateDropdownPosition();
                }}
                onKeyDown={handleKeyDown}
                autoComplete="off"
            />
            {open && (
                <ul
                    ref={listRef}
                    style={dropdownStyle}
                    className="max-h-52 overflow-y-auto rounded-md border bg-popover text-sm shadow-md"
                >
                    {filtered.length === 0 && (
                        <li className="px-3 py-2 text-muted-foreground">{t("common.no_results")}</li>
                    )}
                    {filtered.map((c, idx) => (
                        <li
                            key={c.alpha3}
                            className={`flex cursor-pointer items-center gap-2 px-3 py-2 hover:bg-accent hover:text-accent-foreground ${getCountryLabel(c, locale) === value ? "bg-accent font-semibold" : ""} ${idx === highlightedIndex ? "bg-accent text-accent-foreground" : ""}`}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                selectCountry(c);
                            }}
                        >
                            <span className="text-base leading-none">{flagEmoji(c.alpha2)}</span>
                            <span className="w-10 shrink-0 font-mono text-xs text-muted-foreground">{c.alpha3?.toUpperCase()}</span>
                            <span>{getCountryLabel(c, locale)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
};

/** Dial codes map: alpha2 (lowercase) → E.164 prefix */
const DIAL_CODES = {
    af: '+93', al: '+355', dz: '+213', ad: '+376', ao: '+244', ag: '+1-268',
    ar: '+54', am: '+374', au: '+61', at: '+43', az: '+994', bs: '+1-242',
    bh: '+973', bd: '+880', bb: '+1-246', by: '+375', be: '+32', bz: '+501',
    bj: '+229', bt: '+975', bo: '+591', ba: '+387', bw: '+267', br: '+55',
    bn: '+673', bg: '+359', bf: '+226', bi: '+257', cv: '+238', kh: '+855',
    cm: '+237', ca: '+1', cf: '+236', td: '+235', cl: '+56', cn: '+86',
    co: '+57', km: '+269', cg: '+242', cd: '+243', cr: '+506', ci: '+225',
    hr: '+385', cu: '+53', cy: '+357', cz: '+420', dk: '+45', dj: '+253',
    dm: '+1-767', do: '+1-809', ec: '+593', eg: '+20', sv: '+503', gq: '+240',
    er: '+291', ee: '+372', sz: '+268', et: '+251', fj: '+679', fi: '+358',
    fr: '+33', ga: '+241', gm: '+220', ge: '+995', de: '+49', gh: '+233',
    gr: '+30', gd: '+1-473', gt: '+502', gn: '+224', gw: '+245', gy: '+592',
    ht: '+509', hn: '+504', hu: '+36', is: '+354', in: '+91', id: '+62',
    ir: '+98', iq: '+964', ie: '+353', il: '+972', it: '+39', jm: '+1-876',
    jp: '+81', jo: '+962', kz: '+7', ke: '+254', ki: '+686', kp: '+850',
    kr: '+82', kw: '+965', kg: '+996', la: '+856', lv: '+371', lb: '+961',
    ls: '+266', lr: '+231', ly: '+218', li: '+423', lt: '+370', lu: '+352',
    mg: '+261', mw: '+265', my: '+60', mv: '+960', ml: '+223', mt: '+356',
    mh: '+692', mr: '+222', mu: '+230', mx: '+52', fm: '+691', md: '+373',
    mc: '+377', mn: '+976', me: '+382', ma: '+212', mz: '+258', mm: '+95',
    na: '+264', nr: '+674', np: '+977', nl: '+31', nz: '+64', ni: '+505',
    ne: '+227', ng: '+234', no: '+47', om: '+968', pk: '+92', pw: '+680',
    pa: '+507', pg: '+675', py: '+595', pe: '+51', ph: '+63', pl: '+48',
    pt: '+351', qa: '+974', ro: '+40', ru: '+7', rw: '+250', kn: '+1-869',
    lc: '+1-758', vc: '+1-784', ws: '+685', sm: '+378', st: '+239',
    sa: '+966', sn: '+221', rs: '+381', sc: '+248', sl: '+232', sg: '+65',
    sk: '+421', si: '+386', sb: '+677', so: '+252', za: '+27', ss: '+211',
    es: '+34', lk: '+94', sd: '+249', sr: '+597', se: '+46', ch: '+41',
    sy: '+963', tw: '+886', tj: '+992', tz: '+255', th: '+66', tl: '+670',
    tg: '+228', to: '+676', tt: '+1-868', tn: '+216', tr: '+90', tm: '+993',
    ug: '+256', ua: '+380', ae: '+971', gb: '+44', us: '+1', uy: '+598',
    uz: '+998', vu: '+678', ve: '+58', vn: '+84', ye: '+967', zm: '+260',
    zw: '+263',
};

/**
 * Normalise a raw local phone string: strips country prefix, leading zeros,
 * and formatting chars (spaces, dashes) — returns clean local digits only.
 */
const normalizeLocalNumber = (dialCode, raw) => {
    if (!raw) return "";
    let digits = raw.replace(/[^\d]/g, "");
    const codeDigits = dialCode.replace(/[^\d]/g, "");
    if (digits.startsWith(codeDigits)) {
        digits = digits.slice(codeDigits.length);
    }
    digits = digits.replace(/^0+/, "");
    return digits;
};

/**
 * Module-level: phone input with searchable dial-code dropdown.
 * Rendered as a single visually joined h-9 input: [flag+code button | number field].
 */
const PhoneInput = ({ value, onChange, required, countries, locale, t, error }) => {
    const parseValue = (v) => {
        if (!v) return { dialCode: "+218", number: "" };
        const sorted = Object.entries(DIAL_CODES).sort((a, b) => b[1].length - a[1].length);
        for (const [, code] of sorted) {
            if (v.startsWith(code)) {
                return { dialCode: code, number: v.slice(code.length) };
            }
        }
        return { dialCode: "+218", number: v };
    };

    const [dialCode, setDialCode] = useState(() => parseValue(value).dialCode);
    const [number, setNumber] = useState(() => parseValue(value).number);
    const [query, setQuery] = useState("");
    const [open, setOpen] = useState(false);
    const [dropdownStyle, setDropdownStyle] = useState({});
    const triggerRef = useRef(null);
    const containerRef = useRef(null);

    useEffect(() => {
        const { dialCode: d, number: n } = parseValue(value);
        setDialCode(d);
        setNumber(n);
    }, [value]);

    const countriesWithDial = useMemo(
        () =>
            countries
                .filter((c) => DIAL_CODES[c.alpha2])
                .map((c) => ({ ...c, dialCode: DIAL_CODES[c.alpha2] }))
                .sort((a, b) => getCountryLabel(a, locale).localeCompare(getCountryLabel(b, locale))),
        [countries, locale],
    );

    const filteredDial = useMemo(() => {
        if (!query) return countriesWithDial;
        const q = query.toLowerCase();
        return countriesWithDial.filter(
            (c) =>
                (c.alpha2 || "").includes(q) ||
                c.dialCode.includes(q) ||
                (c.name_en || "").toLowerCase().includes(q) ||
                (c.name_ar || "").includes(q) ||
                (c.name_fr || "").toLowerCase().includes(q),
        );
    }, [query, countriesWithDial]);

    const selectedCountry = countriesWithDial.find((c) => c.dialCode === dialCode);

    const updateDropdownPosition = () => {
        if (!triggerRef.current) return;
        const rect = triggerRef.current.getBoundingClientRect();
        setDropdownStyle({
            position: "fixed",
            top: rect.bottom + 4,
            left: rect.left,
            width: Math.max(rect.width, 240),
            zIndex: 9999,
        });
    };

    useEffect(() => {
        const handler = (e) => {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
                setQuery("");
            }
        };
        document.addEventListener("mousedown", handler);
        return () => document.removeEventListener("mousedown", handler);
    }, []);

    const selectDial = (code) => {
        setDialCode(code);
        setOpen(false);
        setQuery("");
        onChange(number ? code + number : "");
    };

    const handleNumber = (v) => {
        const cleaned = v.replace(/[^\d\s\-]/g, "");
        setNumber(cleaned);
        onChange(cleaned ? dialCode + cleaned : "");
    };

    const handleNumberBlur = () => {
        const normalized = normalizeLocalNumber(dialCode, number);
        if (normalized !== number) {
            setNumber(normalized);
            onChange(normalized ? dialCode + normalized : "");
        }
    };

    return (
        <div ref={containerRef} dir="ltr" className="relative">
            <div className={`flex h-9 overflow-hidden rounded-md border ${error ? "border-destructive" : "border-input"} bg-background`}>
                <button
                    ref={triggerRef}
                    type="button"
                    className="flex h-full shrink-0 items-center gap-1 border-r border-input bg-muted/40 px-2.5 text-sm transition-colors hover:bg-accent focus:outline-none"
                    onClick={() => {
                        updateDropdownPosition();
                        setOpen((o) => !o);
                        setQuery("");
                    }}
                >
                    <span className="text-base leading-none">{selectedCountry ? flagEmoji(selectedCountry.alpha2) : "🌐"}</span>
                    <span className="font-mono text-xs font-semibold">{dialCode}</span>
                </button>
                <input
                    required={required}
                    type="tel"
                    className="h-full min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none placeholder:text-muted-foreground/60"
                    placeholder="912345678"
                    value={number}
                    onChange={(e) => handleNumber(e.target.value)}
                    onBlur={handleNumberBlur}
                    autoComplete="tel-national"
                />
            </div>
            {open && (
                <div style={dropdownStyle} className="rounded-md border bg-popover shadow-md">
                    <div className="border-b p-2">
                        <input
                            autoFocus
                            className="w-full rounded border border-input bg-background px-2 py-1.5 text-sm outline-none"
                            placeholder={`${t("common.search")}…`}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </div>
                    <ul className="max-h-48 overflow-y-auto text-sm">
                        {filteredDial.length === 0 && (
                            <li className="px-3 py-2 text-muted-foreground">{t("common.no_results")}</li>
                        )}
                        {filteredDial.map((c) => (
                            <li
                                key={c.alpha2}
                                className={`flex cursor-pointer items-center gap-2 px-3 py-2 hover:bg-accent hover:text-accent-foreground ${dialCode === c.dialCode && selectedCountry?.alpha2 === c.alpha2 ? "bg-accent font-semibold" : ""}`}
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    selectDial(c.dialCode);
                                }}
                            >
                                <span className="text-base leading-none">{flagEmoji(c.alpha2)}</span>
                                <span className="w-10 shrink-0 font-mono text-xs text-muted-foreground">{c.dialCode}</span>
                                <span className="truncate">{getCountryLabel(c, locale)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
};

// ── helpers ───────────────────────────────────────────────────────────────────

function paxCountFromSearch(search, rateKeys) {
    const rooms = Array.isArray(search?.rooms) ? search.rooms : [];

    return rooms.map((room, index) => {
        const adults = Number(room?.adult || 1);
        const children = Array.isArray(room?.children) ? room.children : [];

        return {
            rate_key: rateKeys?.[index] ?? rateKeys?.[0] ?? "",
            paxes: [
                ...Array.from({ length: adults }, () => ({
                    civility: "Mr",
                    first_name: "",
                    last_name: "",
                    age: "",
                })),
                ...children.map((age) => ({
                    civility: "Enf",
                    first_name: "",
                    last_name: "",
                    age: Number(age || 0),
                })),
            ],
        };
    });
}

function nightsCount(checkIn, checkOut) {
    if (!checkIn || !checkOut) return 0;
    const d1 = new Date(checkIn);
    const d2 = new Date(checkOut);
    return Math.max(0, Math.round((d2 - d1) / (1000 * 60 * 60 * 24)));
}

// ── component ─────────────────────────────────────────────────────────────────

export default function HotelDetails({ bookingUuid, search, selectedOffer, rateKeys, civilityOptions = [], countries = [] }) {
    const { props } = usePage();
    const flash = props.flash ?? {};
    const locale = props.locale || "en";
    const { t, getCurrencyName } = useTranslation();
    const [activeStep, setActiveStep] = React.useState("details");
    const [showErrors, setShowErrors] = useState(false);
    const currency = selectedOffer?.currency || "USD";
    const currencyLabel = getCurrencyName(currency) || currency;

    const form = useForm({
        booking_uuid: bookingUuid,
        recommandations: "",
        customer: {
            first_name: "",
            last_name: "",
            email: "",
            mobile: "",
            country: "",
            city: "",
        },
        rooms: paxCountFromSearch(search, rateKeys),
    });

    // Sync city = country on every country change
    const setCustomer = (key, value) => {
        if (key === "country") {
            form.setData("customer", { ...form.data.customer, country: value, city: value });
        } else {
            form.setData("customer", { ...form.data.customer, [key]: value });
        }
    };

    const updatePax = (roomIndex, paxIndex, key, value) => {
        const newRooms = form.data.rooms.map((room, ri) => {
            if (ri !== roomIndex) {
                return room;
            }

            return {
                ...room,
                paxes: room.paxes.map((pax, pi) => {
                    if (pi !== paxIndex) {
                        return pax;
                    }

                    return { ...pax, [key]: value };
                }),
            };
        });

        form.setData("rooms", newRooms);
    };

    // Split-input name handler — auto-syncs customer name from first guest
    const updatePaxName = (roomIndex, paxIndex, field, value) => {
        const newRooms = form.data.rooms.map((room, ri) => {
            if (ri !== roomIndex) {
                return room;
            }

            return {
                ...room,
                paxes: room.paxes.map((pax, pi) => {
                    if (pi !== paxIndex) {
                        return pax;
                    }

                    return { ...pax, [field]: value };
                }),
            };
        });

        if (roomIndex === 0 && paxIndex === 0) {
            form.setData({
                ...form.data,
                rooms: newRooms,
                customer: { ...form.data.customer, [field]: value },
            });
        } else {
            form.setData("rooms", newRooms);
        }
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route("hotels.book"));
    };

    // Jump back to details step when server validation errors arrive
    useEffect(() => {
        if (Object.keys(form.errors).length > 0) {
            setActiveStep("details");
        }
    }, [form.errors]);

    const handleContinue = () => {
        setShowErrors(true);
        const allPaxFilled = form.data.rooms.every((room) =>
            room.paxes.every((pax) => pax.first_name.trim() && pax.last_name.trim()),
        );
        const contactFilled =
            form.data.customer.email.trim() &&
            form.data.customer.mobile.trim() &&
            form.data.customer.country.trim();
        if (allPaxFilled && contactFilled) {
            setActiveStep("confirm");
        }
    };

    const selectedRateKeys = Array.isArray(rateKeys) ? rateKeys : [];
    const checkRateRooms = Array.isArray(selectedOffer?.check_rate_rooms)
        ? selectedOffer.check_rate_rooms
        : [];

    const matchedRooms = selectedRateKeys.length > 0
        ? selectedRateKeys.map((key, idx) => {
              const match = checkRateRooms.find(
                  (r) => (r.rateKey ?? r.ratekey ?? "") === key,
              );
              return match ?? checkRateRooms[idx] ?? null;
          }).filter(Boolean)
        : checkRateRooms.slice(0, 1);

    const nights = nightsCount(search?.check_in, search?.check_out);

    const reservationRooms = matchedRooms.length > 0
        ? matchedRooms
        : [{ name: selectedOffer?.room_name, boardName: selectedOffer?.board_name }];

    const defaultCivilityOptions = civilityOptions.length > 0
        ? civilityOptions
        : [
              { value: "Mr", label: "Mr" },
              { value: "Mme", label: "Mme" },
              { value: "Mlle", label: "Mlle" },
              { value: "Enf", label: "Enf" },
          ];

    return (
        <TenantNavbarLayout>
            <Head title={t("common.complete_hotel_booking")} />

            {/* Hero */}
            <div className="bg-primary px-6 py-10 text-primary-foreground">
                <div className="mx-auto max-w-7xl">
                    <Link
                        href={route("hotels.results", bookingUuid)}
                        className="mb-4 inline-flex items-center gap-1 text-sm text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                    >
                        <ChevronLeft className="h-4 w-4" />
                        {t("common.back_to_hotel_results")}
                    </Link>
                    <h1 className="text-2xl font-bold">
                        {t("common.complete_hotel_booking")}
                    </h1>
                    <p className="mt-1 text-sm text-primary-foreground/70">
                        {t("common.fill_customer_guest_details")}
                    </p>

                    {/* Step indicator */}
                    <div className="mt-6 flex items-center gap-3">
                        <div className={`flex items-center gap-2 text-sm font-medium ${activeStep === "details" ? "text-primary-foreground" : "text-primary-foreground/50"}`}>
                            <span className={`flex h-6 w-6 items-center justify-center rounded-full border-2 text-xs font-bold ${activeStep === "details" ? "border-primary-foreground bg-primary-foreground text-primary" : "border-primary-foreground/50 text-primary-foreground/50"}`}>
                                {activeStep === "confirm" ? <CheckCircle2 className="h-3.5 w-3.5" /> : "1"}
                            </span>
                            {t("common.guest_details_tab")}
                        </div>
                        <div className="h-px w-8 bg-primary-foreground/30" />
                        <div className={`flex items-center gap-2 text-sm font-medium ${activeStep === "confirm" ? "text-primary-foreground" : "text-primary-foreground/50"}`}>
                            <span className={`flex h-6 w-6 items-center justify-center rounded-full border-2 text-xs font-bold ${activeStep === "confirm" ? "border-primary-foreground bg-primary-foreground text-primary" : "border-primary-foreground/50 text-primary-foreground/50"}`}>
                                2
                            </span>
                            {t("common.confirm_book_tab")}
                        </div>
                    </div>
                </div>
            </div>

            <div className="mx-auto max-w-7xl px-4 py-8">
                {flash.error && (
                    <div className="mb-6 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                        <AlertCircle className="mt-0.5 h-5 w-5 shrink-0" />
                        <p className="text-sm font-medium">{flash.error}</p>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
                    {/* Main form */}
                    <div className="lg:col-span-2">
                        <form className="space-y-6" onSubmit={submit}>
                            {activeStep === "details" && (
                                <>
                                    {/* Room cards */}
                                    {form.data.rooms.map((room, roomIndex) => (
                                        <Card key={roomIndex} className="border-2 shadow-sm">
                                            <CardHeader className="border-b bg-muted/10 pb-4">
                                                <CardTitle className="flex items-center gap-2">
                                                    <div className="rounded-full bg-primary/10 p-2">
                                                        <BedDouble className="h-5 w-5 text-primary" />
                                                    </div>
                                                    {t("common.room_number", { number: roomIndex + 1 })}
                                                </CardTitle>
                                                <CardDescription>
                                                    {matchedRooms[roomIndex]?.name ?? selectedOffer?.room_name}
                                                    {(matchedRooms[roomIndex]?.boardName ?? selectedOffer?.board_name)
                                                        ? ` · ${matchedRooms[roomIndex]?.boardName ?? selectedOffer?.board_name}`
                                                        : ""}
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent className="space-y-3 pt-5">
                                                {room.paxes.map((pax, paxIndex) => (
                                                    <div
                                                        key={paxIndex}
                                                        className="grid gap-3 rounded-md border bg-background p-3 grid-cols-1 md:grid-cols-12"
                                                    >
                                                        {/* Civility */}
                                                        <div className="space-y-1.5 md:col-span-2">
                                                            <Label className="text-xs">{t("common.civility")}</Label>
                                                            <select
                                                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                                value={pax.civility}
                                                                onChange={(e) =>
                                                                    updatePax(roomIndex, paxIndex, "civility", e.target.value)
                                                                }
                                                            >
                                                                {defaultCivilityOptions.map((opt) => (
                                                                    <option key={opt.value} value={opt.value}>
                                                                        {t("common." + opt.value.toLowerCase()) || opt.label}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </div>

                                                        {/* Split name input */}
                                                        <div className={`space-y-1.5 ${pax.civility === "Enf" ? "md:col-span-8" : "md:col-span-10"}`}>
                                                            <Label className="text-xs">{t("common.name")}</Label>
                                                            <div className={`flex h-9 overflow-hidden rounded-md border bg-background ${
                                                                (showErrors && (!pax.first_name.trim() || !pax.last_name.trim())) ||
                                                                form.errors[`rooms.${roomIndex}.paxes.${paxIndex}.first_name`] ||
                                                                form.errors[`rooms.${roomIndex}.paxes.${paxIndex}.last_name`]
                                                                    ? "border-destructive"
                                                                    : "border-input"
                                                            }`}>
                                                                <input
                                                                    placeholder={t("common.first_name")}
                                                                    value={pax.first_name}
                                                                    onChange={(e) =>
                                                                        updatePaxName(roomIndex, paxIndex, "first_name", e.target.value)
                                                                    }
                                                                    className="h-full min-w-0 flex-1 border-r border-input bg-transparent px-2.5 text-sm outline-none placeholder:text-muted-foreground/60"
                                                                />
                                                                <input
                                                                    placeholder={t("common.last_name")}
                                                                    value={pax.last_name}
                                                                    onChange={(e) =>
                                                                        updatePaxName(roomIndex, paxIndex, "last_name", e.target.value)
                                                                    }
                                                                    className="h-full min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none placeholder:text-muted-foreground/60"
                                                                />
                                                            </div>
                                                            {showErrors && (!pax.first_name.trim() || !pax.last_name.trim()) && (
                                                                <p className="text-xs text-destructive">
                                                                    {!pax.first_name.trim() && !pax.last_name.trim()
                                                                        ? t("common.passenger_name_required")
                                                                        : !pax.first_name.trim()
                                                                        ? t("common.first_name_required")
                                                                        : t("common.last_name_required")}
                                                                </p>
                                                            )}
                                                            {(form.errors[`rooms.${roomIndex}.paxes.${paxIndex}.first_name`] || form.errors[`rooms.${roomIndex}.paxes.${paxIndex}.last_name`]) && (
                                                                <p className="text-xs text-destructive">
                                                                    {form.errors[`rooms.${roomIndex}.paxes.${paxIndex}.first_name`] || form.errors[`rooms.${roomIndex}.paxes.${paxIndex}.last_name`]}
                                                                </p>
                                                            )}
                                                        </div>

                                                        {/* Age — children only */}
                                                        {pax.civility === "Enf" && (
                                                            <div className="space-y-1.5 md:col-span-2">
                                                                <Label className="text-xs">{t("common.age")}</Label>
                                                                <Input
                                                                    type="number"
                                                                    min="0"
                                                                    max="17"
                                                                    value={pax.age}
                                                                    className="bg-background"
                                                                    onChange={(e) =>
                                                                        updatePax(roomIndex, paxIndex, "age", e.target.value)
                                                                    }
                                                                />
                                                            </div>
                                                        )}
                                                    </div>
                                                ))}
                                            </CardContent>
                                        </Card>
                                    ))}

                                    {/* Contact Information */}
                                    <Card className="border-2 shadow-sm">
                                        <CardHeader className="border-b bg-muted/10 pb-4">
                                            <CardTitle className="flex items-center gap-2">
                                                <div className="rounded-full bg-primary/10 p-2">
                                                    <Users className="h-5 w-5 text-primary" />
                                                </div>
                                                {t("common.contact_information")}
                                            </CardTitle>
                                            <CardDescription>
                                                {t("common.enter_email_phone_primary_contact")}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="pt-6">
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>{t("common.email")}</Label>
                                                    <Input
                                                        type="email"
                                                        value={form.data.customer.email}
                                                        onChange={(e) => setCustomer("email", e.target.value)}
                                                        className={form.errors["customer.email"] || (showErrors && !form.data.customer.email.trim()) ? "border-destructive" : ""}
                                                    />
                                                    {(form.errors["customer.email"] || (showErrors && !form.data.customer.email.trim())) && (
                                                        <p className="text-xs text-destructive">
                                                            {form.errors["customer.email"] || t("common.email_required")}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>{t("common.mobile")}</Label>
                                                    <PhoneInput
                                                        value={form.data.customer.mobile}
                                                        onChange={(v) => setCustomer("mobile", v)}
                                                        countries={countries}
                                                        locale={locale}
                                                        t={t}
                                                        error={!!form.errors["customer.mobile"] || (showErrors && !form.data.customer.mobile.trim())}
                                                    />
                                                    {(form.errors["customer.mobile"] || (showErrors && !form.data.customer.mobile.trim())) && (
                                                        <p className="text-xs text-destructive">
                                                            {form.errors["customer.mobile"] || t("common.phone_required")}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="space-y-2 md:col-span-2">
                                                    <Label>{t("common.country")}</Label>
                                                    <HotelCountrySelect
                                                        value={form.data.customer.country}
                                                        onChange={(name) => setCustomer("country", name)}
                                                        countries={countries}
                                                        locale={locale}
                                                        t={t}
                                                        error={!!form.errors["customer.country"] || (showErrors && !form.data.customer.country.trim())}
                                                    />
                                                    {(form.errors["customer.country"] || (showErrors && !form.data.customer.country.trim())) && (
                                                        <p className="text-xs text-destructive">
                                                            {form.errors["customer.country"] || t("common.required")}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="space-y-2 md:col-span-2">
                                                    <Label>{t("common.recommendations_notes")}</Label>
                                                    <Input
                                                        value={form.data.recommandations}
                                                        onChange={(e) => form.setData("recommandations", e.target.value)}
                                                        placeholder={t("common.late_arrival_hint")}
                                                    />
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            size="lg"
                                             className="rounded-full px-10 font-black shadow-md"
                                             onClick={() => handleContinue()}
                                         >
                                            {t("common.continue_to_review")}
                                        </Button>
                                    </div>
                                </>
                            )}

                            {activeStep === "confirm" && (
                                <>
                                    <div className="rounded-xl border bg-muted/10 p-5">
                                        <p className="mb-3 text-xs font-black uppercase tracking-widest text-primary">
                                            {t("common.review_details")}
                                        </p>
                                        <div className="grid gap-4 text-sm md:grid-cols-2">
                                            <div>
                                                <p className="text-muted-foreground">{t("common.customer")}</p>
                                                <p className="font-bold">
                                                    {form.data.customer.first_name}{" "}
                                                    {form.data.customer.last_name}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">{t("common.email")}</p>
                                                <p className="font-bold">{form.data.customer.email}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">{t("common.hotel")}</p>
                                                <p className="font-bold">{selectedOffer?.hotel_name}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">{t("common.room")}</p>
                                                <p className="font-bold">{selectedOffer?.room_name}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">{t("common.coverage")}</p>
                                                <p className="font-bold">
                                                    {search?.check_in} — {search?.check_out}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">{t("common.guests")}</p>
                                                <p className="font-bold">
                                                    {form.data.rooms.reduce(
                                                        (total, room) => total + room.paxes.length,
                                                        0,
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between border-t pt-6">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="font-bold"
                                            onClick={() => setActiveStep("details")}
                                        >
                                            <ChevronLeft className="mr-2 h-4 w-4" />
                                            {t("common.back")}
                                        </Button>
                                        <Button
                                            type="submit"
                                            size="lg"
                                            className="rounded-full px-12 text-lg font-black shadow-xl"
                                            disabled={form.processing}
                                        >
                                            {form.processing
                                                ? t("common.booking_hotel")
                                                : t("common.confirm_book_hotel")}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </form>
                    </div>

                    {/* Offer summary sidebar */}
                    <div className="hidden lg:block">
                        <div className="sticky top-8">
                            <Card className="overflow-hidden border-2 shadow-lg">
                                {/* Header — matches flight trip summary style */}
                                <div className="bg-primary p-6 text-primary-foreground">
                                    <h3 className="text-xl font-black">{t("common.offer_summary")}</h3>
                                </div>
                                <CardContent className="p-0">
                                     {/* Reservation info */}
                                     <div className="space-y-4 border-b p-6">
                                         <div className="space-y-1.5">
                                             <div className="flex items-start gap-2 text-sm">
                                                 <Building2 className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                                 <span className="font-bold">{selectedOffer?.hotel_name}</span>
                                             </div>
                                             <div className="flex items-start gap-2 text-sm">
                                                 <CalendarDays className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                                 <span className="font-bold">
                                                     {nights > 0 ? `${t("orders.nights_count", { count: nights })} ` : ""}
                                                     {t("common.starting_from").toLowerCase()} {search?.check_in}
                                                 </span>
                                             </div>
                                         </div>
                                         <div className="space-y-2">
                                             {reservationRooms.map((room, index) => (
                                                 <div
                                                     key={room?.rateKey ?? room?.ratekey ?? index}
                                                     className="rounded-lg border bg-muted/20 px-4 py-3"
                                                 >
                                                     <p className="text-sm font-bold">
                                                         {t("common.room_number", { number: index + 1 })}: {room?.name ?? selectedOffer?.room_name ?? "-"}
                                                     </p>
                                                     <p className="text-xs text-muted-foreground">
                                                         {room?.boardName ?? room?.boardCode ?? selectedOffer?.board_name ?? "-"}
                                                     </p>
                                                     {matchedRooms[index] && (
                                                         <div className="mt-2 space-y-0.5 border-t pt-2 text-xs text-muted-foreground">
                                                             <p>
                                                                 {t("common.no_show")}: {Number(matchedRooms[index].noShow ?? 0).toFixed(2)}{" "}
                                                                 {matchedRooms[index].currency ? getCurrencyName(matchedRooms[index].currency) || matchedRooms[index].currency : currencyLabel}
                                                             </p>
                                                             {Array.isArray(matchedRooms[index].cancellationPolicies) &&
                                                                 matchedRooms[index].cancellationPolicies.length > 0 && (
                                                                     <p>
                                                                         {t("common.cancellation_from").replace(":date", matchedRooms[index].cancellationPolicies[0]?.from ?? "-")}:{" "}
                                                                         {Number(matchedRooms[index].cancellationPolicies[0]?.amount ?? 0).toFixed(2)}{" "}
                                                                         {matchedRooms[index].currency ? getCurrencyName(matchedRooms[index].currency) || matchedRooms[index].currency : currencyLabel}
                                                                     </p>
                                                                 )}
                                                         </div>
                                                     )}
                                                 </div>
                                             ))}
                                         </div>
                                     </div>

                                    {/* Pricing */}
                                    <div className="space-y-3 p-6">
                                        <div className="flex justify-between text-sm font-bold">
                                            <span className="text-muted-foreground">{t("common.base_fare")}</span>
                                            <span>
                                                {Number(selectedOffer?.provider_price ?? selectedOffer?.price ?? 0).toFixed(2)}{" "}
                                                {currencyLabel}
                                            </span>
                                        </div>
                                        <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                            <span>{t("common.markup_profit")}</span>
                                            <span>
                                                {Number(selectedOffer?.markup_amount ?? 0).toFixed(2)} {currencyLabel}
                                            </span>
                                        </div>
                                        <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                            <span>{t("common.taxes")}</span>
                                            <span>0.00 {currencyLabel}</span>
                                        </div>
                                    </div>

                                    <div className="flex items-end justify-between border-t bg-muted/30 p-6">
                                        <span className="font-bold text-muted-foreground">
                                            {t("common.total_to_pay")}
                                        </span>
                                        <div className="text-right">
                                            <p className="text-3xl font-black tracking-tight text-primary">
                                                {Number(selectedOffer?.price ?? 0).toFixed(2)}
                                            </p>
                                            <p className="text-xs font-black uppercase tracking-widest text-muted-foreground">
                                                {currencyLabel}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </TenantNavbarLayout>
    );
}
