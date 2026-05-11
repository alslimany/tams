import { Head, Link, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { useTranslation } from '@/hooks/useTranslation';

export default function Login() {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('landlord.login.store'));
    };

    return (
        <AuthSplitLayout
            brandHref={route('landlord.login')}
            title={t('landlord.auth.login_title')}
            description={t('landlord.auth.login_description')}
            footer={(
                <p className="text-center text-sm text-muted-foreground text-pretty">
                    {t('landlord.auth.need_agency')}{' '}
                    <Link
                        href={route('agency.register')}
                        className="rounded text-primary font-medium underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                    >
                        {t('landlord.auth.register_agency')}
                    </Link>
                </p>
            )}
        >
            <Head title={t('landlord.auth.login_head_title')} />

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="email">{t('common.email')}</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        autoComplete="username"
                        autoFocus
                        required
                    />
                    {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">{t('common.password')}</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        autoComplete="current-password"
                        required
                    />
                    {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing ? t('landlord.auth.signing_in') : t('common.login')}
                </Button>
            </form>
        </AuthSplitLayout>
    );
}
