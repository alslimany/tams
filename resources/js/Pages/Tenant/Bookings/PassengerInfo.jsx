import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { useTranslation } from '@/hooks/useTranslation';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/Tabs';
import { Armchair, Briefcase, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Loader2, Plane, Plus, ScanLine, Settings2, Smartphone, Upload, Users } from 'lucide-react';

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

/** Convert ISO alpha2 to flag emoji using Unicode regional indicator codepoints. */
const flagEmoji = (alpha2) => {
    if (!alpha2 || alpha2.length < 2) return '';
    const upper = alpha2.toUpperCase();
    return String.fromCodePoint(
        0x1f1e6 + upper.charCodeAt(0) - 65,
        0x1f1e6 + upper.charCodeAt(1) - 65,
    );
};

/**
 * Module-level: generic document scan modal.
 * Auto-scans immediately when the user picks a file — no manual scan button.
 * Used for both passport and visa scanning.
 */
function DocumentScanModal({ open, onOpenChange, onSuccess, title, description, t }) {
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const inputRef = useRef(null);

    const handleScanFile = async (file) => {
        setLoading(true);
        setError(null);
        try {
            const form = new FormData();
            form.append('image', file);
            const { data } = await axios.post(route('flights.scan-passport'), form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            onSuccess(data, file);
            onOpenChange(false);
            setPreview(null);
        } catch (err) {
            setError(err?.response?.data?.message || t('common.scan_failed'));
        } finally {
            setLoading(false);
        }
    };

    const handleFile = (selected) => {
        if (!selected || loading) return;
        setError(null);
        const reader = new FileReader();
        reader.onload = (e) => {
            setPreview(e.target.result);
            handleScanFile(selected);
        };
        reader.readAsDataURL(selected);
    };

    const handleClose = () => {
        if (loading) return;
        onOpenChange(false);
        setPreview(null);
        setError(null);
    };

    return (
        <Dialog open={open} onOpenChange={handleClose}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ScanLine className="h-5 w-5 text-primary" />
                        {title}
                    </DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <div
                    className={`mt-2 rounded-xl border-2 border-dashed border-input bg-muted/30 p-6 text-center transition ${!loading ? 'cursor-pointer hover:border-primary hover:bg-primary/5' : 'cursor-not-allowed opacity-70'}`}
                    onClick={() => !loading && inputRef.current?.click()}
                >
                    {preview ? (
                        <div className="relative space-y-2">
                            <img src={preview} alt="document" className={`mx-auto max-h-48 rounded-lg object-contain ${loading ? 'opacity-50' : ''}`} />
                            {loading ? (
                                <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 rounded-lg bg-background/50">
                                    <Loader2 className="h-8 w-8 animate-spin text-primary" />
                                    <p className="text-xs font-medium text-primary">{t('common.scanning')}</p>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">{t('common.click_to_change')}</p>
                            )}
                        </div>
                    ) : (
                        <div className="flex flex-col items-center gap-2 text-muted-foreground">
                            <Upload className="h-8 w-8" />
                            <p className="text-sm">{description}</p>
                        </div>
                    )}
                    <input
                        ref={inputRef}
                        type="file"
                        accept="image/*"
                        className="hidden"
                        onChange={(e) => handleFile(e.target.files?.[0] ?? null)}
                    />
                </div>

                {error && (
                    <div className="space-y-1">
                        <p className="text-sm text-destructive">{error}</p>
                        <button
                            type="button"
                            className="text-xs text-primary underline"
                            onClick={() => { setError(null); inputRef.current?.click(); }}
                        >
                            {t('common.click_to_change')}
                        </button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Module-level: single searchable segment used inside DateSelect (day / month / year).
 * Fixed-position dropdown with type-to-filter. Flush/joined: no internal border-radius,
 * outer container provides the rounded border.
 */
function DateSegmentCombobox({ value, onChange, options, placeholder, position }) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [dropdownStyle, setDropdownStyle] = useState({});
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const inputRef = useRef(null);
    const containerRef = useRef(null);
    const listRef = useRef(null);

    const selectedOption = options.find((opt) => opt.value === value);
    const displayValue = open ? query : (selectedOption?.label ?? '');

    const filtered = useMemo(() => {
        if (!query) return options;
        const q = query.toLowerCase();
        return options.filter((opt) => opt.label.toLowerCase().includes(q) || opt.value.toLowerCase().includes(q));
    }, [query, options]);

    // Reset highlight when filtered list changes
    useEffect(() => {
        setHighlightedIndex(-1);
    }, [filtered]);

    // Scroll highlighted item into view
    useEffect(() => {
        if (highlightedIndex < 0 || !listRef.current) return;
        const item = listRef.current.children[highlightedIndex];
        item?.scrollIntoView({ block: 'nearest' });
    }, [highlightedIndex]);

    const updateDropdownPosition = () => {
        if (!inputRef.current) return;
        const rect = inputRef.current.getBoundingClientRect();
        setDropdownStyle({
            position: 'fixed',
            top: rect.bottom + 4,
            left: rect.left,
            width: Math.max(rect.width, 120),
            zIndex: 9999,
        });
    };

    const closeDropdown = () => {
        setOpen(false);
        setQuery('');
        setHighlightedIndex(-1);
    };

    useEffect(() => {
        const handler = (e) => {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                closeDropdown();
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const handleKeyDown = (e) => {
        if (!open) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlightedIndex((i) => Math.min(i + 1, filtered.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlightedIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (highlightedIndex >= 0 && filtered[highlightedIndex]) {
                onChange(filtered[highlightedIndex].value);
                closeDropdown();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeDropdown();
        }
    };

    return (
        <div ref={containerRef} className={`relative min-w-0 flex-1${position !== 'last' ? ' border-r border-input' : ''}`}>
            <input
                ref={inputRef}
                className="h-full w-full bg-transparent px-2 text-sm outline-none placeholder:text-muted-foreground/60"
                placeholder={placeholder}
                value={displayValue}
                onFocus={() => {
                    updateDropdownPosition();
                    setOpen(true);
                    setQuery('');
                }}
                onChange={(e) => {
                    setQuery(e.target.value);
                    updateDropdownPosition();
                }}
                onKeyDown={handleKeyDown}
                autoComplete="off"
            />
            {open && (
                <ul ref={listRef} style={dropdownStyle} className="max-h-44 overflow-y-auto rounded-md border bg-popover text-sm shadow-md">
                    {filtered.length === 0 ? (
                        <li className="px-3 py-2 text-muted-foreground">—</li>
                    ) : (
                        filtered.map((opt, idx) => (
                            <li
                                key={opt.value}
                                className={`cursor-pointer px-3 py-2 hover:bg-accent hover:text-accent-foreground${value === opt.value ? ' bg-accent font-semibold' : ''}${idx === highlightedIndex ? ' bg-accent text-accent-foreground' : ''}`}
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    onChange(opt.value);
                                    closeDropdown();
                                }}
                            >
                                {opt.label}
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}

/**
 * Module-level day/month/year date select with searchable combobox segments.
 * Must NOT be nested inside PassengerInfo — nesting causes React to remount it on
 * every parent re-render, wiping partial selection state mid-entry.
 *
 * Props:
 *   type          – 'dob' | 'passport_expiry'
 *   passengerType – 'adult' | 'child' | 'infant'
 *   value         – YYYY-MM-DD string (or '')
 *   onChange      – (YYYY-MM-DD | '') => void
 *   departureDate – ISO date string for age / expiry lower-bound calculation
 *   returnDate    – ISO date string for round-trip expiry lower-bound
 *   locale        – 'en' | 'ar' | 'fr'
 *   t             – translation function
 */
function DateSelect({ type, passengerType, value, onChange, departureDate, returnDate, required, locale, t, error }) {
    const parseValue = (v) => {
        if (!v) return { day: '', month: '', year: '' };
        const parts = v.split('-');
        if (parts.length !== 3) return { day: '', month: '', year: '' };
        return { year: parts[0], month: parts[1], day: parts[2] };
    };

    const [day, setDay] = useState(() => parseValue(value).day);
    const [month, setMonth] = useState(() => parseValue(value).month);
    const [year, setYear] = useState(() => parseValue(value).year);

    // Sync when value is set externally (e.g. passport scan fills the field)
    useEffect(() => {
        if (!value) return;
        const parsed = parseValue(value);
        setDay(parsed.day);
        setMonth(parsed.month);
        setYear(parsed.year);
    }, [value]);

    // Emit combined value when all three segments are filled
    useEffect(() => {
        if (day && month && year) {
            onChange(`${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`);
        } else if (!day && !month && !year) {
            onChange('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [day, month, year]);

    const today = new Date();
    const departure = departureDate ? new Date(departureDate) : today;
    const refDate = type === 'passport_expiry' && returnDate ? new Date(returnDate) : departure;

    // Year range depends on field type and passenger type
    let minYear, maxYear;
    if (type === 'dob') {
        if (passengerType === 'adult') {
            maxYear = departure.getFullYear() - 12;
            minYear = departure.getFullYear() - 100;
        } else if (passengerType === 'child') {
            maxYear = departure.getFullYear() - 2;
            minYear = departure.getFullYear() - 12;
        } else {
            // infant
            maxYear = departure.getFullYear();
            minYear = departure.getFullYear() - 2;
        }
    } else {
        // passport_expiry — must not expire before departure / return
        minYear = refDate.getFullYear();
        maxYear = today.getFullYear() + 20;
    }

    const years = [];
    if (type === 'dob') {
        for (let y = maxYear; y >= minYear; y--) {
            years.push({ value: String(y), label: String(y) });
        }
    } else {
        for (let y = minYear; y <= maxYear; y++) {
            years.push({ value: String(y), label: String(y) });
        }
    }

    const months = Array.from({ length: 12 }, (_, i) => ({
        value: String(i + 1).padStart(2, '0'),
        label: String(i + 1).padStart(2, '0'),
    }));

    const daysInMonth = month && year ? new Date(parseInt(year), parseInt(month), 0).getDate() : 31;
    const days = Array.from({ length: daysInMonth }, (_, i) => ({
        value: String(i + 1).padStart(2, '0'),
        label: String(i + 1).padStart(2, '0'),
    }));

    return (
        <div className={`flex h-9 rounded-md border ${error ? 'border-destructive' : 'border-input'}`}>
            <DateSegmentCombobox
                value={day}
                onChange={setDay}
                options={days}
                placeholder={t('common.day')}
                position="first"
            />
            <DateSegmentCombobox
                value={month}
                onChange={setMonth}
                options={months}
                placeholder={t('common.month')}
                position="middle"
            />
            <DateSegmentCombobox
                value={year}
                onChange={setYear}
                options={years}
                placeholder={t('common.year')}
                position="last"
            />
        </div>
    );
}

/** Localise a country object's name given a locale string. */
const getCountryLabel = (c, locale) => {
    if (locale === 'ar' && c.name_ar) return c.name_ar;
    if (locale === 'fr' && c.name_fr) return c.name_fr;
    return c.name_en;
};

/**
 * Module-level: searchable country select — stores the alpha3 code.
 * Must NOT be nested inside PassengerInfo (causes remount / focus loss on every render).
 */
const CountrySelect = ({ value, onChange, required, placeholder, countries, locale, t, error }) => {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [dropdownStyle, setDropdownStyle] = useState({});
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const inputRef = useRef(null);
    const containerRef = useRef(null);
    const listRef = useRef(null);

    const selected = countries.find((c) => c.alpha3 === value?.toLowerCase());
    const selectedPrefix = selected?.alpha2 ? `${flagEmoji(selected.alpha2)} ${selected.alpha3?.toUpperCase()}` : '';
    const displayValue = selected ? `${selectedPrefix}  ${getCountryLabel(selected, locale)}`.trim() : (value || '');

    const filtered = useMemo(() => {
        if (!query) return countries;
        const q = query.toLowerCase();
        return countries.filter(
            (c) =>
                (c.alpha3 || '').includes(q) ||
                (c.alpha2 || '').includes(q) ||
                (c.name_en || '').toLowerCase().includes(q) ||
                (c.name_ar || '').includes(q) ||
                (c.name_fr || '').toLowerCase().includes(q),
        );
    }, [query, countries]);

    // Reset highlight when filtered list changes
    useEffect(() => {
        setHighlightedIndex(-1);
    }, [filtered]);

    // Scroll highlighted item into view
    useEffect(() => {
        if (highlightedIndex < 0 || !listRef.current) return;
        const item = listRef.current.children[highlightedIndex];
        item?.scrollIntoView({ block: 'nearest' });
    }, [highlightedIndex]);

    const updateDropdownPosition = () => {
        if (!inputRef.current) return;
        const rect = inputRef.current.getBoundingClientRect();
        setDropdownStyle({
            position: 'fixed',
            top: rect.bottom + 4,
            left: rect.left,
            width: rect.width,
            zIndex: 9999,
        });
    };

    const closeDropdown = () => {
        setOpen(false);
        setQuery('');
        setHighlightedIndex(-1);
    };

    useEffect(() => {
        const handler = (e) => {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                closeDropdown();
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const handleKeyDown = (e) => {
        if (!open) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlightedIndex((i) => Math.min(i + 1, filtered.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlightedIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (highlightedIndex >= 0 && filtered[highlightedIndex]) {
                onChange(filtered[highlightedIndex].alpha3.toUpperCase());
                closeDropdown();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeDropdown();
        }
    };

    return (
        <div ref={containerRef} className="relative">
            <Input
                ref={inputRef}
                required={required}
                placeholder={placeholder || t('common.search') + ' ' + t('common.country') + '…'}
                value={open ? query : displayValue}
                className={error ? 'border-destructive' : ''}
                onFocus={() => {
                    updateDropdownPosition();
                    setOpen(true);
                    setQuery('');
                }}
                onChange={(e) => {
                    setQuery(e.target.value);
                    updateDropdownPosition();
                }}
                onKeyDown={handleKeyDown}
                autoComplete="off"
            />
            {open && (
                <ul ref={listRef} style={dropdownStyle} className="max-h-52 overflow-y-auto rounded-md border bg-popover shadow-md text-sm">
                    {filtered.length === 0 && (
                        <li className="px-3 py-2 text-muted-foreground">{t('common.no_results') || 'No results'}</li>
                    )}
                    {filtered.map((c, idx) => (
                        <li
                            key={c.alpha3}
                            className={`cursor-pointer px-3 py-2 hover:bg-accent hover:text-accent-foreground flex items-center gap-2 ${value?.toLowerCase() === c.alpha3 ? 'bg-accent font-semibold' : ''}${idx === highlightedIndex ? ' bg-accent text-accent-foreground' : ''}`}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                onChange(c.alpha3.toUpperCase());
                                closeDropdown();
                            }}
                        >
                            <span className="text-base leading-none">{flagEmoji(c.alpha2)}</span>
                            <span className="font-mono text-xs text-muted-foreground w-10 shrink-0">{c.alpha3?.toUpperCase() ?? ''}</span>
                            <span>{getCountryLabel(c, locale)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
};

/**
 * Normalise a raw local phone string: strips country prefix, leading zeros,
 * formatting chars (spaces, dashes) and returns clean local digits only.
 *
 * Handles:
 *   "+218911388788"  → "911388788"   (full E.164 with matching prefix)
 *   "218911388788"   → "911388788"   (prefix without +)
 *   "00218911388788" → "911388788"   (00-prefix)
 *   "0911388788"     → "911388788"   (leading 0)
 *   "91-138 8788"    → "911388788"   (formatting chars)
 */
const normalizeLocalNumber = (dialCode, raw) => {
    if (!raw) return '';
    // Strip all non-digit chars first
    let digits = raw.replace(/[^\d]/g, '');
    // Strip the numeric part of dialCode from the front
    const codeDigits = dialCode.replace(/[^\d]/g, '');
    if (digits.startsWith(codeDigits)) {
        digits = digits.slice(codeDigits.length);
    }
    // Strip leading zeros (local number shouldn't start with 0 after prefix removed)
    digits = digits.replace(/^0+/, '');
    return digits;
};

/**
 * Module-level: phone input with searchable dial-code dropdown.
 * Rendered as a single visually joined h-9 input: [flag+code button | number field].
 * Must NOT be nested inside PassengerInfo (causes remount / focus loss on every render).
 */
const PhoneInput = ({ value, onChange, required, countries, locale, t, error }) => {
    const parseValue = (v) => {
        if (!v) return { dialCode: '+218', number: '' };
        const sorted = Object.entries(DIAL_CODES).sort((a, b) => b[1].length - a[1].length);
        for (const [, code] of sorted) {
            if (v.startsWith(code)) {
                return { dialCode: code, number: v.slice(code.length) };
            }
        }
        return { dialCode: '+218', number: v };
    };

    const [dialCode, setDialCode] = useState(() => parseValue(value).dialCode);
    const [number, setNumber] = useState(() => parseValue(value).number);
    const [query, setQuery] = useState('');
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
                (c.alpha2 || '').includes(q) ||
                c.dialCode.includes(q) ||
                (c.name_en || '').toLowerCase().includes(q) ||
                (c.name_ar || '').includes(q) ||
                (c.name_fr || '').toLowerCase().includes(q),
        );
    }, [query, countriesWithDial]);

    const selectedCountry = countriesWithDial.find((c) => c.dialCode === dialCode);

    const updateDropdownPosition = () => {
        if (!triggerRef.current) return;
        const rect = triggerRef.current.getBoundingClientRect();
        setDropdownStyle({
            position: 'fixed',
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
                setQuery('');
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const selectDial = (code) => {
        setDialCode(code);
        setOpen(false);
        setQuery('');
        onChange(number ? code + number : '');
    };

    const handleNumber = (v) => {
        const cleaned = v.replace(/[^\d\s\-]/g, '');
        setNumber(cleaned);
        onChange(cleaned ? dialCode + cleaned : '');
    };

    const handleNumberBlur = () => {
        const normalized = normalizeLocalNumber(dialCode, number);
        if (normalized !== number) {
            setNumber(normalized);
            onChange(normalized ? dialCode + normalized : '');
        }
    };

    return (
        <div ref={containerRef} dir="ltr" className="relative">
            {/* Single joined wrapper — same h-9 height as other inputs */}
            <div className={`flex h-9 rounded-md border ${error ? 'border-destructive' : 'border-input'} bg-background overflow-hidden`}>
                {/* Dial-code button */}
                <button
                    ref={triggerRef}
                    type="button"
                    className="flex h-full shrink-0 items-center gap-1 border-r border-input bg-muted/40 px-2.5 text-sm hover:bg-accent transition-colors focus:outline-none"
                    onClick={() => {
                        updateDropdownPosition();
                        setOpen((o) => !o);
                        setQuery('');
                    }}
                >
                    <span className="text-base leading-none">{selectedCountry ? flagEmoji(selectedCountry.alpha2) : '🌐'}</span>
                    <span className="font-mono text-xs font-semibold">{dialCode}</span>
                </button>
                {/* Local number input */}
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
            {/* Dial-code dropdown */}
            {open && (
                <div style={dropdownStyle} className="rounded-md border bg-popover shadow-md">
                    <div className="p-2 border-b">
                        <input
                            autoFocus
                            className="w-full rounded border border-input bg-background px-2 py-1.5 text-sm outline-none"
                            placeholder={`${t('common.search')}…`}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </div>
                    <ul className="max-h-48 overflow-y-auto text-sm">
                        {filteredDial.length === 0 && (
                            <li className="px-3 py-2 text-muted-foreground">{t('common.no_results')}</li>
                        )}
                        {filteredDial.map((c) => (
                            <li
                                key={c.alpha2}
                                className={`cursor-pointer px-3 py-2 flex items-center gap-2 hover:bg-accent hover:text-accent-foreground ${dialCode === c.dialCode && selectedCountry?.alpha2 === c.alpha2 ? 'bg-accent font-semibold' : ''}`}
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    selectDial(c.dialCode);
                                }}
                            >
                                <span className="text-base leading-none">{flagEmoji(c.alpha2)}</span>
                                <span className="font-mono text-xs text-muted-foreground w-10 shrink-0">{c.dialCode}</span>
                                <span className="truncate">{getCountryLabel(c, locale)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
};

export default function PassengerInfo({ uuid, provider_id, flight, reservation_type, return_reservation_type = null, is_round_trip = false, outbound_provider_id = null, return_provider_id = null, passportRequired = false, searchParams, ancillaryCatalog = [], ancillaryCatalogByOffer = {}, cached_passengers = [], cached_customer = null, countries = [], airports_map = {} }) {
    const { t, getAirlineName, getCurrencyName, getCabinName } = useTranslation();
    const flash = usePage().props.flash ?? {};
    const locale = usePage().props.locale || 'en';
    const issueCommandPreview = flash.issue_command_preview || '';



    const initialPassengers = (() => {
        if (cached_passengers?.length > 0) return cached_passengers;
        const pax = [];
        const types = [
            { type: 'adult', count: searchParams?.adults || 1 },
            { type: 'child', count: searchParams?.children || 0 },
            { type: 'infant', count: searchParams?.infants || 0 },
        ];
        types.forEach(({ type, count }) => {
            for (let index = 0; index < count; index += 1) {
                pax.push({
                    type,
                    first_name: '',
                    last_name: '',
                    dob: '',
                    gender: 'M',
                    passport_number: '',
                    passport_expiry: '',
                    passport_issue_country: 'LBY',
                    nationality: 'LBY',
                    passport_file: null,
                    visa_number: '',
                    visa_type: '',
                    visa_expiry: '',
                    visa_issue_country: '',
                    visa_file: null,
                });
            }
        });
        return pax;
    })();

    const { data, setData, post, processing, errors } = useForm({
        uuid,
        provider_id,
        flight,
        reservation_type,
        is_round_trip,
        outbound_provider_id,
        return_provider_id,
        ticketing_mode: 'final',
        customer: {
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
        },
        passengers: initialPassengers,
        extras: {
            selected_services: [],
            seats: {},
            esim_selection: null,
        },
    });

    // Initialize form with cached data if available
    useEffect(() => {
        if (cached_passengers?.length > 0) {
            setData('passengers', cached_passengers);
        }
        if (cached_customer) {
            setData('customer', cached_customer);
        }
    }, []);

    const [activeTab, setActiveTab] = useState('passengers');
    const [isSeatMapOpen, setIsSeatMapOpen] = useState(false);
    const [seatMapByOffer, setSeatMapByOffer] = useState({});
    const [loadingSeatMap, setLoadingSeatMap] = useState(false);
    const [activeOfferKeyForSeat, setActiveOfferKeyForSeat] = useState('oneway');
    const [activePaxIndexForSeat, setActivePaxIndexForSeat] = useState(0);
    const [localErrors, setLocalErrors] = useState({});
    const [esimPackages, setEsimPackages] = useState([]);
    const [loadingEsim, setLoadingEsim] = useState(false);
    const [esimModalOpen, setEsimModalOpen] = useState(false);
    const [scanPassportOpen, setScanPassportOpen] = useState(false);
    const [scanPassengerIndex, setScanPassengerIndex] = useState(null);
    const [scanVisaOpen, setScanVisaOpen] = useState(false);
    const [scanVisaPassengerIndex, setScanVisaPassengerIndex] = useState(null);
    const [expandedPassengerIndex, setExpandedPassengerIndex] = useState(0);
    const [visaOpenIndexes, setVisaOpenIndexes] = useState(new Set());
    const [serviceModalOpen, setServiceModalOpen] = useState(false);
    const [serviceModalCode, setServiceModalCode] = useState(null);
    const [serviceModalOfferKey, setServiceModalOfferKey] = useState('oneway');
    const [pendingDocUpload, setPendingDocUpload] = useState(null); // { index, type: 'passport' | 'visa' }
    const docUploadInputRef = useRef(null);

    const offerContexts = useMemo(() => {
        if (is_round_trip && flight?.round_trip) {
            const outboundFlight = flight.round_trip.outbound_flight;
            const returnFlight = flight.round_trip.return_flight;

            const contexts = [];

            if (outboundFlight) {
                contexts.push({
                    key: 'outbound',
                    label: t('common.outbound_offer'),
                    providerId: Number(outbound_provider_id || provider_id),
                    flight: outboundFlight,
                    segments: outboundFlight.segments || [outboundFlight],
                    reservation_type,
                });
            }

            if (returnFlight) {
                contexts.push({
                    key: 'return',
                    label: t('common.return_offer'),
                    providerId: Number(return_provider_id || provider_id),
                    flight: returnFlight,
                    segments: returnFlight.segments || [returnFlight],
                    reservation_type: return_reservation_type || reservation_type,
                });
            }

            if (contexts.length > 0) {
                return contexts;
            }
        }

        return [
            {
                key: 'oneway',
                label: '',
                providerId: Number(provider_id),
                flight,
                segments: flight?.segments || [flight],
                reservation_type,
            },
        ];
    }, [flight, is_round_trip, outbound_provider_id, provider_id, return_provider_id]);

    const isRoundTripBooking = offerContexts.length > 1;

    useEffect(() => {
        if (!offerContexts.some((offer) => offer.key === activeOfferKeyForSeat)) {
            setActiveOfferKeyForSeat(offerContexts[0]?.key || 'oneway');
        }
    }, [activeOfferKeyForSeat, offerContexts]);

    const destinationIata = useMemo(
        () => offerContexts[0]?.segments?.[0]?.arrival_airport ?? null,
        [offerContexts],
    );

    useEffect(() => {
        if (!destinationIata) {
            return;
        }

        setLoadingEsim(true);

        axios
            .get(route('esim.airport.packages', { iata: destinationIata }))
            .then((res) => setEsimPackages(res.data.packages || []))
            .catch(() => setEsimPackages([]))
            .finally(() => setLoadingEsim(false));
    }, [destinationIata]);

    const ancillaryCatalogByOfferMap = useMemo(() => {
        const map = {};

        offerContexts.forEach((offer) => {
            const offerCatalog = ancillaryCatalogByOffer?.[offer.key] ?? ancillaryCatalog;
            map[offer.key] = Array.isArray(offerCatalog)
                ? offerCatalog.filter((service) => service.enabled)
                : [];
        });

        return map;
    }, [ancillaryCatalog, ancillaryCatalogByOffer, offerContexts]);

    const passportFields = ['passport_number', 'passport_expiry', 'passport_issue_country', 'nationality'];
    const hasPartialPassportDetails = useMemo(() => {
        return data.passengers.some((passenger) => {
            const values = passportFields.map((field) => (passenger[field] ?? '').toString().trim());
            const hasAny = values.some((value) => value !== '');
            const hasMissing = values.some((value) => value === '');

            return hasAny && hasMissing;
        });
    }, [data.passengers]);

    const validateStep = (step) => {
        const errors = {};
        if (step === 'passengers') {
            data.passengers.forEach((p, i) => {
                // Name: single combined error when either field is empty
                if (!p.first_name || !p.last_name) {
                    errors[`passengers.${i}.name`] = t('common.passenger_name_required');
                } else {
                    if (!/^[A-Za-z]+$/.test(p.first_name)) {
                        errors[`passengers.${i}.first_name`] = `${t('common.first_name')}: ${t('common.letters_only')}`;
                    }
                    if (!/^[A-Za-z]+$/.test(p.last_name)) {
                        errors[`passengers.${i}.last_name`] = `${t('common.last_name')}: ${t('common.letters_only')}`;
                    }
                }

                if (!p.dob) {
                    errors[`passengers.${i}.dob`] = t('common.dob_required');
                }

                if (!p.gender) {
                    errors[`passengers.${i}.gender`] = t('common.gender_required');
                }

                const passportValues = passportFields.map((field) => (p[field] ?? '').toString().trim());
                const hasAnyPassportDetail = passportValues.some((value) => value !== '');
                const needsPassportDetails = passportRequired || hasAnyPassportDetail;

                if (needsPassportDetails) {
                    if (!p.passport_number) errors[`passengers.${i}.passport_number`] = t('common.passport_number_required');
                    if (!p.passport_expiry) errors[`passengers.${i}.passport_expiry`] = t('common.passport_expiry_required');
                    if (!p.nationality) errors[`passengers.${i}.nationality`] = t('common.nationality_required');
                    if (!p.passport_issue_country) errors[`passengers.${i}.passport_issue_country`] = t('common.passport_issue_country_required');
                }
            });

            if (!data.customer.email) {
                errors['customer.email'] = t('common.email_required');
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.customer.email)) {
                errors['customer.email'] = t('common.invalid_email');
            }

            if (!data.customer.phone) {
                errors['customer.phone'] = t('common.phone_required');
            } else {
                // Extract local digits from stored value (dialCode + localNumber)
                const sorted = Object.entries(DIAL_CODES).sort((a, b) => b[1].length - a[1].length);
                let localDigits = data.customer.phone;
                for (const [, code] of sorted) {
                    if (localDigits.startsWith(code)) {
                        localDigits = localDigits.slice(code.length);
                        break;
                    }
                }
                localDigits = localDigits.replace(/[^\d]/g, '');
                if (localDigits.length < 7 || localDigits.length > 15) {
                    errors['customer.phone'] = t('common.phone_invalid');
                }
            }
        }
        setLocalErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const nextStep = (current, next) => {
        if (validateStep(current)) {
            setActiveTab(next);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const prevStep = (prev) => {
        setActiveTab(prev);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const getSelectedService = (offerKey, code) => {
        return data.extras.selected_services.find((service) => service.offer_key === offerKey && service.code === code) ?? null;
    };

    const replaceSelectedServices = (services) => {
        setData('extras', {
            ...data.extras,
            selected_services: services,
        });
    };

    const upsertService = (offerKey, code, updater) => {
        const currentService = getSelectedService(offerKey, code) ?? { offer_key: offerKey, code, quantity: 0, passengers: [] };
        const nextService = updater(currentService);
        const nextServices = data.extras.selected_services.filter((service) => !(service.offer_key === offerKey && service.code === code));
        const normalizedPassengers = [...new Set((nextService.passengers ?? []).map((value) => Number(value)))];
        const normalizedQuantity = Math.max(0, Number(nextService.quantity ?? 0));

        if (normalizedQuantity === 0 && normalizedPassengers.length === 0) {
            replaceSelectedServices(nextServices);

            return;
        }

        replaceSelectedServices([
            ...nextServices,
            {
                code,
                offer_key: offerKey,
                quantity: normalizedQuantity,
                passengers: normalizedPassengers,
            },
        ]);
    };

    const togglePassengerService = (offerKey, service, passengerIndex) => {
        upsertService(offerKey, service.code, (currentService) => {
            const passengers = new Set((currentService.passengers ?? []).map((value) => Number(value)));

            if (passengers.has(passengerIndex)) {
                passengers.delete(passengerIndex);
            } else {
                passengers.add(passengerIndex);
            }

            return {
                ...currentService,
                quantity: passengers.size > 0 ? (currentService.quantity || 1) : 0,
                passengers: [...passengers],
            };
        });
    };

    const toggleBookingService = (offerKey, service) => {
        upsertService(offerKey, service.code, (currentService) => {
            const isSelected = Number(currentService.quantity ?? 0) > 0;

            return {
                ...currentService,
                quantity: isSelected ? 0 : 1,
                passengers: [],
            };
        });
    };

    const setQuantityService = (offerKey, service, quantity) => {
        upsertService(offerKey, service.code, (currentService) => ({
            ...currentService,
            quantity: Math.min(
                service.max_quantity || quantity,
                Math.max(service.min_quantity || 0, quantity),
            ),
            passengers: currentService.passengers ?? [],
        }));
    };

    const applyServiceToAllOffers = (sourceOfferKey, service) => {
        const sourceSelection = getSelectedService(sourceOfferKey, service.code);
        const defaultPassengers = data.passengers.map((_, index) => index);
        const nextQuantity = sourceSelection
            ? Number(sourceSelection.quantity ?? 0)
            : Math.max(service.min_quantity || 0, service.default_quantity || 1);
        const nextPassengers = sourceSelection
            ? [...new Set((sourceSelection.passengers ?? []).map((value) => Number(value)))]
            : (service.pricing_mode === 'per_booking' || service.pricing_mode === 'per_kg' ? [] : defaultPassengers);

        const nextServices = data.extras.selected_services.filter((selectedService) => selectedService.code !== service.code);

        const appliedToOffers = offerContexts.map((offer) => ({
            offer_key: offer.key,
            code: service.code,
            quantity: nextQuantity,
            passengers: nextPassengers,
        }));

        replaceSelectedServices([...nextServices, ...appliedToOffers]);
    };

    const ancillaryLines = useMemo(() => {
        return offerContexts.reduce((lines, offer) => {
            const services = ancillaryCatalogByOfferMap[offer.key] ?? [];

            services.forEach((service) => {
                const selection = getSelectedService(offer.key, service.code);

                if (!selection) {
                    return;
                }

                const passengerCount = selection.passengers?.length > 0 ? selection.passengers.length : data.passengers.length;
                const segmentCount = offer.segments?.length || 1;
                const quantity = Number(selection.quantity ?? 0);

                let multiplier = 1;

                if (service.pricing_mode === 'per_kg') {
                    multiplier = quantity;
                } else if (service.pricing_mode === 'per_passenger') {
                    multiplier = passengerCount;
                } else if (service.pricing_mode === 'per_segment') {
                    multiplier = segmentCount;
                } else if (service.pricing_mode === 'per_passenger_per_segment') {
                    multiplier = passengerCount * segmentCount;
                }

                const total = Number(service.unit_price || 0) * multiplier;

                lines.push({
                    offer_key: offer.key,
                    offer_label: offer.label,
                    code: service.code,
                    label: service.label,
                    quantity,
                    total,
                });
            });

            return lines;
        }, []);
    }, [ancillaryCatalogByOfferMap, data.extras.selected_services, data.passengers.length, offerContexts]);

    const ancillaryTotal = ancillaryLines.reduce((total, line) => total + line.total, 0);

    const uniqueServices = useMemo(() => {
        const seen = new Set();
        const result = [];

        for (const offer of offerContexts) {
            for (const service of (ancillaryCatalogByOfferMap[offer.key] ?? [])) {
                if (!seen.has(service.code)) {
                    seen.add(service.code);
                    result.push(service);
                }
            }
        }

        return result;
    }, [ancillaryCatalogByOfferMap, offerContexts]);

    const activeModalService = useMemo(
        () => uniqueServices.find((s) => s.code === serviceModalCode) ?? null,
        [uniqueServices, serviceModalCode],
    );

    const fetchSeatMap = async () => {
        setIsSeatMapOpen(true);

        const offersToLoad = offerContexts.filter((offer) => !seatMapByOffer[offer.key]);
        if (offersToLoad.length === 0) {
            return;
        }

        setLoadingSeatMap(true);

        try {
            const responses = await Promise.all(
                offersToLoad.map(async (offer) => {
                    const firstSegment = offer.segments?.[0] || offer.flight;
                    const flightCode = firstSegment?.flight_number;
                    const flightDate = firstSegment?.departure_time || firstSegment?.date;

                    if (!offer.providerId || !flightCode || !flightDate) {
                        return { key: offer.key, data: null };
                    }

                    const response = await axios.post(route('flights.seatmap'), {
                        provider_id: offer.providerId,
                        flight_number: flightCode,
                        date: flightDate,
                    });

                    return { key: offer.key, data: response.data };
                })
            );

            setSeatMapByOffer((previous) => {
                const next = { ...previous };

                responses.forEach(({ key, data }) => {
                    next[key] = data;
                });

                return next;
            });
        } catch (error) {
            console.error('Failed to fetch seat map', error);
        } finally {
            setLoadingSeatMap(false);
        }
    };

    const handleSeatSelection = (offerKey, seatCode) => {
        const nextSeats = { ...data.extras.seats };
        const offerSeats = { ...(nextSeats[offerKey] ?? {}) };
        const existingPaxIndex = Object.keys(offerSeats).find((index) => offerSeats[index] === seatCode);

        if (existingPaxIndex !== undefined) {
            if (Number(existingPaxIndex) === activePaxIndexForSeat) {
                delete offerSeats[activePaxIndexForSeat];
            } else {
                delete offerSeats[existingPaxIndex];
                offerSeats[activePaxIndexForSeat] = seatCode;
            }
        } else {
            offerSeats[activePaxIndexForSeat] = seatCode;
        }

        if (Object.keys(offerSeats).length === 0) {
            delete nextSeats[offerKey];
        } else {
            nextSeats[offerKey] = offerSeats;
        }

        setData('extras', {
            ...data.extras,
            seats: nextSeats,
        });

        if (activePaxIndexForSeat < data.passengers.length - 1 && !offerSeats[activePaxIndexForSeat + 1]) {
            setActivePaxIndexForSeat(activePaxIndexForSeat + 1);
        }
    };

    const selectEsimPackage = (pkg) => {
        setData('extras', {
            ...data.extras,
            esim_selection: {
                package_id: pkg.id,
                name: pkg.name,
                price: pkg.price,
                currency: pkg.currency,
            },
        });
        setEsimModalOpen(false);
    };

    const removeEsimPackage = () => {
        setData('extras', {
            ...data.extras,
            esim_selection: null,
        });
    };

    const generateGrid = (offerKey) => {
        const seatMapData = seatMapByOffer[offerKey];

        if (!seatMapData || !seatMapData.grid) {
            return [];
        }

        const { max_row, max_col } = seatMapData.grid;
        const grid = Array(max_row)
            .fill(null)
            .map(() => Array(max_col).fill(null));

        seatMapData.seats.forEach((seat) => {
            if (seat.row > 0 && seat.col > 0) {
                grid[seat.row - 1][max_col - seat.col] = seat;
            }
        });

        return grid;
    };

    const handleCustomerChange = (field, value) => {
        setData('customer', { ...data.customer, [field]: value });
    };

    const handlePassengerChange = (index, field, value) => {
        const updatedPassengers = [...data.passengers];
        let nextValue = value;

        if (field === 'first_name' || field === 'last_name') {
            nextValue = value.replace(/[^A-Za-z]/g, '');
        }

        updatedPassengers[index][field] = nextValue;
        
        const newData = { passengers: updatedPassengers };
        
        if (index === 0 && (field === 'first_name' || field === 'last_name')) {
            newData.customer = {
                ...data.customer,
                [field]: nextValue,
            };
        }
        
        setData((prev) => ({ ...prev, ...newData }));
    };

    /** Fill a passenger's fields from a passport scan result. Only overwrites non-empty values. */
    const handleFillFromScan = (scanData, file) => {
        if (scanPassengerIndex === null) return;
        const updatedPassengers = data.passengers.map((p, i) => {
            if (i !== scanPassengerIndex) return p;
            const filled = {
                first_name: scanData.first_name || p.first_name,
                last_name: scanData.last_name || p.last_name,
                dob: scanData.dob || p.dob,
                gender: scanData.gender || p.gender,
                passport_number: scanData.passport_number || p.passport_number,
                passport_expiry: scanData.passport_expiry || p.passport_expiry,
                passport_issue_country: scanData.passport_issue_country || p.passport_issue_country,
                nationality: scanData.nationality || p.nationality,
                passport_file: file ?? p.passport_file,
            };
            return { ...p, ...filled };
        });
        setData('passengers', updatedPassengers);
        setExpandedPassengerIndex(scanPassengerIndex);
    };

    /** Fill a passenger's visa fields from a visa scan result. */
    const handleFillFromVisaScan = (scanData, file) => {
        if (scanVisaPassengerIndex === null) return;
        const updatedPassengers = data.passengers.map((p, i) => {
            if (i !== scanVisaPassengerIndex) return p;
            return {
                ...p,
                visa_number: scanData.passport_number || p.visa_number,
                visa_expiry: scanData.passport_expiry || p.visa_expiry,
                visa_issue_country: scanData.passport_issue_country || p.visa_issue_country,
                visa_file: file ?? p.visa_file,
            };
        });
        setData('passengers', updatedPassengers);
        setExpandedPassengerIndex(scanVisaPassengerIndex);
        setVisaOpenIndexes((prev) => new Set([...prev, scanVisaPassengerIndex]));
    };

    /** Returns true if the given passenger index has any local validation errors. */
    const hasPassengerLocalErrors = (index) =>
        Object.keys(localErrors).some((key) => key.startsWith(`passengers.${index}.`));

    /** Toggle visa section open/closed for a given passenger index. */
    const toggleVisaSection = (index) => {
        setVisaOpenIndexes((prev) => {
            const next = new Set(prev);
            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }
            return next;
        });
    };

    /** Open the hidden file input for direct document attachment (no OCR). */
    const openDocUpload = (index, type) => {
        setPendingDocUpload({ index, type });
        docUploadInputRef.current?.click();
    };

    /** Handle file selection from the hidden input — store File object in passenger state. */
    const handleDocUpload = (e) => {
        const file = e.target.files?.[0];
        if (!file || !pendingDocUpload) return;
        const { index, type } = pendingDocUpload;
        const field = type === 'passport' ? 'passport_file' : 'visa_file';
        handlePassengerChange(index, field, file);
        setPendingDocUpload(null);
        e.target.value = '';
    };

    const submitBooking = (event) => {
        event.preventDefault();

        post(route('flights.store'), {
            transform: (formData) => {
                const normalizedSeats = {};

                offerContexts.forEach((offer, offerIndex) => {
                    const offerSeats = formData.extras?.seats?.[offer.key] ?? {};
                    const segmentNumber = offerIndex + 1;

                    Object.entries(offerSeats).forEach(([passengerIndex, seatCode]) => {
                        if (!seatCode) {
                            return;
                        }

                        normalizedSeats[passengerIndex] = {
                            ...(normalizedSeats[passengerIndex] ?? {}),
                            [segmentNumber]: seatCode,
                        };
                    });
                });

                return {
                    ...formData,
                    extras: {
                        ...(formData.extras ?? {}),
                        seats: normalizedSeats,
                    },
                };
            },
        });
    };

    const providerPrice = Number(flight.pricing?.total || 0);
    const currency = getCurrencyName(flight.pricing?.currency);
    const grandTotal = providerPrice + ancillaryTotal;
    const selectedSeatLabels = offerContexts.flatMap((offer) => {
        const offerSeats = data.extras.seats?.[offer.key] ?? {};

        return Object.entries(offerSeats)
            .map(([index, seatCode]) => (seatCode ? `${offer.label} - ${t('common.pax')} ${Number(index) + 1}: ${seatCode}` : null))
            .filter(Boolean);
    });

    const formatSegmentDateTime = (value) => {
        if (!value) {
            return '--';
        }

        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return String(value);
        }

        return parsed.toLocaleString(undefined, {
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const firstSegment = offerContexts[0]?.segments?.[0] || null;
    const lastOffer = offerContexts[offerContexts.length - 1] || null;
    const lastSegment = lastOffer?.segments?.[lastOffer.segments.length - 1] || null;

    const renderServiceOfferControls = (offer, service) => {
        const selection = getSelectedService(offer.key, service.code);
        const quantity = Number(selection?.quantity ?? service.default_quantity ?? 0);
        const selectedPassengers = new Set((selection?.passengers ?? []).map((v) => Number(v)));
        const isQuantityService = service.type === 'baggage_increment' || service.pricing_mode === 'per_kg';
        const isBookingService = service.pricing_mode === 'per_booking';

        return (
            <div className="space-y-4">
                {isQuantityService && (
                    <div className="flex items-center justify-between rounded-xl border bg-muted/20 px-4 py-3">
                        <span className="font-semibold">{service.unit_label || t('common.unit_quantity')}</span>
                        <div className="flex items-center gap-3">
                            <Button type="button" variant="outline" size="sm" onClick={() => setQuantityService(offer.key, service, quantity - 1)} disabled={quantity <= (service.min_quantity || 0)}>-</Button>
                            <span className="min-w-12 text-center text-lg font-black">{quantity}</span>
                            <Button type="button" variant="outline" size="sm" onClick={() => setQuantityService(offer.key, service, quantity + 1)} disabled={service.max_quantity > 0 && quantity >= service.max_quantity}>+</Button>
                        </div>
                    </div>
                )}

                {isBookingService && !isQuantityService && (
                    <Button
                        type="button"
                        variant={quantity > 0 ? 'default' : 'outline'}
                        className="w-full rounded-full"
                        onClick={() => toggleBookingService(offer.key, service)}
                    >
                        {quantity > 0 ? t('common.selected_for_this_offer') : t('common.add_to_this_offer')}
                    </Button>
                )}

                {!isQuantityService && !isBookingService && (
                    <div className="space-y-2">
                        <p className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{t('common.select_passengers')}</p>
                        <div className="flex flex-wrap gap-2">
                            {data.passengers.map((passenger, passengerIndex) => {
                                const isSelected = selectedPassengers.has(passengerIndex);

                                return (
                                    <Button
                                        key={`svc-modal-${offer.key}-${service.code}-${passengerIndex}`}
                                        type="button"
                                        variant={isSelected ? 'default' : 'outline'}
                                        className="rounded-full"
                                        onClick={() => togglePassengerService(offer.key, service, passengerIndex)}
                                    >
                                        {t('common.pax')} {passengerIndex + 1}
                                    </Button>
                                );
                            })}
                        </div>
                    </div>
                )}

                {isRoundTripBooking && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-8 px-0 text-xs font-bold text-primary"
                        onClick={() => applyServiceToAllOffers(offer.key, service)}
                    >
                        {t('common.apply_to_all_offers')}
                    </Button>
                )}

                <div className="rounded-xl border bg-muted/30 px-4 py-3">
                    <p className="text-2xl font-black text-primary">{Number(service.unit_price || 0).toFixed(2)} {currency}</p>
                    <p className="text-xs capitalize text-muted-foreground">{(service.pricing_mode ?? '').replace(/_/g, ' ')}</p>
                </div>
            </div>
        );
    };

    return (
        <TenantNavbarLayout>
            <Head title="Passenger Details" />

            {/* Hero banner */}
            <div className="bg-primary text-primary-foreground px-6 py-10">
                <div className="max-w-7xl mx-auto">
                    <Link
                        href={route('flights.results', { uuid })}
                        className="inline-flex items-center gap-1 text-primary-foreground/70 hover:text-primary-foreground text-sm mb-4 transition-colors"
                    >
                        <ChevronLeft className="w-4 h-4" />
                        {t('common.back_to_flights')}
                    </Link>
                    <h1 className="text-2xl font-bold">{t('common.complete_your_booking')}</h1>
                    <p className="text-primary-foreground/70 mt-1 text-sm">{t('common.fill_passenger_details_seats_services')}</p>

                    {/* 3-step indicator */}
                    <div className="flex items-center gap-3 mt-6">
                        {[
                            { key: 'passengers', label: t('common.passengers_tab'), step: 1 },
                            { key: 'extras',     label: t('common.extras_tab'),     step: 2 },
                            { key: 'review',     label: t('common.review_tab'),     step: 3 },
                        ].map(({ key, label, step }, idx, arr) => {
                            const stepOrder = { passengers: 1, extras: 2, review: 3 };
                            const current = stepOrder[activeTab] ?? 1;
                            const isDone = current > step;
                            const isActive = activeTab === key;
                            return (
                                <React.Fragment key={key}>
                                    <div className={`flex items-center gap-2 text-sm font-medium ${isActive ? 'text-primary-foreground' : 'text-primary-foreground/50'}`}>
                                        <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold border-2 ${isActive ? 'bg-primary-foreground text-primary border-primary-foreground' : isDone ? 'bg-primary-foreground/20 border-primary-foreground/50 text-primary-foreground/80' : 'border-primary-foreground/50 text-primary-foreground/50'}`}>
                                            {isDone ? <CheckCircle2 className="w-3.5 h-3.5" /> : step}
                                        </span>
                                        {label}
                                    </div>
                                    {idx < arr.length - 1 && <div className="h-px w-8 bg-primary-foreground/30" />}
                                </React.Fragment>
                            );
                        })}
                    </div>
                </div>
            </div>

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 py-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">

                    {flash.error && (
                        <Card className="border border-destructive/40 bg-destructive/5">
                            <CardContent className="py-4 text-sm font-semibold text-destructive">
                                {flash.error}
                            </CardContent>
                        </Card>
                    )}

                    {flash.success && !issueCommandPreview && (
                        <Card className="border border-emerald-300 bg-emerald-50">
                            <CardContent className="py-4 text-sm font-semibold text-emerald-700">
                                {flash.success}
                            </CardContent>
                        </Card>
                    )}

                    {issueCommandPreview && (
                        <Card className="border border-primary/30 bg-primary/5">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-bold text-primary">Issuance Command Preview</CardTitle>
                                <CardDescription>This command is generated for validation only and has not been sent to the airline API.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre className="overflow-x-auto rounded-md border bg-background p-3 text-xs font-mono text-foreground">
                                    {issueCommandPreview}
                                </pre>
                            </CardContent>
                        </Card>
                    )}

                    <form onSubmit={submitBooking} className="space-y-8">
                        <Tabs value={activeTab} className="w-full">
                            <TabsContent value="passengers" className="space-y-6">
                                {/* <Card className="border bg-muted/10">
                                    <CardContent className="pt-6">
                                        <p className="text-sm font-medium text-muted-foreground">
                                            Passport fields are {passportRequired ? 'required for this international route' : 'optional for this domestic route'}.
                                        </p>
                                        {hasPartialPassportDetails && (
                                            <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                                                You entered some passport details. Please complete all passport fields or clear them all.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card> */}

                                {data.passengers.map((passenger, index) => {
                                    const isExpanded = expandedPassengerIndex === index;
                                    const hasErrors = hasPassengerLocalErrors(index);
                                    const nameError = localErrors[`passengers.${index}.name`] || localErrors[`passengers.${index}.first_name`] || localErrors[`passengers.${index}.last_name`] || errors[`passengers.${index}.first_name`] || errors[`passengers.${index}.last_name`];
                                    const isVisaOpen = visaOpenIndexes.has(index);

                                    return (
                                        <Card key={index} className="overflow-hidden border-2 shadow-sm">
                                            {/* Collapsible header row */}
                                            <div
                                                role="button"
                                                tabIndex={0}
                                                className="flex w-full cursor-pointer select-none items-center justify-between border-b bg-primary/5 px-4 py-4 transition-colors hover:bg-primary/8"
                                                onClick={() => setExpandedPassengerIndex(isExpanded ? null : index)}
                                                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setExpandedPassengerIndex(isExpanded ? null : index); } }}
                                            >
                                                <div className="flex items-center gap-2 overflow-hidden">
                                                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground">{index + 1}</span>
                                                    <span className="font-semibold">{t(`common.${passenger.type}_passenger`)}</span>
                                                    {!isExpanded && (passenger.first_name || passenger.last_name) && (
                                                        <span className="truncate text-sm text-muted-foreground">
                                                            · {[passenger.first_name, passenger.last_name].filter(Boolean).join(' ')}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="flex shrink-0 items-center gap-2">
                                                    {!isExpanded && hasErrors && (
                                                        <span className="text-xs font-medium text-destructive">{t('common.incomplete')}</span>
                                                    )}
                                                    {isExpanded && (
                                                        <button
                                                            type="button"
                                                            title={t('common.scan_passport')}
                                                            className="flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-primary/10"
                                                            onClick={(e) => { e.stopPropagation(); setScanPassengerIndex(index); setScanPassportOpen(true); }}
                                                        >
                                                            <ScanLine className="h-3.5 w-3.5" />
                                                            {t('common.scan_passport')}
                                                        </button>
                                                    )}
                                                    {isExpanded
                                                        ? <ChevronUp className="h-4 w-4 text-muted-foreground" />
                                                        : <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                                    }
                                                </div>
                                            </div>

                                            {/* Expanded body */}
                                            {isExpanded && (
                                                <CardContent className="space-y-4 pt-6">
                                                    {/* Row 1: Gender + Full Name (joined flush inputs) */}
                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-[auto_1fr]">
                                                        <div className="space-y-2">
                                                            <Label>{t('common.gender')}</Label>
                                                            <select
                                                                className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background"
                                                                value={passenger.gender}
                                                                onChange={(event) => handlePassengerChange(index, 'gender', event.target.value)}
                                                            >
                                                                <option value="M">{t('common.male')}</option>
                                                                <option value="F">{t('common.female')}</option>
                                                            </select>
                                                            {localErrors[`passengers.${index}.gender`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.gender`]}</p>}
                                                        </div>
                                                        <div className="space-y-2">
                                                            <Label>{t('common.full_name_english')}</Label>
                                                            <div className={`flex h-9 rounded-md border ${nameError ? 'border-destructive' : 'border-input'}`}>
                                                                <input
                                                                    required
                                                                    placeholder={t('common.first_name')}
                                                                    value={passenger.first_name}
                                                                    onChange={(event) => handlePassengerChange(index, 'first_name', event.target.value)}
                                                                    className="h-full min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none placeholder:text-muted-foreground/60 border-r border-input"
                                                                />
                                                                <input
                                                                    required
                                                                    placeholder={t('common.last_name')}
                                                                    value={passenger.last_name}
                                                                    onChange={(event) => handlePassengerChange(index, 'last_name', event.target.value)}
                                                                    className="h-full min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none placeholder:text-muted-foreground/60"
                                                                />
                                                            </div>
                                                            {nameError && <p className="mt-1 text-xs text-destructive">{nameError}</p>}
                                                        </div>
                                                    </div>

                                                    {/* Row 2: Date of Birth + Nationality */}
                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                                        <div className="space-y-2">
                                                            <Label>{t('common.date_of_birth')}</Label>
                                                            <DateSelect
                                                                required
                                                                type="dob"
                                                                passengerType={passenger.type}
                                                                value={passenger.dob}
                                                                onChange={(val) => handlePassengerChange(index, 'dob', val)}
                                                                departureDate={firstSegment?.departure_time || firstSegment?.date}
                                                                returnDate={lastSegment?.departure_time || lastSegment?.date}
                                                                locale={locale}
                                                                t={t}
                                                                error={!!localErrors[`passengers.${index}.dob`]}
                                                            />
                                                            {localErrors[`passengers.${index}.dob`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.dob`]}</p>}
                                                        </div>
                                                        <div className="space-y-2">
                                                            <Label>{t('common.nationality')}</Label>
                                                            <CountrySelect
                                                                required={passportRequired}
                                                                value={passenger.nationality}
                                                                onChange={(val) => handlePassengerChange(index, 'nationality', val)}
                                                                countries={countries}
                                                                locale={locale}
                                                                t={t}
                                                                error={!!(localErrors[`passengers.${index}.nationality`] || errors[`passengers.${index}.nationality`])}
                                                            />
                                                            {(localErrors[`passengers.${index}.nationality`] || errors[`passengers.${index}.nationality`]) && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.nationality`] || errors[`passengers.${index}.nationality`]}</p>}
                                                        </div>
                                                    </div>

                                                    {/* Row 3: Passport Number + Issue Country + Expiry */}
                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                                        <div className="space-y-2">
                                                            <Label>{t('common.passport_number')}</Label>
                                                            <Input required={passportRequired} value={passenger.passport_number} className={localErrors[`passengers.${index}.passport_number`] ? 'border-destructive' : ''} onChange={(event) => handlePassengerChange(index, 'passport_number', event.target.value)} />
                                                            {localErrors[`passengers.${index}.passport_number`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.passport_number`]}</p>}
                                                        </div>
                                                        <div className="space-y-2">
                                                            <Label>{t('common.passport_issue_country')}</Label>
                                                            <CountrySelect
                                                                required={passportRequired}
                                                                value={passenger.passport_issue_country}
                                                                onChange={(val) => handlePassengerChange(index, 'passport_issue_country', val)}
                                                                countries={countries}
                                                                locale={locale}
                                                                t={t}
                                                                error={!!(localErrors[`passengers.${index}.passport_issue_country`] || errors[`passengers.${index}.passport_issue_country`])}
                                                            />
                                                            {(localErrors[`passengers.${index}.passport_issue_country`] || errors[`passengers.${index}.passport_issue_country`]) && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.passport_issue_country`] || errors[`passengers.${index}.passport_issue_country`]}</p>}
                                                        </div>
                                                        <div className="space-y-2">
                                                            <Label>{t('common.passport_expiry')}</Label>
                                                            <DateSelect
                                                                required={passportRequired}
                                                                type="passport_expiry"
                                                                passengerType={passenger.type}
                                                                value={passenger.passport_expiry}
                                                                onChange={(val) => handlePassengerChange(index, 'passport_expiry', val)}
                                                                departureDate={firstSegment?.departure_time || firstSegment?.date}
                                                                returnDate={lastSegment?.departure_time || lastSegment?.date}
                                                                locale={locale}
                                                                t={t}
                                                                error={!!localErrors[`passengers.${index}.passport_expiry`]}
                                                            />
                                                            {localErrors[`passengers.${index}.passport_expiry`] && <p className="text-xs text-destructive">{localErrors[`passengers.${index}.passport_expiry`]}</p>}
                                                        </div>
                                                    </div>

                                                    {/* Passport document attachment */}
                                                    {passenger.passport_file ? (
                                                        <div className="flex items-center gap-3 rounded-lg border border-dashed border-input bg-muted/20 p-2.5">
                                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded border bg-background">
                                                                {passenger.passport_file.type?.startsWith('image/') ? (
                                                                    <img src={URL.createObjectURL(passenger.passport_file)} alt="" className="h-full w-full object-cover" />
                                                                ) : (
                                                                    <Upload className="h-3.5 w-3.5 text-muted-foreground" />
                                                                )}
                                                            </div>
                                                            <p className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{passenger.passport_file.name}</p>
                                                            <div className="flex gap-3 text-xs">
                                                                <button type="button" className="text-primary hover:underline" onClick={() => openDocUpload(index, 'passport')}>{t('common.change')}</button>
                                                                <button type="button" className="text-destructive hover:underline" onClick={() => handlePassengerChange(index, 'passport_file', null)}>{t('common.remove')}</button>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <button type="button" className="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground" onClick={() => openDocUpload(index, 'passport')}>
                                                            <Upload className="h-3.5 w-3.5" />
                                                            {t('common.attach_document')}
                                                        </button>
                                                    )}

                                                    {/* Optional visa section */}
                                                    <div className="border-t pt-4">
                                                        <button
                                                            type="button"
                                                            className="flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                                            onClick={() => toggleVisaSection(index)}
                                                        >
                                                            {isVisaOpen
                                                                ? <ChevronUp className="h-4 w-4" />
                                                                : <Plus className="h-4 w-4" />
                                                            }
                                                            {isVisaOpen ? t('common.remove_visa_details') : t('common.add_visa_details')}
                                                        </button>

                                                        {isVisaOpen && (
                                                            <div className="mt-4 space-y-4 rounded-lg border border-dashed border-input bg-muted/20 p-4">
                                                                <div className="flex items-center justify-between">
                                                                    <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{t('common.visa_information')}</p>
                                                                    <button
                                                                        type="button"
                                                                        className="flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-primary/10"
                                                                        onClick={() => { setScanVisaPassengerIndex(index); setScanVisaOpen(true); }}
                                                                    >
                                                                        <ScanLine className="h-3.5 w-3.5" />
                                                                        {t('common.scan_visa')}
                                                                    </button>
                                                                </div>
                                                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                                                    <div className="space-y-2">
                                                                        <Label>{t('common.visa_number')}</Label>
                                                                        <Input
                                                                            value={passenger.visa_number || ''}
                                                                            onChange={(e) => handlePassengerChange(index, 'visa_number', e.target.value)}
                                                                        />
                                                                    </div>
                                                                    <div className="space-y-2">
                                                                        <Label>{t('common.visa_type')}</Label>
                                                                        <Input
                                                                            value={passenger.visa_type || ''}
                                                                            onChange={(e) => handlePassengerChange(index, 'visa_type', e.target.value)}
                                                                        />
                                                                    </div>
                                                                </div>
                                                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                                                    <div className="space-y-2">
                                                                        <Label>{t('common.visa_issue_country')}</Label>
                                                                        <CountrySelect
                                                                            value={passenger.visa_issue_country || ''}
                                                                            onChange={(val) => handlePassengerChange(index, 'visa_issue_country', val)}
                                                                            countries={countries}
                                                                            locale={locale}
                                                                            t={t}
                                                                        />
                                                                    </div>
                                                                    <div className="space-y-2">
                                                                        <Label>{t('common.visa_expiry')}</Label>
                                                                        <DateSelect
                                                                            type="passport_expiry"
                                                                            passengerType={passenger.type}
                                                                            value={passenger.visa_expiry || ''}
                                                                            onChange={(val) => handlePassengerChange(index, 'visa_expiry', val)}
                                                                            departureDate={firstSegment?.departure_time || firstSegment?.date}
                                                                            returnDate={lastSegment?.departure_time || lastSegment?.date}
                                                                            locale={locale}
                                                                            t={t}
                                                                        />
                                                                    </div>
                                                                </div>

                                                                {/* Visa document attachment */}
                                                                {passenger.visa_file ? (
                                                                    <div className="flex items-center gap-3 rounded-lg border border-dashed border-input bg-muted/20 p-2.5">
                                                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded border bg-background">
                                                                            {passenger.visa_file.type?.startsWith('image/') ? (
                                                                                <img src={URL.createObjectURL(passenger.visa_file)} alt="" className="h-full w-full object-cover" />
                                                                            ) : (
                                                                                <Upload className="h-3.5 w-3.5 text-muted-foreground" />
                                                                            )}
                                                                        </div>
                                                                        <p className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{passenger.visa_file.name}</p>
                                                                        <div className="flex gap-3 text-xs">
                                                                            <button type="button" className="text-primary hover:underline" onClick={() => openDocUpload(index, 'visa')}>{t('common.change')}</button>
                                                                            <button type="button" className="text-destructive hover:underline" onClick={() => handlePassengerChange(index, 'visa_file', null)}>{t('common.remove')}</button>
                                                                        </div>
                                                                    </div>
                                                                ) : (
                                                                    <button type="button" className="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground" onClick={() => openDocUpload(index, 'visa')}>
                                                                        <Upload className="h-3.5 w-3.5" />
                                                                        {t('common.attach_document')}
                                                                    </button>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                </CardContent>
                                            )}
                                        </Card>
                                    );
                                })}

                                <Card className="border-2 shadow-sm">
                                    <CardHeader className="border-b bg-muted/10 pb-4">
                                        <CardTitle className="flex items-center gap-2">
                                            <div className="rounded-full bg-primary/10 p-2"><Users className="h-5 w-5 text-primary" /></div>
                                            {t('common.contact_information')}
                                        </CardTitle>
                                        <CardDescription>{t('common.enter_email_phone_primary_contact')}</CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid grid-cols-1 gap-6 pt-6 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>{t('common.email_address')}</Label>
                                             <Input
                                                required
                                                type="email"
                                                value={data.customer.email}
                                                className={localErrors['customer.email'] ? 'border-destructive' : ''}
                                                onChange={(event) => handleCustomerChange('email', event.target.value)}
                                                onBlur={(event) => {
                                                    if (event.target.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(event.target.value)) {
                                                        setLocalErrors((prev) => ({ ...prev, 'customer.email': t('common.invalid_email') }));
                                                    } else {
                                                        setLocalErrors((prev) => { const next = { ...prev }; delete next['customer.email']; return next; });
                                                    }
                                                }}
                                            />
                                            {(localErrors['customer.email'] || errors['customer.email']) && <p className="text-xs text-destructive">{localErrors['customer.email'] || errors['customer.email']}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>{t('common.phone_number')}</Label>
                                             <PhoneInput
                                                required
                                                value={data.customer.phone}
                                                onChange={(val) => handleCustomerChange('phone', val)}
                                                countries={countries}
                                                locale={locale}
                                                t={t}
                                                error={!!(localErrors['customer.phone'] || errors['customer.phone'])}
                                            />
                                            {(localErrors['customer.phone'] || errors['customer.phone']) && <p className="text-xs text-destructive">{localErrors['customer.phone'] || errors['customer.phone']}</p>}
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="flex justify-end">
                                    <Button type="button" size="lg" className="rounded-full px-8 shadow-md" onClick={() => nextStep('passengers', 'extras')}>
                                        {t('common.continue_to_extras')} <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </div>
                            </TabsContent>

                            <TabsContent value="extras" className="space-y-6">
                                 <div className="space-y-6">
                                     <div>
                                         <h3 className="text-lg font-bold">{t('common.airline_services')}</h3>
                                         <p className="text-sm text-muted-foreground">{t('common.select_services_per_offer_or_apply_all')}</p>
                                     </div>

                                     {uniqueServices.length === 0 ? (
                                         <Card className="border-dashed">
                                             <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                                 {t('common.no_airline_services_available')}
                                             </CardContent>
                                         </Card>
                                     ) : (
                                         <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                             {uniqueServices.map((service) => {
                                                 const isQuantityService = service.type === 'baggage_increment' || service.pricing_mode === 'per_kg';
                                                 const selectedOffersCount = offerContexts.filter((offer) => {
                                                     const sel = getSelectedService(offer.key, service.code);

                                                     return sel && (Number(sel.quantity ?? 0) > 0 || (sel.passengers ?? []).length > 0);
                                                 }).length;
                                                 const isAnySelected = selectedOffersCount > 0;

                                                 return (
                                                     <button
                                                         key={service.code}
                                                         type="button"
                                                         onClick={() => {
                                                             setServiceModalCode(service.code);
                                                             setServiceModalOfferKey(offerContexts[0]?.key ?? 'oneway');
                                                             setServiceModalOpen(true);
                                                         }}
                                                         className={`group relative rounded-2xl border-2 bg-card p-5 text-left shadow-sm transition-all hover:border-primary/50 hover:shadow-md ${isAnySelected ? 'border-primary bg-primary/5' : 'border-border'}`}
                                                     >
                                                         <div className="flex items-start gap-3">
                                                             <div className={`rounded-full p-3 ${isAnySelected ? 'bg-primary/15' : 'bg-muted'}`}>
                                                                 {isQuantityService ? (
                                                                     <Briefcase className={`h-5 w-5 ${isAnySelected ? 'text-primary' : 'text-muted-foreground'}`} />
                                                                 ) : (
                                                                     <Settings2 className={`h-5 w-5 ${isAnySelected ? 'text-primary' : 'text-muted-foreground'}`} />
                                                                 )}
                                                             </div>
                                                             <div className="min-w-0 flex-1">
                                                                 <h4 className="text-base font-bold leading-tight">{service.label}</h4>
                                                                 <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{service.description}</p>
                                                                 <p className="mt-2 text-lg font-black text-primary">{Number(service.unit_price || 0).toFixed(2)} {currency}</p>
                                                             </div>
                                                         </div>

                                                         {isAnySelected ? (
                                                             <div className="mt-3 flex items-center gap-2">
                                                                 <CheckCircle2 className="h-4 w-4 text-primary" />
                                                                 <span className="text-xs font-bold text-primary">{t('common.added')}</span>
                                                             </div>
                                                         ) : (
                                                             <div className="mt-3">
                                                                 <span className="text-xs font-semibold text-muted-foreground">{t('common.click_to_configure')}</span>
                                                             </div>
                                                         )}
                                                     </button>
                                                 );
                                             })}
                                         </div>
                                     )}
                                 </div>

                                <Card onClick={fetchSeatMap} className="relative cursor-pointer overflow-hidden border-2 transition-all hover:border-primary/50">
                                    <CardContent className="flex items-start gap-4 p-6">
                                        <div className="rounded-full bg-muted p-3">
                                            <Armchair className="h-6 w-6" />
                                        </div>
                                        <div>
                                            <h3 className="mb-1 text-lg font-bold">{t('common.seat_selection')}</h3>
                                            <p className="mb-1 text-sm text-muted-foreground">
                                                {selectedSeatLabels.length > 0 ? `${selectedSeatLabels.length} ${t('common.seats_selected_across_offers')}` : t('common.standard_auto_assignment')}
                                            </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {selectedSeatLabels.map((label) => (
                                        <span key={label} className="rounded bg-primary/10 px-2 py-1 text-xs font-bold text-primary">
                                            {label}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                                <Card className="overflow-hidden border-2 shadow-sm">
                                    <CardContent className="flex items-start gap-4 p-6">
                                        <div className="rounded-full bg-primary/10 p-3">
                                            <Smartphone className="h-6 w-6 text-primary" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <h3 className="mb-1 text-lg font-bold">{t('esim.upsell.title')}</h3>
                                            <p className="text-sm text-muted-foreground">{t('esim.upsell.description')}</p>

                                            {loadingEsim && (
                                                <div className="mt-3 flex items-center gap-2 text-sm text-muted-foreground">
                                                    <Loader2 className="h-4 w-4 animate-spin text-primary" />
                                                    {t('esim.upsell.loading')}
                                                </div>
                                            )}

                                            {!loadingEsim && esimPackages.length === 0 && (
                                                <p className="mt-3 text-sm italic text-muted-foreground">{t('esim.upsell.no_packages')}</p>
                                            )}

                                            {!loadingEsim && esimPackages.length > 0 && !data.extras.esim_selection && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="mt-3 rounded-full"
                                                    onClick={() => setEsimModalOpen(true)}
                                                >
                                                    {t('esim.upsell.add_package')}
                                                </Button>
                                            )}

                                            {data.extras.esim_selection && (
                                                <div className="mt-3 space-y-2">
                                                    <div className="flex items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 px-3 py-2">
                                                        <CheckCircle2 className="h-4 w-4 shrink-0 text-primary" />
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-sm font-bold text-primary">{data.extras.esim_selection.name}</p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {Number(data.extras.esim_selection.price).toFixed(2)} {data.extras.esim_selection.currency}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="flex gap-2">
                                                        <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs font-bold text-primary" onClick={() => setEsimModalOpen(true)}>
                                                            {t('esim.upsell.change_package')}
                                                        </Button>
                                                        <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs text-destructive" onClick={removeEsimPackage}>
                                                            {t('esim.upsell.remove')}
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="mt-8 flex items-center justify-between border-t pt-8">
                                    <Button type="button" variant="ghost" className="font-bold" onClick={() => prevStep('passengers')}>
                                        <ChevronLeft className="mr-2 h-4 w-4" /> {t('common.back_to_passengers')}
                                    </Button>
                                    <Button type="button" size="lg" className="rounded-full px-8 shadow-md" onClick={() => nextStep('extras', 'review')}>
                                        {t('common.continue_to_review')} <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </div>
                            </TabsContent>

                            <TabsContent value="review" className="space-y-6">
                                <Card className="border-2 shadow-sm overflow-hidden">
                                    <CardHeader className="border-b bg-muted/10 pb-4">
                                        <CardTitle className="flex items-center gap-2">
                                            <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                                            {t('common.review_your_details')}
                                        </CardTitle>
                                        <CardDescription>{t('common.double_check_before_finalizing')}</CardDescription>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <div className="p-6 space-y-8">
                                            <div>
                                                <p className="text-xs font-black uppercase tracking-widest text-primary mb-4">{t('common.passenger_details')}</p>
                                                <div className="grid gap-4">
                                                    {data.passengers.map((p, i) => (
                                                        <div key={i} className="flex justify-between items-center p-4 rounded-xl border bg-muted/5">
                                                            <div>
                                                                <p className="font-bold">{p.first_name} {p.last_name}</p>
                                                                <p className="text-xs text-muted-foreground uppercase font-black tracking-tighter">{p.type} • {p.gender} • DOB: {p.dob}</p>
                                                            </div>
                                                            <div className="text-right">
                                                                <p className="text-xs font-bold text-muted-foreground">{t('common.passport')}</p>
                                                                <p className="text-sm font-black">{p.passport_number}</p>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-2 gap-8 border-t pt-8">
                                                <div>
                                                    <p className="text-xs font-black uppercase tracking-widest text-primary mb-2">{t('common.primary_contact')}</p>
                                                    <p className="font-bold">{data.customer.first_name} {data.customer.last_name}</p>
                                                    <p className="text-sm text-muted-foreground">{data.customer.email}</p>
                                                    <p className="text-sm text-muted-foreground">{data.customer.phone}</p>
                                                </div>
                                                <div>
                                     <p className="text-xs font-black uppercase tracking-widest text-primary mb-2">{t('common.selected_extras')}</p>
                                    {ancillaryLines.length > 0 || selectedSeatLabels.length > 0 || data.extras.esim_selection ? (
                                        <ul className="space-y-1">
                                            {ancillaryLines.map(l => (
                                                <li key={`${l.offer_key}-${l.code}`} className="text-sm font-medium">{l.offer_label}: {l.label} ({l.quantity})</li>
                                            ))}
                                            {selectedSeatLabels.map(s => (
                                                <li key={s} className="text-sm font-medium">Seat: {s}</li>
                                            ))}
                                            {data.extras.esim_selection && (
                                                <li className="text-sm font-medium">
                                                    {t('esim.upsell.add_on')}: {data.extras.esim_selection.name} — {Number(data.extras.esim_selection.price).toFixed(2)} {data.extras.esim_selection.currency}
                                                </li>
                                            )}
                                        </ul>
                                    ) : (
                                         <p className="text-sm text-muted-foreground italic">{t('common.no_extras_selected')}</p>
                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="mt-8 border-t pt-8 space-y-6">
                                    <div>
                                        <p className="text-xs font-black uppercase tracking-widest text-primary mb-3">{t('common.select_ticketing_mode')}</p>
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <button
                                                type="button"
                                                onClick={() => setData('ticketing_mode', 'final')}
                                                className={`flex items-start gap-3 rounded-xl border-2 p-4 text-start transition-all ${data.ticketing_mode === 'final' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40'}`}
                                            >
                                                <div className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 transition-all ${data.ticketing_mode === 'final' ? 'border-primary' : 'border-muted-foreground/50'}`}>
                                                    {data.ticketing_mode === 'final' && <div className="h-2 w-2 rounded-full bg-primary" />}
                                                </div>
                                                <div>
                                                    <p className={`font-black ${data.ticketing_mode === 'final' ? 'text-primary' : ''}`}>{t('common.final_issue')}</p>
                                                    <p className="mt-0.5 text-sm text-muted-foreground">{t('common.final_issue_description')}</p>
                                                </div>
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() => setData('ticketing_mode', 'draft')}
                                                className={`flex items-start gap-3 rounded-xl border-2 p-4 text-start transition-all ${data.ticketing_mode === 'draft' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40'}`}
                                            >
                                                <div className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 transition-all ${data.ticketing_mode === 'draft' ? 'border-primary' : 'border-muted-foreground/50'}`}>
                                                    {data.ticketing_mode === 'draft' && <div className="h-2 w-2 rounded-full bg-primary" />}
                                                </div>
                                                <div>
                                                    <p className={`font-black ${data.ticketing_mode === 'draft' ? 'text-primary' : ''}`}>{t('common.draft_issue')}</p>
                                                    <p className="mt-0.5 text-sm text-muted-foreground">{t('common.draft_issue_description')}</p>
                                                </div>
                                            </button>
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between">
                                        <Button type="button" variant="ghost" className="font-bold" onClick={() => prevStep('extras')}>
                                            <ChevronLeft className="mr-2 h-4 w-4" /> {t('common.back_to_extras')}
                                        </Button>
                                        <Button type="submit" size="lg" className="rounded-full px-12 text-lg font-black shadow-xl" disabled={processing}>
                                            {processing
                                                ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> {t('common.processing')}</>
                                                : data.ticketing_mode === 'draft'
                                                    ? t('common.confirm_save')
                                                    : t('common.confirm_issue')
                                            }
                                        </Button>
                                    </div>
                                </div>
                            </TabsContent>
                        </Tabs>
                        {/* Hidden input for direct document attachment (without OCR scanning) */}
                        <input
                            ref={docUploadInputRef}
                            type="file"
                            accept="image/jpeg,image/jpg,image/png,image/webp,application/pdf"
                            className="hidden"
                            onChange={handleDocUpload}
                        />
                    </form>
                </div>

                <div className="hidden lg:block">
                    <div className="sticky top-8">
                        <Card className="overflow-hidden border-2 shadow-lg mt-2">
                            <div className="bg-primary p-6 text-primary-foreground">
                                <h3 className="text-xl font-black">{t('common.trip_summary')}</h3>
                            </div>
                            <CardContent className="p-0">
                                <div className="space-y-4 border-b bg-muted/10 p-6">
                                    <div className="mt-2 flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t('common.passengers')}</span>
                                        <span className="text-right">
                                            {searchParams?.adults > 0 && <span>{searchParams.adults} {t('common.adult_s')}<br /></span>}
                                            {searchParams?.children > 0 && <span>{searchParams.children} {t('common.child_ren')}<br /></span>}
                                            {searchParams?.infants > 0 && <span>{searchParams.infants} {t('common.infant_s')}</span>}
                                        </span>
                                    </div>

                                    <div className="space-y-3 border-t pt-4">
                                        <p className="text-xs font-black uppercase tracking-widest text-primary">{t('common.flight_itineraries')}</p>
                                        {offerContexts.map((offer) => (
                                            <div key={offer.key} className="rounded-xl border bg-background/80 p-3">
                                                <p className="mb-1 text-xs font-bold uppercase tracking-wide text-muted-foreground">{offer.label || offer.flight?.airline_name || t('common.airline')}</p>
                                                <div className="space-y-1 mt-1">
                                                    {offer.segments.map((segment, index) => {
                                                        const airlineCode = (segment.airline_code || offer.flight?.airline_code || '').toUpperCase();
                                                        return (
                                                            <div key={`${offer.key}-${index}`} className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground leading-tight">
                                                                {airlineCode ? (
                                                                    <>
                                                                        <img
                                                                            src={route('api.airlines.logo', { code: airlineCode, variant: 'icon-transparent', radius: 4 })}
                                                                            alt={airlineCode}
                                                                            className="h-5 w-5 shrink-0 object-contain"
                                                                            onError={(e) => { e.target.style.display = 'none'; e.target.nextSibling.style.display = 'inline'; }}
                                                                        />
                                                                        <Plane className="hidden h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
                                                                    </>
                                                                ) : (
                                                                    <Plane className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
                                                                )}
                                                                {segment.cabin && <><span className="text-foreground font-semibold">{getCabinName(segment.cabin)}</span><span className="text-muted-foreground/40">·</span></>}
                                                                {segment.flight_number && <><span className="font-semibold text-foreground">{segment.flight_number}</span><span className="text-muted-foreground/40">·</span></>}
                                                                <span>{segment.departure_airport || segment.origin} → {segment.arrival_airport || segment.destination}</span>
                                                                <span className="text-muted-foreground/40">·</span>
                                                                <span>{formatSegmentDateTime(segment.departure_time || segment.date)}</span>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                                {Number(offer.flight?.pricing?.total) > 0 && (
                                                    <div className="mt-2 flex items-center justify-between border-t border-border/40 pt-2">
                                                        <span className="text-xs text-muted-foreground">{t('common.fare')}</span>
                                                        <span className="text-xs font-black text-primary">
                                                            {Number(offer.flight.pricing.total).toFixed(2)} {getCurrencyName(offer.flight.pricing.currency) || currency}
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-3 p-6">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t('common.base_flight_fare')}</span>
                                        <span>{providerPrice.toFixed(2)} {currency}</span>
                                    </div>
                                    {ancillaryLines.map((line) => (
                                        <div key={`${line.offer_key}-${line.code}`} className="flex justify-between text-sm font-medium text-muted-foreground">
                                            <span>{line.offer_label}: {line.label}{line.quantity > 1 ? ` x${line.quantity}` : ''}</span>
                                            <span>+{line.total.toFixed(2)} {currency}</span>
                                        </div>
                                    ))}
                                </div>

                                <div className="flex items-end justify-between border-t bg-muted/30 p-6">
                                    <span className="font-bold text-muted-foreground">{t('common.total_to_pay')}</span>
                                    <span className="text-3xl font-black text-primary">{grandTotal.toFixed(2)} <span className="text-sm">{currency}</span></span>
                                </div>

                                {data.extras.esim_selection && (
                                    <div className="space-y-1 border-t px-6 pb-4 pt-4">
                                        <p className="text-xs font-black uppercase tracking-widest text-primary">{t('esim.upsell.add_on')}</p>
                                        <div className="flex justify-between text-sm font-medium">
                                            <span className="text-muted-foreground">{data.extras.esim_selection.name}</span>
                                            <span className="font-bold">{Number(data.extras.esim_selection.price).toFixed(2)} {data.extras.esim_selection.currency}</span>
                                        </div>
                                        <p className="text-xs text-muted-foreground">{t('esim.upsell.billed_separately')}</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <div className="mt-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-4 text-amber-800">
                            <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" />
                            <p className="text-xs font-semibold leading-relaxed">
                                {t('common.booking_confirmation_message')}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <DocumentScanModal
                open={scanPassportOpen}
                onOpenChange={setScanPassportOpen}
                onSuccess={handleFillFromScan}
                title={t('common.scan_passport')}
                description={t('common.upload_passport_image')}
                t={t}
            />

            <DocumentScanModal
                open={scanVisaOpen}
                onOpenChange={setScanVisaOpen}
                onSuccess={handleFillFromVisaScan}
                title={t('common.scan_visa')}
                description={t('common.upload_visa_image')}
                t={t}
            />

            <Dialog open={isSeatMapOpen} onOpenChange={setIsSeatMapOpen}>
                <DialogContent className="max-h-[90vh] max-w-4xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{t('common.select_your_seats')}</DialogTitle>
                        <DialogDescription>{t('common.select_seats_for_each_passenger')}</DialogDescription>
                    </DialogHeader>

                    {loadingSeatMap ? (
                        <div className="flex flex-col items-center justify-center space-y-4 py-20">
                            <Loader2 className="h-10 w-10 animate-spin text-primary" />
                            <p className="font-medium text-muted-foreground">{t('common.loading_seat_map')}</p>
                        </div>
                    ) : !offerContexts.some((offer) => seatMapByOffer[offer.key]) ? (
                        <div className="py-10 text-center text-muted-foreground">{t('common.could_not_load_seat_map')}</div>
                    ) : (
                        <Tabs value={activeOfferKeyForSeat} onValueChange={setActiveOfferKeyForSeat} className="mt-4 w-full">
                            <TabsList className={`grid w-full ${offerContexts.length > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
                                {offerContexts.map((offer) => (
                                    <TabsTrigger key={offer.key} value={offer.key}>{offer.label}</TabsTrigger>
                                ))}
                            </TabsList>

                            {offerContexts.map((offer) => (
                                <TabsContent key={offer.key} value={offer.key} className="mt-4">
                                    {!seatMapByOffer[offer.key] ? (
                                        <div className="py-10 text-center text-muted-foreground">{t('common.could_not_load_seat_map_for_offer')}</div>
                                    ) : (
                                        <div className="flex flex-col gap-8 lg:flex-row">
                                            <div className="flex flex-1 justify-center overflow-x-auto pb-8">
                                                <div className="flex flex-col gap-1 md:gap-2">
                                                    {generateGrid(offer.key).map((rowArray, rowIndex) => (
                                                        <div key={`${offer.key}-row-${rowIndex}`} className="flex min-w-max items-center justify-center gap-1 md:gap-2">
                                                            {rowArray.map((seat, colIndex) => {
                                                                if (!seat) {
                                                                    return <div key={`empty-${offer.key}-${rowIndex}-${colIndex}`} className="h-8 w-8 opacity-0" />;
                                                                }

                                                                const description = seat.description || '';
                                                                const isTextHeader = description.length === 1 && /[A-Z]/.test(description);

                                                                if (isTextHeader) {
                                                                    return (
                                                                        <div key={`header-${offer.key}-${rowIndex}-${colIndex}`} className="w-10 pb-2 text-center font-bold text-slate-500 md:w-11">
                                                                            {description}
                                                                        </div>
                                                                    );
                                                                }

                                                                if (seat.is_aisle || description.includes('WidthMarker') || description.includes('Door') || description.includes('Wing')) {
                                                                    return <div key={`spacer-${offer.key}-${rowIndex}-${colIndex}`} className="h-8 w-6 select-none text-transparent">.</div>;
                                                                }

                                                                const bookedCabin = offer.flight?.pricing?.cabin_type || offer.segments?.[0]?.cabin_type || 'Y';
                                                                const isOccupied = seat.is_occupied;
                                                                const isWrongCabin = seat.cabinType && seat.cabinType !== bookedCabin;
                                                                const assignedSeats = Object.entries(data.extras.seats?.[offer.key] ?? {});
                                                                const selectedAssignment = assignedSeats.find(([, assignedSeatCode]) => assignedSeatCode === seat.code);
                                                                const paxNumberAssigned = selectedAssignment ? Number(selectedAssignment[0]) + 1 : null;
                                                                const isSelected = Boolean(selectedAssignment);
                                                                const activePassenger = data.passengers[activePaxIndexForSeat];
                                                                const disableForInfant = seat.no_infant && activePassenger?.type === 'infant';
                                                                const isDisabled = isOccupied || disableForInfant || isWrongCabin;

                                                                let buttonClasses = 'bg-white border-slate-300 hover:border-primary text-slate-700 hover:shadow-sm';

                                                                if (isSelected) {
                                                                    buttonClasses = 'bg-primary border-primary text-primary-foreground shadow-md scale-105 z-10';
                                                                } else if (isDisabled && isWrongCabin) {
                                                                    buttonClasses = 'bg-red-50/50 border-red-200 text-red-300 cursor-not-allowed opacity-50';
                                                                } else if (isDisabled) {
                                                                    buttonClasses = 'bg-slate-200 border-slate-300 text-slate-400 cursor-not-allowed opacity-60';
                                                                }

                                                                return (
                                                                    <button
                                                                        key={`seat-${offer.key}-${rowIndex}-${colIndex}-${seat.code}`}
                                                                        type="button"
                                                                        disabled={isDisabled}
                                                                        onClick={() => handleSeatSelection(offer.key, seat.code)}
                                                                        className={`flex h-10 w-10 flex-col items-center justify-center rounded-b-sm rounded-t-lg border-2 transition-all focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-1 md:h-12 md:w-11 ${buttonClasses}`}
                                                                        title={seat.code}
                                                                    >
                                                                        {isSelected && <span className="mb-0.5 text-[10px] font-black leading-none opacity-80">P{paxNumberAssigned}</span>}
                                                                        <span className={`text-xs font-bold leading-none ${isSelected ? 'text-white' : ''}`}>{seat.code}</span>
                                                                    </button>
                                                                );
                                                            })}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="flex w-full flex-col gap-4 lg:w-1/3">
                                                <h4 className="border-b pb-2 text-lg font-bold">{t('common.assign_passengers')}</h4>
                                                <div className="flex flex-col gap-2">
                                                    {data.passengers.map((passenger, index) => {
                                                        const assignedSeat = data.extras.seats?.[offer.key]?.[index];
                                                        const isActive = activePaxIndexForSeat === index;

                                                        return (
                                                            <button
                                                                key={`${offer.key}-pax-${index}`}
                                                                type="button"
                                                                className={`rounded-2xl border px-4 py-3 text-left transition ${isActive ? 'border-primary bg-primary/5 shadow-sm' : 'hover:border-primary/40'}`}
                                                                onClick={() => setActivePaxIndexForSeat(index)}
                                                            >
                                                                <div className="flex items-center justify-between gap-3">
                                                                    <div>
                                                                        <p className="font-bold">{t('common.pax')} {index + 1}</p>
                                                                        <p className="text-sm text-muted-foreground capitalize">{passenger.type}</p>
                                                                    </div>
                                                                    <div className="text-right">
                                                                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{t('common.seat')}</p>
                                                                        <p className="font-black text-primary">{assignedSeat || t('common.auto')}</p>
                                                                    </div>
                                                                </div>
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </TabsContent>
                            ))}
                        </Tabs>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={serviceModalOpen} onOpenChange={setServiceModalOpen}>
                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{activeModalService?.label}</DialogTitle>
                        <DialogDescription>{activeModalService?.description}</DialogDescription>
                    </DialogHeader>

                    {activeModalService && (
                        <div className="mt-4">
                            {offerContexts.length > 1 ? (
                                <Tabs value={serviceModalOfferKey} onValueChange={setServiceModalOfferKey} className="w-full">
                                    <TabsList className={`grid w-full grid-cols-${offerContexts.length}`}>
                                        {offerContexts.map((offer) => (
                                            <TabsTrigger key={offer.key} value={offer.key}>{offer.label}</TabsTrigger>
                                        ))}
                                    </TabsList>

                                    {offerContexts.map((offer) => (
                                        <TabsContent key={offer.key} value={offer.key} className="mt-4">
                                            {renderServiceOfferControls(offer, activeModalService)}
                                        </TabsContent>
                                    ))}
                                </Tabs>
                            ) : offerContexts.length === 1 ? (
                                renderServiceOfferControls(offerContexts[0], activeModalService)
                            ) : null}
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={esimModalOpen} onOpenChange={setEsimModalOpen}>
                <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{t('esim.upsell.choose_package')}</DialogTitle>
                        <DialogDescription>{t('esim.upsell.description')}</DialogDescription>
                    </DialogHeader>
                    <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {esimPackages.map((pkg) => {
                            const isSelected = data.extras.esim_selection?.package_id === pkg.id;
                            const dataMb = Number(pkg.data_mb || 0);
                            const dataLabel = pkg.unlimited
                                ? t('esim.results.unlimited')
                                : dataMb >= 1024
                                    ? `${(dataMb / 1024).toFixed(1)} ${t('esim.results.gb_short')}`
                                    : `${dataMb} ${t('esim.results.mb_short')}`;

                            return (
                                <button
                                    key={pkg.id}
                                    type="button"
                                    onClick={() => selectEsimPackage(pkg)}
                                    className={`relative flex flex-col gap-2 rounded-2xl border-2 p-4 text-left transition-all focus:outline-none focus:ring-2 focus:ring-primary ${isSelected ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50'}`}
                                >
                                    {isSelected && (
                                        <span className="absolute end-3 top-3">
                                            <CheckCircle2 className="h-4 w-4 text-primary" />
                                        </span>
                                    )}
                                    <p className="pe-6 text-sm font-bold leading-snug">{pkg.name}</p>
                                    <div className="flex flex-wrap gap-2 text-xs">
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 font-black text-primary">{dataLabel}</span>
                                        <span className="rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground">{pkg.validity_days} {t('esim.results.days_short')}</span>
                                    </div>
                                    <p className="text-lg font-black text-primary">
                                        {Number(pkg.price).toFixed(2)} <span className="text-xs font-semibold">{pkg.currency}</span>
                                    </p>
                                </button>
                            );
                        })}
                    </div>
                </DialogContent>
            </Dialog>
        </TenantNavbarLayout>
    );
}
