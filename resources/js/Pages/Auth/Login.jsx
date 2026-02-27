import { useState } from 'react';
import { Checkbox } from "@/Components/ui/Checkbox";
import { Button } from "@/Components/ui/Button";
import { Input } from "@/Components/ui/Input";
import { Label } from "@/Components/ui/Label";
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from "@/hooks/useTranslation";
import { Mail, KeyRound, Delete, Eraser } from 'lucide-react';
import { cn } from "@/lib/utils";

export default function Login({ status, canResetPassword }) {
    const { tenant } = usePage().props;
    const { t } = useTranslation();
    const [loginMethod, setLoginMethod] = useState('email'); // 'email' | 'code'
    const [focusedField, setFocusedField] = useState('login_code');

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        login_code: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const handleKeypadClick = (value) => {
        if (!focusedField) return;
        if (loginMethod === 'email' && focusedField === 'login_code') return;

        const currentVal = data[focusedField] || '';
        
        if (value === 'clear') {
            setData(focusedField, '');
        } else if (value === 'backspace') {
            setData(focusedField, currentVal.slice(0, -1));
        } else {
            setData(focusedField, currentVal + value);
        }
    };

    const KeypadButton = ({ value, label, icon: Icon, className }) => (
        <Button
            type="button"
            variant="outline"
            className={cn("h-16 text-xl font-bold hover:bg-muted", className)}
            onClick={() => handleKeypadClick(value)}
        >
            {Icon ? <Icon className="h-6 w-6" /> : label || value}
        </Button>
    );

    return (
        <GuestLayout className={loginMethod === 'code' ? "sm:max-w-4xl" : "sm:max-w-md"}>
            <Head title={t("Log in")} />

            {tenant && (
                <div className="mb-6 text-center">
                    <h2 className="text-2xl font-bold">
                        {t("Welcome to :name", { name: tenant.id })}
                    </h2>
                </div>
            )}

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <div className="flex justify-center mb-6">
                <div className="bg-muted p-1 rounded-lg flex space-x-1">
                    <button
                        type="button"
                        onClick={() => setLoginMethod('email')}
                        className={cn(
                            "px-4 py-2 rounded-md text-sm font-medium transition-colors",
                            loginMethod === 'email' ? "bg-background shadow text-primary" : "text-muted-foreground hover:text-foreground"
                        )}
                    >
                        <div className="flex items-center space-x-2">
                            <Mail className="w-4 h-4" />
                            <span>{t("Email Login")}</span>
                        </div>
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setLoginMethod('code');
                            setFocusedField('login_code');
                        }}
                        className={cn(
                            "px-4 py-2 rounded-md text-sm font-medium transition-colors",
                            loginMethod === 'code' ? "bg-background shadow text-primary" : "text-muted-foreground hover:text-foreground"
                        )}
                    >
                        <div className="flex items-center space-x-2">
                            <KeyRound className="w-4 h-4" />
                            <span>{t("Code Login")}</span>
                        </div>
                    </button>
                </div>
            </div>

            <form onSubmit={submit}>
                <div className={cn("grid gap-8", loginMethod === 'code' ? "md:grid-cols-2" : "grid-cols-1")}>
                    <div className="space-y-4">
                        {loginMethod === 'email' ? (
                            <>
                                <div>
                                    <Label htmlFor="email">{t("Email")}</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        value={data.email}
                                        className="mt-1 block w-full"
                                        autoComplete="username"
                                        onChange={(e) => setData('email', e.target.value)}
                                    />
                                    {errors.email && <p className="mt-2 text-sm text-destructive">{errors.email}</p>}
                                </div>

                                <div className="mt-4">
                                    <Label htmlFor="password">{t("Password")}</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        name="password"
                                        value={data.password}
                                        className="mt-1 block w-full"
                                        autoComplete="current-password"
                                        onChange={(e) => setData('password', e.target.value)}
                                    />
                                    {errors.password && <p className="mt-2 text-sm text-destructive">{errors.password}</p>}
                                </div>
                            </>
                        ) : (
                            <div>
                                <Label htmlFor="login_code">{t("Agent Code")}</Label>
                                <Input
                                    id="login_code"
                                    type="text"
                                    name="login_code"
                                    value={data.login_code}
                                    className="mt-1 block w-full text-center text-3xl h-16 font-mono tracking-widest"
                                    readOnly
                                    onFocus={() => setFocusedField('login_code')}
                                />
                                {errors.login_code && <p className="mt-2 text-sm text-destructive">{errors.login_code}</p>}
                                <p className="mt-2 text-sm text-muted-foreground text-center">
                                    {t("Enter your 6-digit agent code using the keypad")}
                                </p>
                            </div>
                        )}

                        <div className="block mt-4">
                            <label className="flex items-center">
                                <Checkbox
                                    name="remember"
                                    checked={data.remember}
                                    onCheckedChange={(checked) => setData('remember', checked)}
                                />
                                <span className="ms-2 text-sm text-muted-foreground">{t("Remember me")}</span>
                            </label>
                        </div>

                        <div className="flex items-center justify-between mt-4">
                            {canResetPassword && (
                                <Link
                                    href={route('password.request')}
                                    className="underline text-sm text-muted-foreground hover:text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    {t("Forgot your password?")}
                                </Link>
                            )}

                            <Button className="ms-4" disabled={processing}>
                                {t("Log in")}
                            </Button>
                        </div>
                        
                        <div className="mt-4 text-center">
                            <p className="text-sm text-muted-foreground">
                                {t("Don't have an account?")}{' '}
                                <Link href={route('register')} className="text-primary hover:underline">
                                    {t("Register Agency")}
                                </Link>
                            </p>
                        </div>
                    </div>

                    {loginMethod === 'code' && (
                        <div className="bg-muted p-6 rounded-xl border border-input shadow-inner">
                            <div className="grid grid-cols-3 gap-3">
                                {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((num) => (
                                    <KeypadButton key={num} value={num.toString()} />
                                ))}
                                <KeypadButton value="clear" icon={Eraser} className="text-destructive hover:bg-destructive/10" />
                                <KeypadButton value="0" />
                                <KeypadButton value="backspace" icon={Delete} />
                            </div>
                        </div>
                    )}
                </div>
            </form>
        </GuestLayout>
    );
}
