import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { useTranslation } from '@/hooks/useTranslation';

export default function Register({ centralDomain }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        company_name: '',
        owner_name: '',
        phone: '',
        email: '',
        agency_path: '',
        password: '',
        password_confirmation: '',
        commercial_register: null,
        passport: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register-agency');
    };

    return (
        <AuthSplitLayout
            brandHref={route('agency.register')}
            title={t('landlord.auth.register_title')}
            description={t('landlord.auth.register_description')}
            footer={
                <p className="text-center text-sm text-muted-foreground text-pretty">
                    {t('landlord.auth.already_have_workspace')}
                </p>
            }
        >
            <Head title={t('landlord.auth.register_head_title')} />

            <form onSubmit={submit} className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="company_name">{t('landlord.auth.company_name')}</Label>
                        <Input
                            id="company_name"
                            type="text"
                            value={data.company_name}
                            onChange={(e) => setData('company_name', e.target.value)}
                            autoFocus
                            required
                        />
                        {errors.company_name && <p className="text-sm text-destructive">{errors.company_name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="owner_name">{t('landlord.auth.owner_name')}</Label>
                        <Input
                            id="owner_name"
                            type="text"
                            value={data.owner_name}
                            onChange={(e) => setData('owner_name', e.target.value)}
                        />
                        {errors.owner_name && <p className="text-sm text-destructive">{errors.owner_name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="phone">{t('common.phone')}</Label>
                        <Input
                            id="phone"
                            type="text"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                        />
                        {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="email">{t('common.email')}</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            autoComplete="username"
                            required
                        />
                        {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="agency_path">{t('landlord.auth.agency_path')}</Label>
                        <div className="flex items-center gap-2">
                            <span className="shrink-0 text-sm text-muted-foreground">{centralDomain}/agency/</span>
                            <Input
                                id="agency_path"
                                type="text"
                                value={data.agency_path}
                                onChange={(e) => setData('agency_path', e.target.value)}
                                className="flex-1"
                                placeholder="my-agency"
                                required
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">{t('landlord.auth.agency_path_hint')}</p>
                        {errors.agency_path && <p className="text-sm text-destructive">{errors.agency_path}</p>}
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="commercial_register">{t('landlord.auth.commercial_register')}</Label>
                        <Input
                            id="commercial_register"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={(e) => setData('commercial_register', e.target.files[0])}
                            required
                        />
                        <p className="text-xs text-muted-foreground">{t('landlord.auth.commercial_register_hint')}</p>
                        {errors.commercial_register && <p className="text-sm text-destructive">{errors.commercial_register}</p>}
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="passport">{t('landlord.auth.passport')}</Label>
                        <Input
                            id="passport"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={(e) => setData('passport', e.target.files[0])}
                            required
                        />
                        <p className="text-xs text-muted-foreground">{t('landlord.auth.passport_hint')}</p>
                        {errors.passport && <p className="text-sm text-destructive">{errors.passport}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password">{t('common.password')}</Label>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password_confirmation">{t('common.confirm_password')}</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        {errors.password_confirmation && <p className="text-sm text-destructive">{errors.password_confirmation}</p>}
                    </div>
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing ? t('landlord.auth.registering') : t('landlord.auth.register_agency')}
                </Button>
            </form>
        </AuthSplitLayout>
    );
}
