import React from "react";
import { Head, useForm, usePage } from "@inertiajs/react";
import TenantNavbarLayout from "@/Layouts/TenantNavbarLayout";
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardDescription,
} from "@/Components/ui/Card";
import { Label } from "@/Components/ui/Label";
import { Input } from "@/Components/ui/Input";
import { Button } from "@/Components/ui/Button";
import { ChevronLeft, SmartphoneNfc, CheckCircle2, User, Mail } from "lucide-react";
import { useTranslation } from "@/hooks/useTranslation";

function formatData(mb) {
    if (!mb) return "—";
    const gb = Number(mb) / 1024;
    return gb >= 1 ? `${gb % 1 === 0 ? gb : gb.toFixed(1)} GB` : `${mb} MB`;
}

function OrderSummary({ pkg, t }) {
    const price = Number(pkg?.price ?? 0).toFixed(2);
    const currency = pkg?.currency ?? "USD";

    return (
        <Card className="sticky top-6 overflow-hidden border-2 shadow-sm">
            <CardHeader className="border-b bg-primary/5 pb-4">
                <CardTitle className="text-base flex items-center gap-2">
                    <div className="rounded-full bg-primary/10 p-2">
                        <SmartphoneNfc className="w-4 h-4 text-primary" />
                    </div>
                    {t("esim.checkout.summary_title")}
                </CardTitle>
                <CardDescription>{t("esim.checkout.summary_subtitle")}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3 text-sm pt-4">
                <div className="flex justify-between">
                    <span className="text-muted-foreground">{t("esim.checkout.summary_package")}</span>
                    <span className="font-medium text-end">{pkg?.name ?? "—"}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">{t("esim.checkout.summary_country")}</span>
                    <span>{pkg?.country ?? "—"}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">{t("esim.checkout.summary_data")}</span>
                    <span>{formatData(pkg?.data_mb)}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">{t("esim.checkout.summary_validity")}</span>
                    <span>{pkg?.validity_days ? `${pkg.validity_days} days` : "—"}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">{t("esim.checkout.summary_provider")}</span>
                    <span>{pkg?.provider ?? "—"}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">{t("esim.checkout.summary_currency")}</span>
                    <span>{currency}</span>
                </div>
                <div className="border-t pt-3 flex justify-between font-semibold text-base">
                    <span>{t("esim.checkout.summary_total")}</span>
                    <span className="text-primary">{currency} {price}</span>
                </div>
            </CardContent>
        </Card>
    );
}

