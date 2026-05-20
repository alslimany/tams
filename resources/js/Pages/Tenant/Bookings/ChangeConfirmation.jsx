import React from 'react';
import { Head, Link } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { CheckCircle2, ArrowRightLeft, ReceiptText, Plane } from 'lucide-react';
import { useTranslation } from '@/hooks/useTranslation';

export default function ChangeConfirmation({ order, item, change }) {
    const { t } = useTranslation();

    const pnr = item.provider_reference ?? item.ticket_number ?? '-';
    const changeType = change?.change_type ?? '-';
    const newSegmentCode = change?.new_segment_code ?? '-';
    const changedAt = change?.changed_at
        ? new Date(change.changed_at).toLocaleString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
        : '-';

    return (
        <TenantNavbarLayout>
            <Head title={t('orders.change_confirmation_title')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                {/* Success banner */}
                <Card className="border-2 border-emerald-200 bg-emerald-50/60">
                    <CardContent className="flex flex-col gap-4 py-8 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-start gap-3">
                            <CheckCircle2 className="mt-0.5 h-7 w-7 text-emerald-600" />
                            <div>
                                <p className="text-xs font-black uppercase tracking-widest text-emerald-700">
                                    {t('orders.change_ticket')}
                                </p>
                                <h1 className="text-3xl font-black text-emerald-800">
                                    {t('orders.change_confirmation_title')}
                                </h1>
                                <p className="mt-1 text-sm text-emerald-700">
                                    {t('orders.change_success')}
                                </p>
                            </div>
                        </div>
                        <Badge className="w-fit bg-emerald-600 text-white">{t('orders.status_changed')}</Badge>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Change summary */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ArrowRightLeft className="h-5 w-5 text-primary" />
                                {t('orders.change_review_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <p className="text-muted-foreground">{t('orders.pnr')}</p>
                                <p className="font-bold">{pnr}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">{t('orders.order_number')}</p>
                                <p className="font-bold">{order.number}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">{t('orders.change_segment_line')}</p>
                                <p className="font-bold">{change?.segment_line ?? '-'}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">{t('orders.change_type_label')}</p>
                                <p className="font-bold capitalize">{changeType}</p>
                            </div>
                            <div className="sm:col-span-2">
                                <p className="text-muted-foreground">{t('orders.change_new_segment_code')}</p>
                                <p className="font-mono font-bold">{newSegmentCode}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">{t('orders.changed_at')}</p>
                                <p className="font-bold">{changedAt}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('common.next_actions')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Button asChild className="w-full">
                                <Link href={route('orders.show', { order: order.id })}>
                                    <Plane className="mr-2 h-4 w-4" />
                                    {t('orders.go_to_order')}
                                </Link>
                            </Button>
                            <Button asChild variant="outline" className="w-full">
                                <Link href={route('orders.index')}>
                                    <ReceiptText className="mr-2 h-4 w-4" />
                                    {t('orders.go_to_orders')}
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </TenantNavbarLayout>
    );
}
