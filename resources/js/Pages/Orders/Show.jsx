import React from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { OrderItemsSection } from '@/Components/Orders/OrderItemCards';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/Dialog';
import { formatMoney } from '@/lib/currency';
import { useTranslation } from '@/hooks/useTranslation';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Building2,
    Loader2,
    Plane,
    ShieldCheck,
    Store,
    UserRound,
} from 'lucide-react';

const ownerTypeIcon = (ownerType) => {
    if (ownerType === 'merchant') {
        return Store;
    }

    if (ownerType === 'agency') {
        return Building2;
    }

    return UserRound;
};

export default function Show({ order, itemTransactions, voidRefundAccount }) {
    const { auth } = usePage().props;
    const { t, locale } = useTranslation();
    const voidForm = useForm({});
    const cancelForm = useForm({ remarks: '' });
    const finalizeCancellationForm = useForm({});
    const refundForm = useForm({ penalty_amount: '', refund_amount: '' });
    const currentUserRole = String(auth?.user?.role ?? auth?.landlordUser?.role ?? '').trim().toLowerCase();
    const canManageItems = ['admin', 'manager'].includes(currentUserRole);
    const canManageInsurance = ['admin', 'manager', 'agent'].includes(currentUserRole);
    const canManageHotels = ['admin', 'manager', 'agent'].includes(currentUserRole);
    const [voidTarget, setVoidTarget] = React.useState(null);
    const [cancelTarget, setCancelTarget] = React.useState(null);
    const [finalizeTarget, setFinalizeTarget] = React.useState(null);
    const [refundTarget, setRefundTarget] = React.useState(null);
    const [refundQuote, setRefundQuote] = React.useState(null);
    const [refundQuoteLoading, setRefundQuoteLoading] = React.useState(false);
    const [refundQuoteError, setRefundQuoteError] = React.useState(null);
    const [changeTarget, setChangeTarget] = React.useState(null);

    const primaryItem = order.items?.[0] ?? null;
    const primaryReferenceLabel = primaryItem?.type === 'insurance' ? t('orders.policy_reference') : t('orders.pnr');
    const primaryReferenceValue = primaryItem?.type === 'insurance'
        ? (primaryItem.provider_reference ?? primaryItem.ticket_number ?? '-')
        : (primaryItem?.provider_reference ?? '-');

    // Derive owner type from order owner morph type or payment_method
    const ownerType = order.owner_type?.includes('Merchant') || order.payment_method === 'merchant_wallet'
        ? 'merchant'
        : order.owner_type?.includes('Agency') || order.owner_type?.includes('Tenant')
            ? 'agency'
            : 'user';

    const OwnerIcon = ownerTypeIcon(ownerType);

    // Source context: which provider/financial source
    const financialSource = primaryItem?.item_details?.financial_source ?? null;
    const isMasterSupply = financialSource === 'master_agency_supply' || order.payment_method === 'default_agency_supply';

    const formatDateTime = (value) => {
        if (!value) {
            return '-';
        }

        return new Date(value).toLocaleString(locale, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const formatAmount = (amount, currency) => formatMoney(amount, currency);

    const orderStatusVariant = (status) => {
        if (status === 'paid' || status === 'issued') {
            return 'success';
        }

        if (status === 'voided' || status === 'refunded') {
            return 'destructive';
        }

        if (status === 'confirmed') {
            return 'default';
        }

        return 'secondary';
    };


    const openVoidModal = (item, amount, currency) => {
        setVoidTarget({
            id: item.id,
            pnr: item.provider_reference ?? item.item_details?.rloc ?? '-',
            amount,
            currency,
        });
    };

    const closeVoidModal = () => {
        if (voidForm.processing) {
            return;
        }

        setVoidTarget(null);
    };

    const confirmVoid = () => {
        if (!voidTarget) {
            return;
        }

        voidForm.post(route('tickets.void', { booking: order.id, ticket: voidTarget.id }), {
            preserveScroll: true,
            onSuccess: () => setVoidTarget(null),
        });
    };

    const getInsuranceCancellation = (item) => item?.item_details?.insurance?.cancellation ?? {};

    const getInsuranceRemark = (item) => String(getInsuranceCancellation(item)?.latest_remark ?? '');

    const isInsuranceCancellationApproved = (item) => getInsuranceRemark(item) === 'تم الالغاء';

    const openCancelModal = (item) => {
        setCancelTarget(item);
        cancelForm.setData('remarks', '');
        cancelForm.clearErrors();
    };

    const openPolicyReport = (item) => {
        const url = route('insurance.order-items.report', { order: order.id, item: item.id });

        window.open(url, '_blank', 'noopener,noreferrer');
    };

    const openFlightTicketPdf = (item) => {
        const url = route('orders.flight-items.ticket-pdf', { order: order.id, item: item.id });

        window.open(url, '_blank', 'noopener,noreferrer');
    };

    const openRefundModal = (item) => {
        setRefundTarget(item);
        setRefundQuote(null);
        setRefundQuoteError(null);
        refundForm.reset();
        setRefundQuoteLoading(true);

        window.axios
            .get(route('tickets.refundQuote', { booking: order.id, ticket: item.id }))
            .then((response) => {
                const data = response.data ?? {};
                setRefundQuote(data);
                refundForm.setData({
                    penalty_amount: String(data.penalty_amount ?? ''),
                    refund_amount: String(data.refund_amount ?? ''),
                });
            })
            .catch((error) => {
                setRefundQuoteError(error?.response?.data?.message ?? t('orders.refund_quote_error'));
            })
            .finally(() => {
                setRefundQuoteLoading(false);
            });
    };

    const closeRefundModal = () => {
        if (refundForm.processing) {
            return;
        }

        setRefundTarget(null);
        setRefundQuote(null);
        setRefundQuoteError(null);
        refundForm.reset();
    };

    const confirmRefund = () => {
        if (!refundTarget) {
            return;
        }

        refundForm.post(route('tickets.refund', { booking: order.id, ticket: refundTarget.id }), {
            preserveScroll: true,
            onSuccess: () => closeRefundModal(),
        });
    };

    const openChangeModal = (item) => {
        setChangeTarget(item);
    };

    const closeChangeModal = () => {
        setChangeTarget(null);
    };

    const navigateToChangeOffers = (item, segment) => {
        router.visit(route('flights.change-offers', { booking: order.id, ticket: item.id }), {
            data: {
                segment_line: segment.line,
                origin: segment.departure_airport,
                destination: segment.arrival_airport,
                date: segment.date ?? segment.departure_time?.split(' ')[0] ?? '',
            },
        });
    };

    const cancelHotelBooking = (item) => {
        if (!window.confirm(t('orders.confirm_hotel_cancel'))) {
            return;
        }

        router.post(route('hotels.order-items.cancel', { order: order.id, item: item.id }), {}, {
            preserveScroll: true,
        });
    };

    const refundEsim = (item) => {
        if (!window.confirm(t('orders.confirm_esim_refund'))) {
            return;
        }

        router.post(route('orders.esim-items.refund', { order: order.id, item: item.id }), {}, {
            preserveScroll: true,
        });
    };

    const closeCancelModal = () => {
        if (cancelForm.processing) {
            return;
        }

        setCancelTarget(null);
        cancelForm.reset('remarks');
    };

    const submitCancellation = () => {
        if (!cancelTarget) {
            return;
        }

        cancelForm.post(route('insurance.order-items.cancel', { order: order.id, item: cancelTarget.id }), {
            preserveScroll: true,
            onSuccess: () => {
                setCancelTarget(null);
                cancelForm.reset('remarks');
            },
        });
    };

    const closeFinalizeModal = () => {
        if (finalizeCancellationForm.processing) {
            return;
        }

        setFinalizeTarget(null);
    };

    const confirmCancellationFinalization = () => {
        if (!finalizeTarget) {
            return;
        }

        finalizeCancellationForm.post(route('insurance.order-items.finalize-cancellation', { order: order.id, item: finalizeTarget.id }), {
            preserveScroll: true,
            onSuccess: () => setFinalizeTarget(null),
        });
    };

    React.useEffect(() => {
        const pendingCancellationItems = (order.items ?? []).filter((item) => item.type === 'insurance' && item.status === 'cancellation');

        if (pendingCancellationItems.length === 0) {
            return undefined;
        }

        const timerId = window.setInterval(() => {
            router.reload({
                only: ['order', 'itemTransactions'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 30000);

        return () => window.clearInterval(timerId);
    }, [order.items]);

    React.useEffect(() => {
        const approvedItem = (order.items ?? []).find((item) => item.type === 'insurance' && item.status === 'cancellation' && isInsuranceCancellationApproved(item));

        if (!approvedItem) {
            setFinalizeTarget((current) => (current && current.status === 'cancellation' ? null : current));

            return;
        }

        setFinalizeTarget((current) => (current?.id === approvedItem.id ? current : approvedItem));
    }, [order.items]);

    const depositAccountName = voidRefundAccount?.name ?? auth?.user?.name ?? 'Agency Account';
    const depositAccountEmail = voidRefundAccount?.email ?? auth?.user?.email ?? '-';

    return (
        <TenantSidebarLayout>
            <Head title={t('orders.order_number_label', { number: order.number })} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <Card className="overflow-hidden border-0 shadow-xl shadow-slate-200/60 ring-1 ring-slate-200/70">
                    <CardContent className="space-y-6 bg-[linear-gradient(180deg,rgba(248,250,252,0.95),rgba(255,255,255,1))] p-0">
                        <div className="flex flex-col gap-4 border-b border-slate-200/80 px-6 py-6 md:flex-row md:items-start md:justify-between">
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-3">
                                    <p className="text-3xl font-black tracking-tight text-slate-950">
                                        {t('orders.order_number_label', { number: order.number })}
                                    </p>
                                    <Badge variant={orderStatusVariant(order.status)} className="px-3 py-1 font-black uppercase tracking-wider text-white">
                                        {order.status}
                                    </Badge>
                                    {isMasterSupply ? (
                                        <Badge variant="outline" className="border-violet-200 bg-violet-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-violet-700">
                                            {t('orders.source_agency_supply')}
                                        </Badge>
                                    ) : null}
                                </div>
                                <div className="grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
                                    <p className="w-50 truncate">
                                        <span className="font-semibold text-slate-900">{primaryReferenceLabel}:</span> {primaryReferenceValue}
                                    </p>
                                    <p>
                                        <span className="font-semibold text-slate-900">{t('orders.issued')}:</span> {formatDateTime(order.issued_at)}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    <OwnerIcon className="size-3.5 shrink-0" />
                                    <span className="font-semibold capitalize text-slate-700">{ownerType}</span>
                                    {order.owner?.name ? (
                                        <>
                                            <span>·</span>
                                            <span>{order.owner.name}</span>
                                        </>
                                    ) : null}
                                    {order.payment_method ? (
                                        <>
                                            <span>·</span>
                                            <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-600">
                                                {t(`orders.payment_method_${order.payment_method}`) || order.payment_method}
                                            </span>
                                        </>
                                    ) : null}
                                </div>
                            </div>

                            <div className="flex flex-col items-start gap-3 md:items-end">
                                <div className="text-left md:text-right">
                                    <p className="text-xs font-bold uppercase tracking-[0.25em] text-slate-500">{t('orders.total_paid')}</p>
                                    <p className="text-3xl font-black text-slate-950">{formatAmount(order.amount_paid || order.grand_total, order.currency)}</p>
                                </div>

                                <Button asChild variant="outline" className="border-slate-300 bg-white/80 font-bold text-slate-700 hover:bg-slate-50">
                                    <Link href={route('orders.index')}>
                                        <ArrowLeft className="mr-2 h-4 w-4" /> {t('orders.back_to_orders')}
                                    </Link>
                                </Button>
                            </div>
                        </div>

                    </CardContent>
                </Card>

                <OrderItemsSection
                    order={order}
                    canManageItems={canManageItems}
                    canManageInsurance={canManageInsurance}
                    canManageHotels={canManageHotels}
                    onVoid={openVoidModal}
                    onRefund={openRefundModal}
                    onChangeTicket={openChangeModal}
                    onPrintTickets={openFlightTicketPdf}
                    onInsuranceCancel={openCancelModal}
                    onPrintPolicy={openPolicyReport}
                    onHotelCancel={cancelHotelBooking}
                    onEsimRefund={refundEsim}
                    isInsuranceCancellationApproved={isInsuranceCancellationApproved}
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <UserRound className="size-4 text-slate-500" />
                            {t('orders.customer')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.order_owner')}</p>
                                <p className="mt-1 font-semibold text-slate-900">{order.owner?.name ?? order.contact?.first_name ?? '-'}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.contact_email')}</p>
                                <p className="mt-1 font-semibold text-slate-900">{order.contact?.email ?? '-'}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.payment_method')}</p>
                                <p className="mt-1 font-semibold text-slate-900">
                                    {order.payment_method
                                        ? (t(`orders.payment_method_${order.payment_method}`) || order.payment_method)
                                        : '-'}
                                </p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.issued_at')}</p>
                                <p className="mt-1 font-semibold text-slate-900">{formatDateTime(order.issued_at)}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('orders.financial_summary')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.grand_total')}</p>
                                <p className="mt-1 font-semibold tabular-nums text-slate-900">{formatAmount(order.grand_total, order.currency)}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.amount_paid')}</p>
                                <p className="mt-1 font-semibold tabular-nums text-slate-900">{formatAmount(order.amount_paid, order.currency)}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.amount_refunded')}</p>
                                <p className="mt-1 font-semibold tabular-nums text-slate-900">{formatAmount(order.amount_refunded, order.currency)}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">{t('orders.items_count_label', { count: order.items?.length ?? 0 })}</p>
                                <p className="mt-1 font-semibold tabular-nums text-slate-900">{order.items?.length ?? 0}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('orders.status_log')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {(order.status_logs ?? []).length === 0 ? (
                            <p className="text-sm text-slate-500">{t('orders.status_log_empty')}</p>
                        ) : (order.status_logs ?? []).map((log) => (
                            <div key={log.id} className="rounded-md border p-3 text-sm">
                                <p className="font-medium">
                                    {log.old_status ?? 'null'} → {log.new_status}
                                </p>
                                <p className="text-muted-foreground">{log.comment ?? '-'}</p>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Dialog open={Boolean(voidTarget)} onOpenChange={(open) => (open ? null : closeVoidModal())}>
                    <DialogContent className="sm:max-w-130">
                        <DialogHeader>
                            <DialogTitle>{t('orders.confirm_void_title')}</DialogTitle>
                            <DialogDescription>
                                {t('orders.confirm_void_description', { pnr: voidTarget?.pnr ?? '-' })}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                {t('orders.confirm_carefully')}
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.amount_to_deposit')}</p>
                                    <p className="mt-2 text-lg font-black text-slate-950">
                                        {voidTarget ? formatAmount(voidTarget.amount, voidTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.deposit_account')}</p>
                                    <p className="mt-2 font-semibold text-slate-900">{depositAccountName}</p>
                                    <p className="text-xs text-slate-600">{depositAccountEmail}</p>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeVoidModal} disabled={voidForm.processing}>
                                {t('orders.keep_ticket')}
                            </Button>
                            <Button type="button" variant="destructive" onClick={confirmVoid} disabled={voidForm.processing}>
                                {voidForm.processing ? t('orders.voiding') : t('orders.confirm_void')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(cancelTarget)} onOpenChange={(open) => (open ? null : closeCancelModal())}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('orders.cancel_insurance_title')}</DialogTitle>
                            <DialogDescription>
                                {t('orders.cancel_insurance_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                                <div className="flex items-start gap-3">
                                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                                    <p>{t('orders.cancel_insurance_warning')}</p>
                                </div>
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.policy_reference')}</p>
                                    <p className="mt-2 font-semibold text-slate-900">{cancelTarget?.provider_reference ?? cancelTarget?.ticket_number ?? '-'}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.policy_amount')}</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {cancelTarget ? formatAmount(cancelTarget.total_amount ?? cancelTarget.total, cancelTarget.currency) : '-'}
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <label htmlFor="insurance-cancellation-remarks" className="text-sm font-semibold text-slate-900">
                                    {t('orders.cancellation_note')}
                                </label>
                                <textarea
                                    id="insurance-cancellation-remarks"
                                    rows={5}
                                    value={cancelForm.data.remarks}
                                    onChange={(event) => cancelForm.setData('remarks', event.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                                    placeholder={t('orders.cancellation_note_placeholder')}
                                />
                                {cancelForm.errors.remarks ? <p className="text-sm text-rose-600">{cancelForm.errors.remarks}</p> : null}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeCancelModal} disabled={cancelForm.processing}>
                                {t('orders.keep_policy')}
                            </Button>
                            <Button type="button" variant="destructive" onClick={submitCancellation} disabled={cancelForm.processing}>
                                {cancelForm.processing ? t('orders.sending') : t('orders.send_cancellation_request')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(finalizeTarget)} onOpenChange={(open) => (open ? null : closeFinalizeModal())}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('orders.confirm_cancellation_title')}</DialogTitle>
                            <DialogDescription>
                                {t('orders.confirm_cancellation_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                                <div className="flex items-start gap-3">
                                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0" />
                                    <p>{t('orders.confirm_cancellation_approved_remark')}</p>
                                </div>
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-3">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.policy_amount')}</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount(finalizeTarget.total_amount ?? finalizeTarget.total, finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.commission_reversal')}</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount(finalizeTarget.commission_amount ?? 0, finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.net_wallet_effect')}</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount((finalizeTarget.total_amount ?? finalizeTarget.total ?? 0) - (finalizeTarget.commission_amount ?? 0), finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeFinalizeModal} disabled={finalizeCancellationForm.processing}>
                                {t('orders.not_now')}
                            </Button>
                            <Button type="button" onClick={confirmCancellationFinalization} disabled={finalizeCancellationForm.processing}>
                                {finalizeCancellationForm.processing ? t('orders.confirming') : t('orders.confirm_cancellation')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(refundTarget)} onOpenChange={(open) => (open ? null : closeRefundModal())}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>{t('orders.confirm_refund_title')}</DialogTitle>
                            <DialogDescription>
                                {t('orders.confirm_refund_description', {
                                    pnr: refundTarget?.provider_reference ?? refundTarget?.item_details?.rloc ?? '-',
                                })}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            {refundQuoteLoading ? (
                                <div className="flex items-center justify-center gap-3 rounded-xl border border-slate-200 bg-slate-50 py-8 text-sm text-slate-600">
                                    <Loader2 className="size-5 animate-spin" />
                                    {t('orders.refund_quote_loading')}
                                </div>
                            ) : refundQuoteError ? (
                                <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                                    <div className="flex items-start gap-3">
                                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                                        <p>{refundQuoteError}</p>
                                    </div>
                                </div>
                            ) : refundQuote ? (
                                <>
                                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                        {t('orders.refund_irreversible_warning')}
                                    </div>

                                    {(refundQuote.mps_penalties ?? []).length > 0 ? (
                                        <div className="space-y-1">
                                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.refund_mps_penalties')}</p>
                                            <div className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                                                {refundQuote.mps_penalties.map((penalty, index) => (
                                                    <div key={index} className="flex items-center justify-between px-3 py-2 text-sm">
                                                        <span className="font-semibold text-slate-700">
                                                            {penalty.description ?? '-'}
                                                            {penalty.code ? <span className="ms-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-bold text-slate-500">[{penalty.code}]</span> : null}
                                                        </span>
                                                        <span className="tabular-nums font-black text-slate-950">
                                                            {formatAmount(penalty.amount, penalty.currency ?? refundQuote.currency)}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}

                                    <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                        <div>
                                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.refund_penalty_amount')}</p>
                                            <p className="mt-2 text-lg font-black text-rose-700">
                                                {formatAmount(refundQuote.penalty_amount, refundQuote.currency)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{t('orders.refund_net_amount')}</p>
                                            <p className="mt-2 text-lg font-black text-emerald-700">
                                                {formatAmount(refundQuote.refund_amount, refundQuote.currency)}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                                        <p className="font-semibold text-slate-700">{t('orders.deposit_account')}</p>
                                        <p>{depositAccountName} · {depositAccountEmail}</p>
                                    </div>
                                </>
                            ) : null}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeRefundModal} disabled={refundForm.processing}>
                                {t('orders.keep_ticket')}
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={confirmRefund}
                                disabled={refundForm.processing || refundQuoteLoading || Boolean(refundQuoteError) || !refundQuote}
                            >
                                {refundForm.processing ? t('orders.refunding') : t('orders.confirm_refund')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(changeTarget)} onOpenChange={(open) => (open ? null : closeChangeModal())}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>{t('orders.change_ticket')}</DialogTitle>
                            <DialogDescription>
                                {t('orders.change_select_segment_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-3">
                            {(changeTarget?.item_details?.itineraries ?? []).length === 0 ? (
                                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                                    {t('orders.change_no_segments')}
                                </div>
                            ) : (changeTarget?.item_details?.itineraries ?? []).map((itin, index) => {
                                const segment = {
                                    line: itin.itinerary_id,
                                    departure_airport: itin.from,
                                    arrival_airport: itin.to,
                                    date: itin.date,
                                    departure_time: itin.departure,
                                    arrival_time: itin.arrival,
                                    airline_id: itin.airline_id,
                                    flight_number: itin.flight_number,
                                    class: itin.class,
                                };
                                const depDate = segment.date ?? '';
                                const depTime = segment.departure_time?.substring(0, 5) ?? '';
                                const arrTime = segment.arrival_time?.substring(0, 5) ?? '';

                                return (
                                    <button
                                        key={index}
                                        type="button"
                                        className="w-full rounded-xl border border-slate-200 bg-white p-4 text-left transition-colors hover:border-sky-400 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-400"
                                        onClick={() => navigateToChangeOffers(changeTarget, segment)}
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 ring-1 ring-sky-200">
                                                    <Plane className="size-4" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-black text-slate-950">
                                                        {segment.departure_airport} → {segment.arrival_airport}
                                                    </p>
                                                    <p className="mt-0.5 text-xs font-semibold text-slate-500">
                                                        {segment.airline_id ?? ''}{segment.flight_number ?? ''}
                                                        {segment.class ? ` · ${segment.class}` : ''}
                                                        {depDate ? ` · ${depDate}` : ''}
                                                        {depTime ? ` · ${depTime}` : ''}
                                                        {arrTime ? ` → ${arrTime}` : ''}
                                                    </p>
                                                </div>
                                            </div>
                                            <ArrowRight className="size-4 shrink-0 text-slate-400" />
                                        </div>
                                    </button>
                                );
                            })}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeChangeModal}>
                                {t('orders.cancel')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </TenantSidebarLayout>
    );
}
