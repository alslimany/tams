import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from "@/hooks/useTranslation";

export default function ForgotPassword({ status }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title={t("Forgot Password")} />

            <div className="mb-4 text-sm text-muted-foreground">
                {t("Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.")}
            </div>

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="email" className="sr-only">{t("Email")}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        placeholder={t("Email")}
                        onChange={(e) => setData('email', e.target.value)}
                        autoFocus
                    />
                    {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                </div>

                <div className="flex items-center justify-end mt-4">
                    <Button className="w-full" disabled={processing}>
                        {t("Email Password Reset Link")}
                    </Button>
                </div>
            </form>
        </GuestLayout>
    );
}
