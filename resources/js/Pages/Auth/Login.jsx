import { Checkbox } from "@/Components/ui/Checkbox";
import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from "@/hooks/useTranslation";
import { Plane, Hotel, Shield } from 'lucide-react';

export default function Login({ status, canResetPassword }) {
    const { tenant } = usePage().props;
    const { t } = useTranslation();

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="min-h-dvh grid lg:grid-cols-2">
            {/* ── Left panel ── */}
            <div className="hidden lg:flex flex-col justify-between bg-primary p-12 text-primary-foreground relative overflow-hidden">
                {/* Subtle geometric background */}
                <div className="absolute inset-0 opacity-10" aria-hidden="true">
                    <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <pattern id="grid" width="60" height="60" patternUnits="userSpaceOnUse">
                                <path d="M 60 0 L 0 0 0 60" fill="none" stroke="currentColor" strokeWidth="1"/>
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#grid)" />
                    </svg>
                </div>

                {/* Large decorative circle */}
                <div
                    className="absolute -bottom-32 -right-32 size-96 rounded-full opacity-10 bg-primary-foreground"
                    aria-hidden="true"
                />
                <div
                    className="absolute -top-16 -left-16 size-64 rounded-full opacity-5 bg-primary-foreground"
                    aria-hidden="true"
                />

                {/* Brand */}
                <div className="relative">
                    <span className="text-3xl font-bold tracking-tighter">TAMS</span>
                </div>

                {/* Middle content */}
                <div className="relative space-y-8">
                    <div className="space-y-3">
                        <h1 className="text-4xl font-bold leading-tight text-balance">
                            {t("Your travel operations, all in one place.")}
                        </h1>
                        <p className="text-primary-foreground/70 text-lg text-pretty">
                            {t("Flights, hotels, and insurance — managed from a single platform.")}
                        </p>
                    </div>

                    <div className="space-y-4">
                        {[
                            { icon: Plane,  label: t("Flight booking & ticketing") },
                            { icon: Hotel,  label: t("Hotel reservations") },
                            { icon: Shield, label: t("Travel insurance") },
                        ].map(({ icon: Icon, label }) => (
                            <div key={label} className="flex items-center gap-3">
                                <div className="flex items-center justify-center size-9 rounded-lg bg-primary-foreground/10 shrink-0">
                                    <Icon className="size-4" />
                                </div>
                                <span className="text-sm text-primary-foreground/80">{label}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Footer */}
                <div className="relative">
                    <p className="text-xs text-primary-foreground/40">
                        © {new Date().getFullYear()} TAMS. {t("All rights reserved.")}
                    </p>
                </div>
            </div>

            {/* ── Right panel ── */}
            <div className="flex flex-col justify-center items-center px-6 py-12 bg-background">
                {/* Mobile-only brand */}
                <div className="lg:hidden mb-10">
                    <span className="text-2xl font-bold tracking-tighter">TAMS</span>
                </div>

                <div className="w-full max-w-sm space-y-8">
                    {/* Header */}
                    <div className="space-y-1">
                        <h2 className="text-2xl font-bold text-foreground text-balance">
                            {tenant
                                ? t("Welcome back to :name", { name: tenant.name ?? tenant.id })
                                : t("Welcome back")}
                        </h2>
                        <p className="text-sm text-muted-foreground text-pretty">
                            {t("Sign in to your account to continue.")}
                        </p>
                    </div>

                    {/* Status message */}
                    {status && (
                        <div className="text-sm font-medium text-green-600">
                            {status}
                        </div>
                    )}

                    {/* Form */}
                    <form onSubmit={submit} className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="email">{t("Email address")}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                autoComplete="username"
                                autoFocus
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            {errors.email && (
                                <p className="text-sm text-destructive">{errors.email}</p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">{t("Password")}</Label>
                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="text-xs text-muted-foreground hover:text-foreground underline underline-offset-4 rounded focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        {t("Forgot password?")}
                                    </Link>
                                )}
                            </div>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                autoComplete="current-password"
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            {errors.password && (
                                <p className="text-sm text-destructive">{errors.password}</p>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="remember"
                                name="remember"
                                checked={data.remember}
                                onCheckedChange={(checked) => setData('remember', checked)}
                            />
                            <Label htmlFor="remember" className="text-sm font-normal text-muted-foreground cursor-pointer">
                                {t("Remember me")}
                            </Label>
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? t("Signing in…") : t("Sign in")}
                        </Button>
                    </form>

                    {/* Register link */}
                    <p className="text-center text-sm text-muted-foreground text-pretty">
                        {t("Don't have an account?")}{' '}
                        <Link
                            href={route('register')}
                            className="text-primary font-medium hover:underline underline-offset-4 rounded focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        >
                            {t("Register your agency")}
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    );
}
