import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { useTranslation } from '@/hooks/useTranslation';
import { Building2, ArrowRight } from 'lucide-react';

export default function AgencyLogin() {
    const { t } = useTranslation();

    const { data, setData, processing } = useForm({
        agency_path: '',
    });

    const submit = (e) => {
        e.preventDefault();
        const path = data.agency_path.trim().toLowerCase();
        if (!path) return;
        window.location.href = `/agency/${path}/login`;
    };

    return (
        <AuthSplitLayout
            brandHref="/"
            title={t('landlord.agent_login')}
            description={t('landlord.auth.agency_login_description') || 'Enter your agency ID to sign in to your workspace.'}
            footer={
                <p className="text-center text-sm text-muted-foreground text-pretty">
                    {t('landlord.auth.already_have_workspace')}
                </p>
            }
        >
            <Head title={t('landlord.agent_login')} />

            <Card className="shadow-sm">
                <CardHeader className="text-center pb-4">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
                        <Building2 className="h-6 w-6 text-primary" />
                    </div>
                    <CardTitle className="text-lg">{t('landlord.agent_login')}</CardTitle>
                    <CardDescription>
                        {t('landlord.auth.agency_id_hint') || 'Enter the agency ID you chose during registration.'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="agency_path">{t('landlord.auth.agency_path')}</Label>
                            <Input
                                id="agency_path"
                                type="text"
                                placeholder="my-agency"
                                value={data.agency_path}
                                onChange={(e) => setData('agency_path', e.target.value)}
                                autoFocus
                                required
                            />
                        </div>
                        <Button type="submit" className="w-full" disabled={processing || !data.agency_path.trim()}>
                            {t('landlord.auth.continue_to_login') || 'Continue to login'}
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </AuthSplitLayout>
    );
}
