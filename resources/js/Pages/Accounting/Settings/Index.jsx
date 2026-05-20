import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import AccountingLayout from '@/Layouts/AccountingLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Switch } from '@/Components/ui/Switch';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/Tabs';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/Dialog';
import { useTranslation } from '@/hooks/useTranslation';

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

export default function AccountingSettingsIndex({ settings, providers }) {
    const { t } = useTranslation();
    const [showCloseDateConfirm, setShowCloseDateConfirm] = useState(false);
    const [pendingCloseDate, setPendingCloseDate] = useState('');

    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        fiscalYearStartMonth: settings.fiscalYearStartMonth,
        vatRate: settings.vatRate,
        vatRegistrationNumber: settings.vatRegistrationNumber,
        alertEmailRecipients: settings.alertEmailRecipients,
        autoLockAfterClose: settings.autoLockAfterClose,
        closeDateCurrentPeriod: settings.closeDateCurrentPeriod ?? '',
        autoReconcileSchedule: settings.autoReconcileSchedule,
        alertOnMismatch: settings.alertOnMismatch,
        thresholds: Object.fromEntries(
            providers.map((p) => [p.id, p.lowBalanceThreshold])
        ),
    });

    function handleSubmit(e) {
        e.preventDefault();
        put(route('accounting.settings.update'));
    }

    function handleCloseDateChange(value) {
        setPendingCloseDate(value);
        setShowCloseDateConfirm(true);
    }

    function confirmCloseDate() {
        setData('closeDateCurrentPeriod', pendingCloseDate);
        setShowCloseDateConfirm(false);
    }

    function cancelCloseDate() {
        setPendingCloseDate('');
        setShowCloseDateConfirm(false);
    }

    return (
        <TenantLayout>
            <Head title={t('accounting.settings.title', 'Accounting Settings')} />
            <AccountingLayout>
                <div className="space-y-6">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold">
                                {t('accounting.settings.title', 'Accounting Settings')}
                            </h1>
                            <p className="text-sm text-gray-500 mt-1">
                                {t('accounting.settings.desc', 'Configure fiscal year, VAT, wallet thresholds, and reconciliation preferences')}
                            </p>
                        </div>
                        {recentlySuccessful && (
                            <span className="text-sm text-green-600 font-medium">
                                {t('accounting.settings.saved', '✓ Settings saved')}
                            </span>
                        )}
                    </div>

                    <form onSubmit={handleSubmit}>
                        <Tabs defaultValue="general" className="space-y-4">
                            <TabsList>
                                <TabsTrigger value="general">
                                    {t('accounting.settings.tab_general', 'General')}
                                </TabsTrigger>
                                <TabsTrigger value="thresholds">
                                    {t('accounting.settings.tab_thresholds', 'Wallet Thresholds')}
                                </TabsTrigger>
                                <TabsTrigger value="revenue">
                                    {t('accounting.settings.tab_revenue', 'Revenue Recognition')}
                                </TabsTrigger>
                                <TabsTrigger value="close">
                                    {t('accounting.settings.tab_close', 'Monthly Close')}
                                </TabsTrigger>
                                <TabsTrigger value="reconciliation">
                                    {t('accounting.settings.tab_reconciliation', 'Reconciliation')}
                                </TabsTrigger>
                            </TabsList>

                            {/* ── General ── */}
                            <TabsContent value="general">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>{t('accounting.settings.general_title', 'General Settings')}</CardTitle>
                                        <CardDescription>
                                            {t('accounting.settings.general_desc', 'Currency, fiscal year, and VAT configuration')}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        {/* Currency — read-only */}
                                        <div className="space-y-1.5">
                                            <Label>{t('accounting.settings.currency', 'Default Currency')}</Label>
                                            <Input value={settings.currency} readOnly disabled className="bg-muted cursor-not-allowed w-40" />
                                            <p className="text-xs text-gray-400">
                                                {t('accounting.settings.currency_note', 'Single-currency mode — cannot be changed here')}
                                            </p>
                                        </div>

                                        {/* Fiscal year start month */}
                                        <div className="space-y-1.5">
                                            <Label htmlFor="fiscalYearStartMonth">
                                                {t('accounting.settings.fiscal_year_start', 'Fiscal Year Start Month')}
                                            </Label>
                                            <Select
                                                value={String(data.fiscalYearStartMonth)}
                                                onValueChange={(v) => setData('fiscalYearStartMonth', parseInt(v))}
                                            >
                                                <SelectTrigger className="w-48" id="fiscalYearStartMonth">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {MONTHS.map((month, i) => (
                                                        <SelectItem key={i + 1} value={String(i + 1)}>
                                                            {month}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {errors.fiscalYearStartMonth && (
                                                <p className="text-xs text-red-500">{errors.fiscalYearStartMonth}</p>
                                            )}
                                        </div>

                                        {/* VAT rate */}
                                        <div className="space-y-1.5">
                                            <Label htmlFor="vatRate">
                                                {t('accounting.settings.vat_rate', 'VAT Rate (%)')}
                                            </Label>
                                            <div className="flex items-center gap-2 w-40">
                                                <Input
                                                    id="vatRate"
                                                    type="number"
                                                    min={0}
                                                    max={100}
                                                    step={0.01}
                                                    value={data.vatRate}
                                                    onChange={(e) => setData('vatRate', parseFloat(e.target.value) || 0)}
                                                />
                                                <span className="text-sm text-gray-500">%</span>
                                            </div>
                                            {errors.vatRate && (
                                                <p className="text-xs text-red-500">{errors.vatRate}</p>
                                            )}
                                        </div>

                                        {/* VAT registration number */}
                                        <div className="space-y-1.5">
                                            <Label htmlFor="vatRegistrationNumber">
                                                {t('accounting.settings.vat_reg_number', 'VAT Registration Number')}
                                            </Label>
                                            <Input
                                                id="vatRegistrationNumber"
                                                value={data.vatRegistrationNumber}
                                                onChange={(e) => setData('vatRegistrationNumber', e.target.value)}
                                                placeholder="e.g. LY-123456789"
                                                className="max-w-xs"
                                            />
                                            {errors.vatRegistrationNumber && (
                                                <p className="text-xs text-red-500">{errors.vatRegistrationNumber}</p>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* ── Wallet Thresholds ── */}
                            <TabsContent value="thresholds">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>{t('accounting.settings.thresholds_title', 'Wallet Thresholds')}</CardTitle>
                                        <CardDescription>
                                            {t('accounting.settings.thresholds_desc', 'Set low-balance alert thresholds per provider wallet')}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        {/* Alert email recipients */}
                                        <div className="space-y-1.5">
                                            <Label htmlFor="alertEmailRecipients">
                                                {t('accounting.settings.alert_recipients', 'Alert Email Recipients')}
                                            </Label>
                                            <Input
                                                id="alertEmailRecipients"
                                                value={data.alertEmailRecipients}
                                                onChange={(e) => setData('alertEmailRecipients', e.target.value)}
                                                placeholder="finance@agency.com, ops@agency.com"
                                                className="max-w-md"
                                            />
                                            <p className="text-xs text-gray-400">
                                                {t('accounting.settings.alert_recipients_note', 'Comma-separated email addresses for low-balance notifications')}
                                            </p>
                                            {errors.alertEmailRecipients && (
                                                <p className="text-xs text-red-500">{errors.alertEmailRecipients}</p>
                                            )}
                                        </div>

                                        {/* Per-provider thresholds */}
                                        {providers.length === 0 ? (
                                            <p className="text-sm text-gray-400">
                                                {t('accounting.settings.no_providers', 'No providers configured yet')}
                                            </p>
                                        ) : (
                                            <div className="space-y-4">
                                                <p className="text-sm font-medium text-gray-700">
                                                    {t('accounting.settings.per_provider_threshold', 'Per-Provider Low-Balance Threshold (LYD)')}
                                                </p>
                                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                    {providers.map((provider) => (
                                                        <div key={provider.id} className="flex items-center gap-3">
                                                            <div className="flex-1">
                                                                <Label className="text-sm text-gray-600">{provider.name}</Label>
                                                                <p className="text-xs text-gray-400 capitalize">{provider.type}</p>
                                                            </div>
                                                            <Input
                                                                type="number"
                                                                min={0}
                                                                step={1}
                                                                value={data.thresholds[provider.id] ?? 500}
                                                                onChange={(e) =>
                                                                    setData('thresholds', {
                                                                        ...data.thresholds,
                                                                        [provider.id]: parseFloat(e.target.value) || 0,
                                                                    })
                                                                }
                                                                className="w-32"
                                                            />
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* ── Revenue Recognition ── */}
                            <TabsContent value="revenue">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>{t('accounting.settings.revenue_title', 'Revenue Recognition')}</CardTitle>
                                        <CardDescription>
                                            {t('accounting.settings.revenue_desc', 'Fixed per accounting plan — read-only')}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        <div className="space-y-1.5">
                                            <Label>{t('accounting.settings.recognition_trigger', 'Recognition Trigger')}</Label>
                                            <Input
                                                value={t('accounting.settings.provider_confirmation', 'Provider Confirmation')}
                                                readOnly
                                                disabled
                                                className="bg-muted cursor-not-allowed max-w-xs"
                                            />
                                            <p className="text-xs text-gray-400">
                                                {t('accounting.settings.recognition_trigger_note', 'Revenue is recognised when the provider confirms the booking')}
                                            </p>
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label>{t('accounting.settings.gross_net_display', 'Gross / Net Display')}</Label>
                                            <Input
                                                value={t('accounting.settings.gross', 'Gross')}
                                                readOnly
                                                disabled
                                                className="bg-muted cursor-not-allowed w-32"
                                            />
                                            <p className="text-xs text-gray-400">
                                                {t('accounting.settings.gross_note', 'Revenue is reported on a gross basis including taxes')}
                                            </p>
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* ── Monthly Close ── */}
                            <TabsContent value="close">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>{t('accounting.settings.close_title', 'Monthly Close')}</CardTitle>
                                        <CardDescription>
                                            {t('accounting.settings.close_desc', 'Lock journal entries and set the current period close date')}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        {/* Auto-lock toggle */}
                                        <div className="flex items-center justify-between max-w-md">
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {t('accounting.settings.auto_lock', 'Auto-lock after close')}
                                                </p>
                                                <p className="text-xs text-gray-400">
                                                    {t('accounting.settings.auto_lock_note', 'Prevent new journal entries for closed periods')}
                                                </p>
                                            </div>
                                            <Switch
                                                checked={data.autoLockAfterClose}
                                                onCheckedChange={(checked) => setData('autoLockAfterClose', checked)}
                                            />
                                        </div>

                                        {/* Close date */}
                                        <div className="space-y-1.5">
                                            <Label htmlFor="closeDateCurrentPeriod">
                                                {t('accounting.settings.close_date', 'Close Date for Current Period')}
                                            </Label>
                                            <Input
                                                id="closeDateCurrentPeriod"
                                                type="date"
                                                value={pendingCloseDate || data.closeDateCurrentPeriod || ''}
                                                onChange={(e) => handleCloseDateChange(e.target.value)}
                                                className="w-48"
                                            />
                                            {data.closeDateCurrentPeriod && (
                                                <p className="text-xs text-amber-600">
                                                    {t('accounting.settings.close_date_set', 'Current close date: {{date}}', { date: data.closeDateCurrentPeriod })}
                                                </p>
                                            )}
                                            {errors.closeDateCurrentPeriod && (
                                                <p className="text-xs text-red-500">{errors.closeDateCurrentPeriod}</p>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* ── Reconciliation ── */}
                            <TabsContent value="reconciliation">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>{t('accounting.settings.reconciliation_title', 'Reconciliation')}</CardTitle>
                                        <CardDescription>
                                            {t('accounting.settings.reconciliation_desc', 'Automated wallet-vs-ledger reconciliation schedule and alerts')}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        {/* Auto-reconcile schedule */}
                                        <div className="space-y-1.5">
                                            <Label htmlFor="autoReconcileSchedule">
                                                {t('accounting.settings.auto_reconcile_schedule', 'Auto-Reconcile Schedule')}
                                            </Label>
                                            <Select
                                                value={data.autoReconcileSchedule}
                                                onValueChange={(v) => setData('autoReconcileSchedule', v)}
                                            >
                                                <SelectTrigger className="w-48" id="autoReconcileSchedule">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="daily">{t('accounting.settings.schedule_daily', 'Daily')}</SelectItem>
                                                    <SelectItem value="weekly">{t('accounting.settings.schedule_weekly', 'Weekly')}</SelectItem>
                                                    <SelectItem value="manual">{t('accounting.settings.schedule_manual', 'Manual')}</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {errors.autoReconcileSchedule && (
                                                <p className="text-xs text-red-500">{errors.autoReconcileSchedule}</p>
                                            )}
                                        </div>

                                        {/* Alert on mismatch toggle */}
                                        <div className="flex items-center justify-between max-w-md">
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {t('accounting.settings.alert_on_mismatch', 'Alert on mismatch')}
                                                </p>
                                                <p className="text-xs text-gray-400">
                                                    {t('accounting.settings.alert_on_mismatch_note', 'Send email alert when wallet and ledger balances diverge')}
                                                </p>
                                            </div>
                                            <Switch
                                                checked={data.alertOnMismatch}
                                                onCheckedChange={(checked) => setData('alertOnMismatch', checked)}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>
                        </Tabs>

                        {/* Save button */}
                        <div className="mt-6 flex justify-end">
                            <Button type="submit" disabled={processing}>
                                {processing
                                    ? t('common.saving', 'Saving…')
                                    : t('accounting.settings.save', 'Save Settings')}
                            </Button>
                        </div>
                    </form>
                </div>

                {/* Close-date confirmation dialog */}
                <Dialog open={showCloseDateConfirm} onOpenChange={(open) => { if (!open) { cancelCloseDate(); } }}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {t('accounting.settings.confirm_close_date_title', 'Confirm Period Close Date')}
                            </DialogTitle>
                            <DialogDescription>
                                {t(
                                    'accounting.settings.confirm_close_date_desc',
                                    'Setting the close date to {{date}} will lock all journal entries for periods up to this date when auto-lock is enabled. This action affects financial reporting.',
                                    { date: pendingCloseDate }
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="outline" onClick={cancelCloseDate}>
                                {t('common.cancel', 'Cancel')}
                            </Button>
                            <Button onClick={confirmCloseDate}>
                                {t('accounting.settings.confirm_close_date_btn', 'Confirm')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </AccountingLayout>
        </TenantLayout>
    );
}
