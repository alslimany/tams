import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Plane, ArrowLeft, ArrowRightLeft, Users, AlertTriangle, CheckCircle2, ChevronRight, Loader2 } from 'lucide-react';
import { formatMoney } from '@/lib/currency';
import { useTranslation } from '@/hooks/useTranslation';

export default function ChangeReview({
    order,
    item,
    segment_line,
    new_segment_code,
    reservation_type,
    flight,
    original_segment,
    passengers,
    penalty_amount,
    currency,
}) {
    const { t } = useTranslation();
    const [submitting, setSubmitting] = React.useState(false);

    const formatTime = (value) => {
        if (!value) {
            return '-';
        }

        const parts = String(value).split(' ');

        return parts.length > 1 ? parts[1].substring(0, 5) : value;
    };

    const formatDate = (value) => {
        if (!value) {
            return '-';
        }

        const datePart = String(value).split(' ')[0];

        try {
            return new Date(datePart).toLocaleDateString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            });
        } catch {
            return datePart;
        }
    };

    const handleConfirm = () => {
        setSubmitting(true);
        router.post(
            route('tickets.confirmChange', { booking: order.id, ticket: item.id }),
            {
                segment_line,
                new_segment_code,
                reservation_type,
                outstanding_amount: penalty_amount ?? 0,
            },
            {
                onError: () => setSubmitting(false),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const pnr = item.provider_reference ?? item.ticket_number ?? '-';
    const hasPenalty = penalty_amount !== null && penalty_amount !== undefined;
    const penaltyIsZero = hasPenalty && Number(penalty_amount) === 0;

    return (
        <TenantNavbarLayout>
            <Head title={t('orders.change_review_title')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                {/* Back link */}
                <div>
                    <Link
                        href={route('flights.change-offers', { booking: order.id, ticket: item.id })}
                        className="inline-flex items-center gap-2 text-sm font-semibold text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        {t('common.back')}
                    </Link>
                </div>

                {/* Page header */}
                <div>
                    <h1 className="text-3xl font-black tracking-tight">{t('orders.change_review_title')}</h1>
                    <p className="mt-1 text-muted-foreground">{t('orders.change_review_description')}</p>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Left column: old segment + new offer + passengers */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Context banner */}
                        <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3">
                            <div className="flex flex-wrap items-center gap-2 text-sm text-sky-900">
                                <ArrowRightLeft className="h-4 w-4 shrink-0" />
                                <span className="font-bold">{t('orders.change_ticket')}</span>
                                <span className="text-sky-600">·</span>
                                <span>{t('orders.pnr')}: <strong>{pnr}</strong></span>
                                <span className="text-sky-600">·</span>
                                <span>{t('orders.change_segment_line')}: <strong>{segment_line}</strong></span>
                            </div>
                        </div>

                        {/* Old segment */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Plane className="h-4 w-4 text-muted-foreground" />
                                    {t('orders.change_old_segment')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {original_segment ? (
                                    <div className="rounded-xl border bg-muted/30 p-4">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-xl font-black">
                                                    {original_segment.from ?? original_segment.departure_airport ?? '-'}
                                                    {' '}<ArrowRightLeft className="mx-1 inline h-4 w-4 text-muted-foreground" />{' '}
                                                    {original_segment.to ?? original_segment.arrival_airport ?? '-'}
                                                </p>
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {formatDate(original_segment.date ?? original_segment.departure_time)}
                                                    {original_segment.departure && ` · ${original_segment.departure}`}
                                                </p>
                                            </div>
                                            <Badge variant="outline" className="capitalize">{t('orders.original')}</Badge>
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">—</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* New offer */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Plane className="h-4 w-4 text-primary" />
                                    {t('orders.change_new_segment')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {flight && flight.departure_airport ? (
                                    <div className="rounded-xl border border-primary/20 bg-primary/5 p-4">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-xl font-black">
                                                    {flight.departure_airport}
                                                    {' '}<ArrowRightLeft className="mx-1 inline h-4 w-4 text-primary" />{' '}
                                                    {flight.arrival_airport}
                                                </p>
                                                <p className="mt-1 text-sm font-medium">
                                                    {flight.airline_name} · {flight.airline_code}{flight.flight_number}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {formatDate(flight.departure_time)}
                                                    {' · '}{formatTime(flight.departure_time)} → {formatTime(flight.arrival_time)}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-xs font-bold uppercase text-muted-foreground">{t('common.total')}</p>
                                                <p className="text-xl font-black text-primary">
                                                    {formatMoney(flight.pricing?.total ?? 0, flight.pricing?.currency ?? currency)}
                                                </p>
                                                <Badge className="mt-1 bg-primary/10 text-primary">
                                                    {reservation_type === 'QQ' ? t('common.open_reservation') : t('common.confirmed_reservation')}
                                                </Badge>
                                            </div>
                                        </div>
                                        <p className="mt-3 text-xs font-mono text-muted-foreground">{new_segment_code}</p>
                                    </div>
                                ) : (
                                    <div className="rounded-xl border bg-muted/30 p-4">
                                        <p className="font-mono text-sm">{new_segment_code}</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Passengers */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Users className="h-4 w-4 text-primary" />
                                    {t('orders.change_passengers')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {passengers.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">—</p>
                                ) : (
                                    <div className="space-y-2">
                                        {passengers.map((pax, i) => (
                                            <div key={i} className="flex items-center justify-between rounded-lg border p-3">
                                                <p className="font-medium">
                                                    {pax.first_name ?? pax.firstName ?? ''} {pax.last_name ?? pax.lastName ?? ''}
                                                </p>
                                                <Badge variant="outline" className="capitalize">
                                                    {pax.type ?? pax.pax_type ?? 'ADT'}
                                                </Badge>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right column: sticky fee sidebar */}
                    <div>
                        <div className="sticky top-6 space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">{t('orders.change_penalty')}</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {hasPenalty ? (
                                        penaltyIsZero ? (
                                            <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                                <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                                                <p className="font-bold text-emerald-800">{t('orders.change_no_penalty')}</p>
                                            </div>
                                        ) : (
                                            <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                                <AlertTriangle className="h-5 w-5 text-amber-600" />
                                                <div>
                                                    <p className="text-xs font-bold uppercase text-amber-700">{t('orders.change_penalty')}</p>
                                                    <p className="text-xl font-black text-amber-800">
                                                        {formatMoney(penalty_amount, currency)}
                                                    </p>
                                                </div>
                                            </div>
                                        )
                                    ) : (
                                        <p className="text-sm text-muted-foreground">—</p>
                                    )}

                                    <Button
                                        className="w-full font-bold"
                                        size="lg"
                                        onClick={handleConfirm}
                                        disabled={submitting}
                                    >
                                        {submitting ? (
                                            <>
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                {t('common.processing')}
                                            </>
                                        ) : (
                                            <>
                                                {t('orders.change_confirm_action')}
                                                <ChevronRight className="ml-2 h-4 w-4" />
                                            </>
                                        )}
                                    </Button>

                                    <p className="text-center text-xs text-muted-foreground">
                                        {t('orders.change_review_description')}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </TenantNavbarLayout>
    );
}