export default function ESimCheckout({ bookingUuid, package: pkg, search }) {
    const { props } = usePage();
    const flash = props.flash ?? {};
    const { t } = useTranslation();
    const [step, setStep] = React.useState("customer");

    const form = useForm({
        booking_uuid: bookingUuid,
        customer: {
            name: "",
            email: "",
        },
    });

    const setCustomer = (key, value) => {
        form.setData("customer", { ...form.data.customer, [key]: value });
    };

    const handleContinue = (e) => {
        e.preventDefault();
        if (!form.data.customer.name.trim() || !form.data.customer.email.trim()) return;
        setStep("review");
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        form.post(route("esim.book"));
    };

    const searchUuid = search?.uuid ?? null;

    return (
        <TenantNavbarLayout>
            <Head title={t("esim.checkout.title")} />

            {/* Hero */}
            <div className="bg-primary text-primary-foreground px-6 py-10">
                <div className="max-w-5xl mx-auto">
                    <a
                        href={searchUuid ? route("esim.results", searchUuid) : route("esim.index")}
                        className="inline-flex items-center gap-1 text-primary-foreground/70 hover:text-primary-foreground text-sm mb-4 transition-colors"
                    >
                        <ChevronLeft className="w-4 h-4" />
                        {t("esim.checkout.back_to_results")}
                    </a>
                    <h1 className="text-2xl font-bold">{t("esim.checkout.heading")}</h1>
                    <p className="text-primary-foreground/70 mt-1 text-sm">{t("esim.checkout.subheading")}</p>

                    {/* Step indicator */}
                    <div className="flex items-center gap-3 mt-6">
                        <div className={`flex items-center gap-2 text-sm font-medium ${step === "customer" ? "text-primary-foreground" : "text-primary-foreground/50"}`}>
                            <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold border-2 ${step === "customer" ? "bg-primary-foreground text-primary border-primary-foreground" : "border-primary-foreground/50 text-primary-foreground/50"}`}>
                                {step === "review" ? <CheckCircle2 className="w-3.5 h-3.5" /> : "1"}
                            </span>
                            {t("esim.checkout.step_customer")}
                        </div>
                        <div className="h-px w-8 bg-primary-foreground/30" />
                        <div className={`flex items-center gap-2 text-sm font-medium ${step === "review" ? "text-primary-foreground" : "text-primary-foreground/50"}`}>
                            <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold border-2 ${step === "review" ? "bg-primary-foreground text-primary border-primary-foreground" : "border-primary-foreground/50 text-primary-foreground/50"}`}>
                                2
                            </span>
                            {t("esim.checkout.step_confirm")}
                        </div>
                    </div>
                </div>
            </div>

            <div className="max-w-5xl mx-auto px-4 py-8">
                {flash.error && (
                    <div className="mb-6 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive px-4 py-3 text-sm">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Main form */}
                    <div className="lg:col-span-2">
                        {step === "customer" && (
                            <Card className="overflow-hidden border-2 shadow-sm">
                                <CardHeader className="border-b bg-primary/5 pb-4">
                                    <CardTitle className="flex items-center gap-2">
                                        <div className="rounded-full bg-primary/10 p-2">
                                            <User className="h-5 w-5 text-primary" />
                                        </div>
                                        {t("esim.checkout.customer_section")}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="pt-6">
                                    <form onSubmit={handleContinue} className="space-y-5">
                                        <div className="space-y-1.5">
                                            <Label htmlFor="customer_name">
                                                {t("esim.checkout.customer_name")}
                                            </Label>
                                            <div className="relative">
                                                <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                                                <Input
                                                    id="customer_name"
                                                    className="pl-9"
                                                    placeholder={t("esim.checkout.customer_name_placeholder")}
                                                    value={form.data.customer.name}
                                                    onChange={(e) => setCustomer("name", e.target.value)}
                                                    required
                                                />
                                            </div>
                                            {form.errors["customer.name"] && (
                                                <p className="text-destructive text-xs">{form.errors["customer.name"]}</p>
                                            )}
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="customer_email">
                                                {t("esim.checkout.customer_email")}
                                            </Label>
                                            <div className="relative">
                                                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                                                <Input
                                                    id="customer_email"
                                                    type="email"
                                                    className="pl-9"
                                                    placeholder={t("esim.checkout.customer_email_placeholder")}
                                                    value={form.data.customer.email}
                                                    onChange={(e) => setCustomer("email", e.target.value)}
                                                    required
                                                />
                                            </div>
                                            {form.errors["customer.email"] && (
                                                <p className="text-destructive text-xs">{form.errors["customer.email"]}</p>
                                            )}
                                        </div>

                                        <div className="flex justify-end pt-2">
                                            <Button type="submit">
                                                {t("esim.checkout.continue")}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}

                        {step === "review" && (
                            <Card className="overflow-hidden border-2 shadow-sm">
                                <CardHeader className="border-b bg-primary/5 pb-4">
                                    <CardTitle className="flex items-center gap-2">
                                        <div className="rounded-full bg-primary/10 p-2">
                                            <SmartphoneNfc className="h-5 w-5 text-primary" />
                                        </div>
                                        {t("esim.checkout.review_heading")}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="pt-6">
                                    <div className="space-y-3 text-sm mb-6">
                                        <div className="flex justify-between py-2 border-b">
                                            <span className="text-muted-foreground">{t("esim.checkout.review_customer")}</span>
                                            <span className="font-medium">{form.data.customer.name}</span>
                                        </div>
                                        <div className="flex justify-between py-2 border-b">
                                            <span className="text-muted-foreground">{t("esim.checkout.review_email")}</span>
                                            <span>{form.data.customer.email}</span>
                                        </div>
                                        <div className="flex justify-between py-2 border-b">
                                            <span className="text-muted-foreground">{t("esim.checkout.review_package")}</span>
                                            <span>{pkg?.name ?? "—"}</span>
                                        </div>
                                        <div className="flex justify-between py-2 border-b">
                                            <span className="text-muted-foreground">{t("esim.checkout.review_country")}</span>
                                            <span>{pkg?.country ?? "—"}</span>
                                        </div>
                                        <div className="flex justify-between py-2 border-b">
                                            <span className="text-muted-foreground">{t("esim.checkout.review_data")}</span>
                                            <span>{formatData(pkg?.data_mb)}</span>
                                        </div>
                                        <div className="flex justify-between py-2 border-b">
                                            <span className="text-muted-foreground">{t("esim.checkout.review_validity")}</span>
                                            <span>{pkg?.validity_days ? `${pkg.validity_days} days` : "—"}</span>
                                        </div>
                                        <div className="flex justify-between py-2">
                                            <span className="text-muted-foreground">{t("esim.checkout.review_provider")}</span>
                                            <span>{pkg?.provider ?? "—"}</span>
                                        </div>
                                    </div>

                                    <form onSubmit={handleSubmit}>
                                        <div className="flex justify-between items-center pt-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => setStep("customer")}
                                                disabled={form.processing}
                                            >
                                                <ChevronLeft className="w-4 h-4 me-1" />
                                                {t("esim.checkout.back")}
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={form.processing}
                                            >
                                                {form.processing
                                                    ? t("esim.checkout.purchasing")
                                                    : t("esim.checkout.confirm_purchase")}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Sidebar */}
                    <div className="lg:col-span-1">
                        <OrderSummary pkg={pkg} t={t} />
                    </div>
                </div>
            </div>
        </TenantNavbarLayout>
    );
}
