import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Copy, ExternalLink } from 'lucide-react';

import { Button } from '@/Components/ui/Button';
import { Card, CardContent } from '@/Components/ui/Card';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { useTranslation } from '@/hooks/useTranslation';

function DetailRow({ label, value }) {
    return (
        <div className="rounded-lg border bg-muted/30 p-3">
            <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
            <dd className="mt-1 break-words text-sm font-medium text-foreground">{value}</dd>
        </div>
    );
}

export default function Success({ registration }) {
    const { t } = useTranslation();

    const details = [
        { label: t('landlord.auth.success.agency_name'), value: registration.agencyName },
        { label: t('landlord.auth.success.agency_number'), value: registration.agencyNumber },
        { label: t('landlord.auth.success.owner_name'), value: registration.ownerName },
        { label: t('landlord.auth.success.owner_email'), value: registration.ownerEmail },
        { label: t('landlord.auth.success.domain'), value: registration.domain },
    ];

    const copyLoginUrl = async () => {
        if (!navigator.clipboard) {
            return;
        }

        await navigator.clipboard.writeText(registration.loginUrl);
    };

    return (
        <AuthSplitLayout
            brandHref={route('agency.register')}
            title={t('landlord.auth.success.title')}
            description={t('landlord.auth.success.description')}
            footer={(
                <p className="text-center text-sm text-muted-foreground text-pretty">
                    {t('landlord.auth.success.email_notice')}
                </p>
            )}
        >
            <Head title={t('landlord.auth.success.head_title')} />

            <div className="space-y-5">
                <div className="flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4 text-primary">
                    <CheckCircle2 className="mt-0.5 size-5 shrink-0" />
                    <p className="text-sm text-pretty">{t('landlord.auth.success.ready_message')}</p>
                </div>

                <Card className="shadow-sm">
                    <CardContent className="space-y-3 p-4">
                        <dl className="grid gap-3">
                            {details.map((detail) => (
                                <DetailRow key={detail.label} label={detail.label} value={detail.value} />
                            ))}
                        </dl>
                    </CardContent>
                </Card>

                <div className="space-y-3">
                    <Button asChild className="w-full">
                        <a href={registration.loginUrl}>
                            {t('landlord.auth.success.open_login')}
                            <ExternalLink className="size-4" />
                        </a>
                    </Button>

                    <Button type="button" variant="outline" className="w-full" onClick={copyLoginUrl}>
                        {t('landlord.auth.success.copy_login_url')}
                        <Copy className="size-4" />
                    </Button>

                    <Button asChild variant="ghost" className="w-full">
                        <Link href={route('agency.register')}>{t('landlord.auth.success.register_another')}</Link>
                    </Button>
                </div>
            </div>
        </AuthSplitLayout>
    );
}
