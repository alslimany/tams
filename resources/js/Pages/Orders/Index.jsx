import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent } from '@/Components/ui/Card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { formatMoney } from '@/lib/currency';
import { useTranslation } from '@/hooks/useTranslation';
import {
    CalendarDays,
    ChevronRight,
    CircleDollarSign,
    Hotel,
    Plane,
    ShieldCheck,
    Smartphone,
} from 'lucide-react';

const statusStyles = (status) => {
    const normalizedStatus = String(status ?? '').toLowerCase();

    if (['issued', 'paid', 'confirmed'].includes(normalizedStatus)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (['voided', 'cancelled', 'refunded'].includes(normalizedStatus)) {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }

    if (normalizedStatus === 'cancellation') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    return 'border-slate-200 bg-slate-100 text-slate-700';
};

const valueOrFallback = (...values) => values.find((value) => value !== undefined && value !== null && String(value).trim() !== '') ?? '-';

const stripHtml = (value) => String(value ?? '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

const isLongToken = (value) => String(value ?? '').length > 28;

export default function Index({ orders }) {
    const { t, locale } = useTranslation();
    const rows = orders?.data ?? [];
    const totalOrders = orders?.total ?? rows.length;

    function formatDate(value, options = {}) {
        if (!value) {
            return '-';
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return '-';
        }

        return date.toLocaleDateString(locale, {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            ...options,
        });
    }

    function formatDateTime(value) {
        if (!value) {
            return '-';
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return '-';
        }

        return date.toLocaleString(locale, {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function productType(item) {
        const type = String(item?.type ?? item?.product_type ?? '').toLowerCase();
        const subtype = String(item?.product_subtype ?? '').toLowerCase();

        if (type.includes('hotel') || subtype.includes('hotel')) {
            return 'hotel';
        }

        if (type.includes('insurance') || ['travel', 'orange', 'compulsory'].includes(subtype)) {
            return 'insurance';
        }

        if (type.includes('esim') || subtype.includes('esim')) {
            return 'esim';
        }

        return 'flight';
    }

    function productIcon(item) {
        const type = productType(item);

        if (type === 'hotel') {
            return Hotel;
        }

        if (type === 'insurance') {
            return ShieldCheck;
        }

        if (type === 'esim') {
            return Smartphone;
        }

        return Plane;
    }

    function productLabel(item) {
        const type = productType(item);
        const subtype = String(item?.product_subtype ?? '').toLowerCase();

        if (type === 'hotel') {
            return t('orders.product_hotel');
        }

        if (type === 'esim') {
            return t('orders.product_esim');
        }

        if (type === 'insurance') {
            if (subtype === 'orange') {
                return t('orders.product_orange_insurance');
            }

            if (subtype === 'travel') {
                return t('orders.product_travel_insurance');
            }

            return t('orders.product_compulsory_insurance');
        }

        return t('orders.product_flight');
    }

    function contactName(order) {
        const firstName = String(order?.contact?.first_name ?? order?.contact?.firstName ?? '').trim();
        const lastName = String(order?.contact?.last_name ?? order?.contact?.lastName ?? '').trim();
        const directName = String(order?.contact?.full_name ?? order?.contact?.name ?? '').trim();
        const fullName = `${firstName} ${lastName}`.trim();

        return directName || fullName || order?.owner?.name || '-';
    }

    function itemReference(item) {
        const policyDetails = item?.product_details?.policy_details ?? {};
        const details = item?.item_details ?? {};
        const product = item?.product_details ?? {};
        const type = productType(item);

        if (type === 'flight') {
            return valueOrFallback(item?.provider_reference, details?.rloc, details?.pnr);
        }

        if (type === 'hotel') {
            return valueOrFallback(details?.booking_id, item?.provider_reference, details?.booking_ref, item?.ticket_number);
        }

        if (type === 'insurance') {
            return valueOrFallback(
                policyDetails?.policy_number,
                policyDetails?.card_number,
                product?.policy_number,
                item?.ticket_number,
                policyDetails?.policy_id,
                item?.provider_reference,
            );
        }

        return valueOrFallback(item?.ticket_number, item?.provider_reference);
    }

    function safeItemReference(item) {
        const reference = String(itemReference(item));

        if (reference === '-' || isLongToken(reference)) {
            return productType(item) === 'insurance' ? t('orders.policy_issued') : '-';
        }

        return reference;
    }

    function itemTitle(item) {
        const details = item?.item_details ?? {};
        const product = item?.product_details ?? {};
        const policyDetails = product?.policy_details ?? {};
        const type = productType(item);

        if (type === 'flight') {
            return safeItemReference(item) === '-' ? t('orders.flight_booking') : `${t('orders.pnr')} ${safeItemReference(item)}`;
        }

        if (type === 'hotel') {
            return valueOrFallback(product?.hotel?.name, product?.hotel?.hotel_name, details?.provider_booking?.hotel?.hotelName, t('orders.hotel_booking'));
        }

        if (type === 'insurance') {
            return valueOrFallback(policyDetails?.policy_number, item?.ticket_number, product?.policy_number, t('orders.policy_issued'));
        }

        return safeItemReference(item);
    }

    function itemSubtitle(item) {
        const details = item?.item_details ?? {};
        const product = item?.product_details ?? {};
        const policyDetails = product?.policy_details ?? {};
        const type = productType(item);

        if (type === 'flight') {
            const itineraries = Array.isArray(details?.itineraries) ? details.itineraries : [];
            const firstItinerary = itineraries[0] ?? {};
            const origin = valueOrFallback(firstItinerary?.from, firstItinerary?.origin, firstItinerary?.departure_airport);
            const destination = valueOrFallback(firstItinerary?.to, firstItinerary?.destination, firstItinerary?.arrival_airport);
            const travelDate = formatDate(valueOrFallback(firstItinerary?.date, firstItinerary?.departure_time));

            return `${origin} → ${destination} · ${travelDate}`;
        }

        if (type === 'hotel') {
            const stay = product?.stay ?? details?.provider_booking ?? {};
            const from = valueOrFallback(stay?.from, details?.search?.check_in);
            const to = valueOrFallback(stay?.to, details?.search?.check_out);

            return `${formatDate(from)} → ${formatDate(to)}`;
        }

        if (type === 'insurance') {
            const start = valueOrFallback(policyDetails?.policy_date_from, policyDetails?.PolicyDateFrom, product?.beneficiary?.policy_date_from);
            const end = valueOrFallback(policyDetails?.policy_date_to, policyDetails?.PolicyDateTo, product?.beneficiary?.policy_date_to);
            const beneficiary = valueOrFallback(product?.beneficiary?.name, details?.beneficiary?.name, policyDetails?.vehicle_owner_name);

            return [beneficiary, `${formatDate(start)} → ${formatDate(end)}`].filter((value) => value !== '-').join(' · ') || t('orders.policy_issued');
        }

        return stripHtml(item?.provider ?? '');
    }

    function primaryItem(order) {
        return order?.items?.[0] ?? null;
    }

    function orderTotal(order) {
        return Number(order?.amount_paid ?? order?.grand_total ?? 0);
    }

    function renderProductSummary(order) {
        const item = primaryItem(order);

        if (!item) {
            return <span className="text-sm text-muted-foreground">{t('orders.no_items')}</span>;
        }

        const Icon = productIcon(item);
        const extraItemsCount = Math.max(0, Number(order?.items_count ?? order?.items?.length ?? 1) - 1);

        return (
            <div className="flex min-w-0 items-start gap-3">
                <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-700">
                    <Icon className="size-4" />
                </span>
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="border-slate-200 bg-white px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">
                            {productLabel(item)}
                        </Badge>
                        <Badge variant="outline" className={statusStyles(item.status)}>
                            {item.status ?? 'pending'}
                        </Badge>
                        {extraItemsCount > 0 ? (
                            <span className="text-xs font-medium text-muted-foreground">+{extraItemsCount}</span>
                        ) : null}
                    </div>
                    <p className="truncate text-sm font-semibold text-slate-950">{itemTitle(item)}</p>
                    <p className="truncate text-xs text-slate-500">{itemSubtitle(item)}</p>
                </div>
            </div>
        );
    }

    return (
        <TenantSidebarLayout>
            <Head title={t('orders.title')} />

            <div className="space-y-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-950">{t('orders.title')}</h1>
                        <p className="text-sm text-muted-foreground">{t('orders.description')}</p>
                    </div>
                    <Badge variant="outline" className="w-fit border-slate-200 bg-white text-slate-700">
                        {t('orders.count', { count: totalOrders })}
                    </Badge>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <CircleDollarSign className="size-5 text-primary" />
                            <div>
                                <p className="text-xs text-muted-foreground">{t('orders.total_orders')}</p>
                                <p className="text-xl font-bold tabular-nums text-slate-950">{totalOrders}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <CalendarDays className="size-5 text-primary" />
                            <div>
                                <p className="text-xs text-muted-foreground">{t('orders.latest_order')}</p>
                                <p className="text-xl font-bold tabular-nums text-slate-950">{formatDate(rows[0]?.issued_at ?? rows[0]?.created_at)}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <ShieldCheck className="size-5 text-primary" />
                            <div>
                                <p className="text-xs text-muted-foreground">{t('orders.visible_items')}</p>
                                <p className="text-xl font-bold tabular-nums text-slate-950">{rows.reduce((total, order) => total + Number(order.items_count ?? 0), 0)}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardContent className="p-0">
                        {rows.length === 0 ? (
                            <div className="p-8 text-center text-muted-foreground">
                                {t('orders.empty')}
                            </div>
                        ) : (
                            <>
                                <div className="divide-y md:hidden">
                                    {rows.map((order) => (
                                        <Link key={order.id} href={route('orders.show', { order: order.id })} className="block p-4 transition-colors hover:bg-slate-50">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0 space-y-1">
                                                    <p className="font-semibold text-slate-950">{order.number}</p>
                                                    <p className="text-xs text-muted-foreground">{contactName(order)}</p>
                                                </div>
                                                <Badge variant="outline" className={statusStyles(order.status)}>{order.status}</Badge>
                                            </div>
                                            <div className="mt-3">{renderProductSummary(order)}</div>
                                            <div className="mt-3 flex items-center justify-between gap-3 text-sm">
                                                <span className="font-semibold tabular-nums text-slate-950">{formatMoney(orderTotal(order), order.currency)}</span>
                                                <span className="text-xs text-muted-foreground">{formatDateTime(order.issued_at ?? order.created_at)}</span>
                                            </div>
                                        </Link>
                                    ))}
                                </div>

                                <div className="hidden md:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>{t('orders.table_order')}</TableHead>
                                                <TableHead>{t('orders.table_product')}</TableHead>
                                                <TableHead>{t('orders.table_customer')}</TableHead>
                                                <TableHead className="text-end">{t('orders.table_total')}</TableHead>
                                                <TableHead>{t('orders.table_issued')}</TableHead>
                                                <TableHead className="text-end">{t('orders.table_action')}</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {rows.map((order) => (
                                                <TableRow key={order.id} className="hover:bg-slate-50/80">
                                                    <TableCell className="w-44">
                                                        <div className="space-y-1">
                                                            <Link href={route('orders.show', { order: order.id })} className="font-semibold text-primary hover:underline">
                                                                {order.number}
                                                            </Link>
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <Badge variant="outline" className={statusStyles(order.status)}>
                                                                    {order.status}
                                                                </Badge>
                                                                <span className="text-xs text-muted-foreground">{t('orders.items_count', { count: order.items_count ?? 0 })}</span>
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="min-w-100 max-w-140">
                                                        {renderProductSummary(order)}
                                                    </TableCell>
                                                    <TableCell className="w-56">
                                                        <p className="truncate font-medium text-slate-950">{contactName(order)}</p>
                                                        <p className="truncate text-xs text-muted-foreground">{valueOrFallback(order.contact?.email, order.owner?.email)}</p>
                                                    </TableCell>
                                                    <TableCell className="w-36 text-end font-semibold tabular-nums text-slate-950">
                                                        {formatMoney(orderTotal(order), order.currency)}
                                                    </TableCell>
                                                    <TableCell className="w-44 text-sm text-muted-foreground">
                                                        {formatDateTime(order.issued_at ?? order.created_at)}
                                                    </TableCell>
                                                    <TableCell className="w-24 text-end">
                                                        <Button asChild size="sm" variant="outline" className="h-8 gap-1">
                                                            <Link href={route('orders.show', { order: order.id })}>
                                                                {t('common.view')}
                                                                <ChevronRight className="size-4 rtl:rotate-180" />
                                                            </Link>
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                {orders?.links?.length > 3 ? (
                    <div className="flex flex-wrap justify-end gap-2">
                        {orders.links.map((link, index) => (
                            <Button
                                key={`${link.label}-${index}`}
                                asChild={Boolean(link.url)}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={!link.url}
                            >
                                {link.url ? (
                                    <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                )}
                            </Button>
                        ))}
                    </div>
                ) : null}
            </div>
        </TenantSidebarLayout>
    );
}
