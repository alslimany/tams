import React from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { useTranslation } from '@/hooks/useTranslation';
import { Globe, Loader2, SearchIcon, SmartphoneNfc } from 'lucide-react';

export default function ESimSearch() {
    const { t } = useTranslation();

    const form = useForm({
        country: '',
        data_mb: '',
        validity_days: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('esim.search'), { preserveScroll: true });
    };

    return (
        <TenantNavbarLayout>
            <Head title={t('esim.search.title')} />

            <section className="relative min-h-dvh bg-slate-900">
                <div
                    className="absolute inset-0 bg-cover bg-center opacity-40"
                    style={{ backgroundImage: "url('/img/search-hero-esim.png')" }}
                />
                <div className="absolute inset-0 bg-gradient-to-br from-violet-900/60 via-slate-900/40 to-slate-900/80" />

                <div className="relative z-10 mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div className="mb-10 text-center">
                        <Badge variant="secondary" className="mb-4 border-violet-500/30 bg-violet-500/10 text-violet-300">
                            <SmartphoneNfc className="mr-1 size-3" />
                            {t('esim.search.badge')}
                        </Badge>
                        <h1 className="text-balance text-4xl font-bold text-white sm:text-5xl lg:text-6xl">
                            {t('esim.search.heading')}
                        </h1>
                        <p className="mx-auto mt-4 max-w-2xl text-pretty text-lg text-slate-300">
                            {t('esim.search.subheading')}
                        </p>
                    </div>

                    <Card className="mx-auto max-w-3xl border-0 bg-white shadow-2xl dark:bg-slate-800">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <SearchIcon className="size-5 text-violet-600" />
                                {t('esim.search.form_title')}
                            </CardTitle>
                            <CardDescription>{t('esim.search.form_description')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-6" onSubmit={submit}>
                                <div className="grid gap-4 rounded-2xl border bg-muted/20 p-4 sm:grid-cols-3">
                                    {/* Country */}
                                    <div className="space-y-2 sm:col-span-3">
                                        <Label htmlFor="esim-country">
                                            {t('esim.search.country')}
                                        </Label>
                                        <div className="relative">
                                            <Globe className="absolute left-3 top-3 size-4 text-muted-foreground" />
                                            <Input
                                                id="esim-country"
                                                className="bg-white pl-9 dark:bg-slate-900"
                                                value={form.data.country}
                                                onChange={(e) => form.setData('country', e.target.value)}
                                                placeholder={t('esim.search.country_placeholder')}
                                                required
                                            />
                                        </div>
                                        {form.errors.country && (
                                            <p className="text-xs text-destructive">{form.errors.country}</p>
                                        )}
                                    </div>

                                    {/* Data size */}
                                    <div className="space-y-2">
                                        <Label htmlFor="esim-data">
                                            {t('esim.search.data_size')}
                                            <span className="ml-1 text-xs text-muted-foreground">({t('esim.search.optional')})</span>
                                        </Label>
                                        <Input
                                            id="esim-data"
                                            type="number"
                                            min="1"
                                            className="bg-white dark:bg-slate-900"
                                            value={form.data.data_mb}
                                            onChange={(e) => form.setData('data_mb', e.target.value)}
                                            placeholder={t('esim.search.data_size_placeholder')}
                                        />
                                        {form.errors.data_mb && (
                                            <p className="text-xs text-destructive">{form.errors.data_mb}</p>
                                        )}
                                    </div>

                                    {/* Validity */}
                                    <div className="space-y-2">
                                        <Label htmlFor="esim-validity">
                                            {t('esim.search.validity')}
                                            <span className="ml-1 text-xs text-muted-foreground">({t('esim.search.optional')})</span>
                                        </Label>
                                        <Input
                                            id="esim-validity"
                                            type="number"
                                            min="1"
                                            className="bg-white dark:bg-slate-900"
                                            value={form.data.validity_days}
                                            onChange={(e) => form.setData('validity_days', e.target.value)}
                                            placeholder={t('esim.search.validity_placeholder')}
                                        />
                                        {form.errors.validity_days && (
                                            <p className="text-xs text-destructive">{form.errors.validity_days}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-sm text-muted-foreground">{t('esim.search.hint')}</p>
                                    <Button
                                        type="submit"
                                        className="bg-violet-600 hover:bg-violet-700"
                                        disabled={form.processing}
                                    >
                                        {form.processing ? (
                                            <>
                                                <Loader2 className="mr-2 size-4 animate-spin" />
                                                {t('esim.search.searching')}
                                            </>
                                        ) : (
                                            <>
                                                <SearchIcon className="mr-2 size-4" />
                                                {t('esim.search.search_button')}
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </TenantNavbarLayout>
    );
}
