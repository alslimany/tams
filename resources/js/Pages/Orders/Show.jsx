import React from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { OrderItemsSection } from '@/Components/Orders/OrderItemCards';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/Dialog';
import { formatMoney } from '@/lib/currency';
import {
    AlertTriangle,
    ReceiptText,
    ShieldCheck,
} from 'lucide-react';

export default function Show({ order, itemTransactions, voidRefundAccount }) {
    const { auth } = usePage().props;
    const voidForm = useForm({});
    const cancelForm = useForm({ remarks: '' });
    const finalizeCancellationForm = useForm({});
    const currentUserRole = String(auth?.user?.role ?? auth?.landlordUser?.role ?? '').trim().toLowerCase();
    const canManageItems = ['admin', 'manager'].includes(currentUserRole);
    const canManageInsurance = ['admin', 'manager', 'agent'].includes(currentUserRole);
    const canManageHotels = ['admin', 'manager', 'agent'].includes(currentUserRole);
    const [voidTarget, setVoidTarget] = React.useState(null);
    const [cancelTarget, setCancelTarget] = React.useState(null);
    const [finalizeTarget, setFinalizeTarget] = React.useState(null);

    const primaryItem = order.items?.[0] ?? null;
    const primaryReferenceLabel = primaryItem?.type === 'insurance' ? 'Policy Reference' : 'PNR';
    const primaryReferenceValue = primaryItem?.type === 'insurance'
        ? (primaryItem.provider_reference ?? primaryItem.ticket_number ?? '-')
        : (primaryItem?.provider_reference ?? '-');

    const formatDateTime = (value) => {
        if (!value) {
            return '-';
        }

        return new Date(value).toLocaleString('en-US', {
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

    const cancelHotelBooking = (item) => {
        if (!window.confirm('Cancel this hotel booking with the provider?')) {
            return;
        }

        router.post(route('hotels.order-items.cancel', { order: order.id, item: item.id }), {}, {
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
            <Head title={`Order ${order.number}`} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <Card className="overflow-hidden border-0 shadow-xl shadow-slate-200/60 ring-1 ring-slate-200/70">
                    <CardContent className="space-y-6 bg-[linear-gradient(180deg,rgba(248,250,252,0.95),rgba(255,255,255,1))] p-0">
                        <div className="flex flex-col gap-4 border-b border-slate-200/80 px-6 py-6 md:flex-row md:items-start md:justify-between">
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-3">
                                    <p className="text-3xl font-black tracking-tight text-white-950">Order {order.number}</p>
                                    <Badge variant={orderStatusVariant(order.status)} className="px-3 py-1 font-black uppercase text-white tracking-wider">
                                        {order.status}
                                    </Badge>
                                </div>
                                <div className="grid gap-2 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-4">
                                    <p className='w-50 truncate'><span className="font-semibold text-slate-900 ">{primaryReferenceLabel}:</span> {primaryReferenceValue}</p>
                                    <p><span className="font-semibold text-slate-900">Issued:</span> {formatDateTime(order.issued_at)}</p>
                                    <p><span className="font-semibold text-slate-900">Payment:</span> {order.payment_method}</p>
                                    <p><span className="font-semibold text-slate-900">Items:</span> {order.items?.length ?? 0}</p>
                                </div>
                            </div>

                            <div className="flex flex-col items-start gap-3 md:items-end">
                                <div className="text-left md:text-right">
                                    <p className="text-xs font-bold uppercase tracking-[0.25em] text-slate-500">Total Paid</p>
                                    <p className="text-3xl font-black text-slate-950">{formatAmount(order.amount_paid || order.grand_total, order.currency)}</p>
                                </div>

                                <Button asChild variant="outline" className="border-slate-300 bg-white/80 font-bold text-slate-700 hover:bg-slate-50">
                                    <Link href={route('orders.index')}>
                                        <ReceiptText className="mr-2 h-4 w-4" /> Back to Orders
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
                    onPrintTickets={openFlightTicketPdf}
                    onInsuranceCancel={openCancelModal}
                    onPrintPolicy={openPolicyReport}
                    onHotelCancel={cancelHotelBooking}
                    isInsuranceCancellationApproved={isInsuranceCancellationApproved}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Financial Summary</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">Order Owner</p>
                                <p className="mt-1 font-semibold text-slate-900">{order.owner?.name ?? order.contact?.first_name ?? '-'}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">Contact Email</p>
                                <p className="mt-1 font-semibold text-slate-900">{order.contact?.email ?? '-'}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">Grand Total</p>
                                <p className="mt-1 font-semibold tabular-nums text-slate-900">{formatAmount(order.grand_total, order.currency)}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <p className="text-xs font-bold uppercase text-slate-500">Refunded</p>
                                <p className="mt-1 font-semibold tabular-nums text-slate-900">{formatAmount(order.amount_refunded, order.currency)}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Status Log</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {order.status_logs?.map((log) => (
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
                            <DialogTitle>Confirm Ticket Void</DialogTitle>
                            <DialogDescription>
                                The ticket for PNR {voidTarget?.pnr ?? '-'} will be cancelled.
                                This operation cannot be changed after cancelling the PNR.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                Please confirm carefully before continuing.
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Amount To Be Deposited</p>
                                    <p className="mt-2 text-lg font-black text-slate-950">
                                        {voidTarget ? formatAmount(voidTarget.amount, voidTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Deposit Account</p>
                                    <p className="mt-2 font-semibold text-slate-900">{depositAccountName}</p>
                                    <p className="text-xs text-slate-600">{depositAccountEmail}</p>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeVoidModal} disabled={voidForm.processing}>
                                Keep Ticket
                            </Button>
                            <Button type="button" variant="destructive" onClick={confirmVoid} disabled={voidForm.processing}>
                                {voidForm.processing ? 'Voiding...' : 'Confirm Void'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(cancelTarget)} onOpenChange={(open) => (open ? null : closeCancelModal())}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Cancel Insurance Policy</DialogTitle>
                            <DialogDescription>
                                This operation is not reversible. Once the cancellation request is sent, the insurance company will review it before the policy can be finalized as cancelled.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                                <div className="flex items-start gap-3">
                                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                                    <p>Please confirm carefully before continuing. After approval from the insurance company, the user will still need to confirm the final cancellation.</p>
                                </div>
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Policy Reference</p>
                                    <p className="mt-2 font-semibold text-slate-900">{cancelTarget?.provider_reference ?? cancelTarget?.ticket_number ?? '-'}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Policy Amount</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {cancelTarget ? formatAmount(cancelTarget.total_amount ?? cancelTarget.total, cancelTarget.currency) : '-'}
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <label htmlFor="insurance-cancellation-remarks" className="text-sm font-semibold text-slate-900">
                                    Cancellation Note
                                </label>
                                <textarea
                                    id="insurance-cancellation-remarks"
                                    rows={5}
                                    value={cancelForm.data.remarks}
                                    onChange={(event) => cancelForm.setData('remarks', event.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                                    placeholder="Explain why the policy should be cancelled."
                                />
                                {cancelForm.errors.remarks ? <p className="text-sm text-rose-600">{cancelForm.errors.remarks}</p> : null}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeCancelModal} disabled={cancelForm.processing}>
                                Keep Policy
                            </Button>
                            <Button type="button" variant="destructive" onClick={submitCancellation} disabled={cancelForm.processing}>
                                {cancelForm.processing ? 'Sending...' : 'Send Cancellation Request'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={Boolean(finalizeTarget)} onOpenChange={(open) => (open ? null : closeFinalizeModal())}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Confirm Insurance Cancellation</DialogTitle>
                            <DialogDescription>
                                The insurance company has approved this cancellation. Confirming now will finalize the policy cancellation, deposit the policy amount, and reverse the commission.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                                <div className="flex items-start gap-3">
                                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0" />
                                    <p>The latest insurance company remark is <span className="font-black">تم الالغاء</span>. Final confirmation is required from the user.</p>
                                </div>
                            </div>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-3">
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Policy Amount</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount(finalizeTarget.total_amount ?? finalizeTarget.total, finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Commission Reversal</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount(finalizeTarget.commission_amount ?? 0, finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Net Wallet Effect</p>
                                    <p className="mt-2 font-semibold text-slate-900">
                                        {finalizeTarget ? formatAmount((finalizeTarget.total_amount ?? finalizeTarget.total ?? 0) - (finalizeTarget.commission_amount ?? 0), finalizeTarget.currency) : '-'}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeFinalizeModal} disabled={finalizeCancellationForm.processing}>
                                Not Now
                            </Button>
                            <Button type="button" onClick={confirmCancellationFinalization} disabled={finalizeCancellationForm.processing}>
                                {finalizeCancellationForm.processing ? 'Confirming...' : 'Confirm Cancellation'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </TenantSidebarLayout>
    );
}
