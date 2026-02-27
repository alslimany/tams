import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from "@/hooks/useTranslation";

export default function ConfirmPassword() {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title={t("Confirm Password")} />

            <div className="mb-4 text-sm text-muted-foreground">
                {t("This is a secure area of the application. Please confirm your password before continuing.")}
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="password">{t("Password")}</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoFocus
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />
                    {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                </div>

                <div className="flex items-center justify-end mt-4">
                    <Button className="w-full" disabled={processing}>
                        {t("Confirm")}
                    </Button>
                </div>
            </form>
        </GuestLayout>
    );
}
