import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import StorefrontLayout from '@/Layouts/StorefrontLayout';
import { useTranslation } from '@/hooks/useTranslation';
import {
    Plane,
    ShieldCheck,
    Globe,
    Clock,
    CheckCircle2,
    ArrowRight,
    LogIn,
    Building2,
    Hotel,
    Ticket,
    Phone,
    Mail,
    MessageSquare,
    Sparkles,
} from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';

export default function Welcome() {
    const { tenant } = usePage().props;
    const { t } = useTranslation();

    // --- TENANT HOME PAGE (e.g. agency.tams.ly) ---
    if (tenant?.id) {
        return (
            <StorefrontLayout>
                <div className="min-h-[70vh] flex flex-col items-center justify-center px-4 py-10 md:py-14 space-y-8 bg-slate-50/50">
                    <Head title={`Welcome to ${tenant.companyName || tenant.id}`} />
                    
                    <div className="text-center space-y-4 max-w-2xl">
                        <div className="inline-flex items-center justify-center p-3 rounded-2xl bg-primary/10 text-primary mb-1">
                            <Plane className="h-8 w-8" />
                        </div>
                        <h1 className="text-3xl md:text-4xl font-bold tracking-tight text-slate-950">
                            {tenant.companyName || tenant.id}
                        </h1>
                        <p className="text-base md:text-lg text-slate-600 font-medium leading-relaxed">
                            Authorized Travel Agent Portal. Access your airline inventory, manage bookings, and issue tickets securely.
                        </p>
                    </div>

                    <Card className="w-full max-w-md border shadow-sm overflow-hidden">
                        <CardHeader className="bg-slate-50 border-b text-center pb-6 pt-6 px-6">
                            <CardTitle className="text-xl font-semibold tracking-tight">Agent Access</CardTitle>
                            <CardDescription className="text-sm font-medium">Sign in to your agency workspace</CardDescription>
                        </CardHeader>
                        <CardContent className="p-6">
                            <div className="grid gap-4">
                                <Button asChild size="lg" className="w-full font-semibold h-11">
                                    <Link href={route('login')}>
                                        <LogIn className="mr-2 h-4 w-4" /> Login to Agency
                                    </Link>
                                </Button>
                                <div className="p-3 rounded-lg bg-amber-50 border border-amber-100 flex gap-2 items-start">
                                    <ShieldCheck className="h-4 w-4 text-amber-600 mt-0.5 shrink-0" />
                                    <p className="text-xs text-amber-900 font-medium leading-relaxed">
                                        Only registered agents of {tenant.companyName || tenant.id} can access this system. All activities are logged.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </StorefrontLayout>
        );
    }

    // --- LANDLORD HOME PAGE (e.g. tams.ly) ---
    return (
        <StorefrontLayout>
            <div className="bg-white text-slate-950">
                <Head title={t('landlord.page_title')} />
                
                {/* Hero Section */}
                <section className="relative overflow-hidden border-b bg-slate-50/30 py-14 md:py-20">
                    <div className="absolute inset-x-0 top-0 h-px bg-primary/20" aria-hidden="true" />
                    <div className="container mx-auto px-4 md:px-6 relative z-10">
                        <div className="mx-auto flex max-w-4xl flex-col items-center space-y-6 text-center">
                            <Badge variant="outline" className="px-3 py-1 border-primary/20 bg-primary/5 text-primary font-medium rounded-full uppercase text-[10px]">
                                {t('landlord.hero_badge')}
                            </Badge>
                            <h1 className="text-balance text-3xl font-bold leading-tight tracking-tight text-slate-950 md:text-5xl">
                                {t('landlord.hero_title_prefix')} <span className="text-primary">{t('landlord.hero_title_highlight')}</span>
                            </h1>
                            <p className="max-w-2xl text-pretty text-base font-medium leading-relaxed text-slate-600 md:text-lg">
                                {t('landlord.hero_description')}
                            </p>

                            <div className="grid w-full max-w-3xl gap-3 pt-2 sm:grid-cols-3">
                                {[
                                    ['landlord.metric_products', '3+'],
                                    ['landlord.metric_wallets', '2x'],
                                    ['landlord.metric_markets', '3'],
                                ].map(([label, value]) => (
                                    <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                                        <p className="text-2xl font-bold text-slate-950 tabular-nums">{value}</p>
                                        <p className="mt-1 text-xs font-medium text-slate-500">{t(label)}</p>
                                    </div>
                                ))}
                            </div>
                            
                            <div className="flex flex-col sm:flex-row gap-3 pt-2 w-full justify-center">
                                <Button asChild size="lg" className="font-semibold h-11 px-6 group">
                                    <Link href="/register-agency">
                                        {t('landlord.request_demo')} <ArrowRight className="ms-2 h-4 w-4 transition-transform group-hover:translate-x-1 rtl:group-hover:-translate-x-1" />
                                    </Link>
                                </Button>
                                <Button asChild variant="outline" size="lg" className="font-semibold h-11 px-6 border-slate-200 hover:bg-slate-50">
                                    <Link href="#features">
                                        {t('landlord.explore_capabilities')}
                                    </Link>
                                </Button>
                                <Button asChild variant="outline" size="lg" className="font-semibold h-11 px-6 border-slate-200 hover:bg-slate-50">
                                    <Link href="/agency">
                                        <Building2 className="mr-2 h-4 w-4" /> {t('landlord.agent_login')}
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Features Section */}
                <section id="features" className="py-14 md:py-16">
                    <div className="container mx-auto px-4 md:px-6">
                        <div className="text-center mb-10 space-y-3">
                            <h2 className="text-2xl md:text-3xl font-semibold tracking-tight text-balance">{t('landlord.capabilities_title')}</h2>
                            <p className="text-base text-slate-500 font-medium max-w-2xl mx-auto leading-relaxed text-pretty">
                                {t('landlord.capabilities_description')}
                            </p>
                        </div>

                        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                            {[
                                { title: t('landlord.feature_flights_title'), desc: t('landlord.feature_flights_desc'), icon: Plane },
                                { title: t('landlord.feature_hotels_title'), desc: t('landlord.feature_hotels_desc'), icon: Hotel },
                                { title: t('landlord.feature_ticketing_title'), desc: t('landlord.feature_ticketing_desc'), icon: Ticket },
                                { title: t('landlord.feature_control_title'), desc: t('landlord.feature_control_desc'), icon: ShieldCheck },
                            ].map((f, i) => (
                                <Card key={i} className="border hover:border-primary/20 transition-all group overflow-hidden bg-white">
                                    <CardContent className="p-6 space-y-4">
                                        <div className="size-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center transition-colors group-hover:bg-primary group-hover:text-white">
                                            <f.icon className="h-6 w-6" />
                                        </div>
                                        <div className="space-y-2">
                                            <h3 className="text-lg font-semibold tracking-tight">{f.title}</h3>
                                            <p className="text-sm text-slate-600 leading-relaxed font-medium">{f.desc}</p>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="py-14 md:py-16 bg-white border-t">
                    <div className="container mx-auto px-4 md:px-6">
                        <div className="grid gap-6 lg:grid-cols-3">
                            <Card className="border bg-white">
                                <CardContent className="p-6 space-y-3">
                                    <div className="flex items-center gap-2 text-primary">
                                        <Building2 className="h-5 w-5" />
                                        <h3 className="font-semibold">{t('landlord.built_for_agencies')}</h3>
                                    </div>
                                    <p className="text-sm text-slate-600 font-medium text-pretty">
                                        {t('landlord.built_for_agencies_desc')}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card className="border bg-white">
                                <CardContent className="p-6 space-y-3">
                                    <div className="flex items-center gap-2 text-primary">
                                        <Clock className="h-5 w-5" />
                                        <h3 className="font-semibold">{t('landlord.faster_operations')}</h3>
                                    </div>
                                    <p className="text-sm text-slate-600 font-medium text-pretty">
                                        {t('landlord.faster_operations_desc')}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card className="border bg-white">
                                <CardContent className="p-6 space-y-3">
                                    <div className="flex items-center gap-2 text-primary">
                                        <Globe className="h-5 w-5" />
                                        <h3 className="font-semibold">{t('landlord.multi_market_ready')}</h3>
                                    </div>
                                    <p className="text-sm text-slate-600 font-medium text-pretty">
                                        {t('landlord.multi_market_ready_desc')}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </section>

                {/* Pricing Section */}
                <section id="offers" className="py-14 md:py-16 bg-slate-50/50 border-t">
                    <div className="container mx-auto px-4 md:px-6">
                        <div className="text-center mb-10 space-y-3">
                            <h2 className="text-2xl md:text-3xl font-semibold tracking-tight text-slate-950 text-balance">{t('landlord.offers_title')}</h2>
                            <p className="text-base text-slate-500 font-medium max-w-2xl mx-auto text-pretty">
                                {t('landlord.offers_description')}
                            </p>
                        </div>

                        <div className="grid md:grid-cols-2 gap-6 max-w-4xl mx-auto">
                            {[
                                {
                                    name: t('landlord.starter'),
                                    price: '49',
                                    features: [t('landlord.starter_feature_1'), t('landlord.starter_feature_2'), t('landlord.starter_feature_3'), t('landlord.starter_feature_4')],
                                    badge: t('landlord.starter_badge'),
                                },
                                {
                                    name: t('landlord.professional'),
                                    price: '149',
                                    features: [t('landlord.pro_feature_1'), t('landlord.pro_feature_2'), t('landlord.pro_feature_3'), t('landlord.pro_feature_4')],
                                    badge: t('landlord.pro_badge'),
                                },
                            ].map((p, i) => (
                                <Card key={i} className={`border overflow-hidden bg-white ${i === 1 ? 'border-primary shadow-sm relative' : ''}`}>
                                    {i === 1 && <div className="absolute top-0 end-0 bg-primary text-white px-4 py-1 text-xs font-semibold uppercase">{t('landlord.recommended')}</div>}
                                    <CardHeader className="p-6 pb-4">
                                        <CardTitle className="text-xs font-semibold uppercase text-primary mb-2">{p.name}</CardTitle>
                                        <div className="flex items-baseline gap-1">
                                            <span className="text-4xl font-bold tracking-tight text-slate-950">${p.price}</span>
                                            <span className="text-slate-500 font-medium text-sm">{t('landlord.per_month')}</span>
                                        </div>
                                        <p className="text-xs text-slate-500 font-medium">{p.badge}</p>
                                    </CardHeader>
                                    <CardContent className="p-6 pt-0 space-y-6">
                                        <ul className="space-y-3">
                                            {p.features.map((f, fi) => (
                                                <li key={fi} className="flex items-center gap-2 font-medium text-slate-700 text-sm">
                                                    <CheckCircle2 className="h-4 w-4 text-primary shrink-0" /> {f}
                                                </li>
                                            ))}
                                        </ul>
                                        <Button asChild size="lg" variant={i === 1 ? "default" : "outline"} className="w-full h-11 font-semibold transition-all border-slate-200 hover:bg-slate-50">
                                            <Link href="/register-agency">{t('landlord.get_started_now')}</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        <div className="mt-8 text-center">
                            <Badge variant="secondary" className="px-3 py-1 text-xs font-medium">
                                <Sparkles className="h-3.5 w-3.5 mr-1" />
                                {t('landlord.limited_offer')}
                            </Badge>
                        </div>
                    </div>
                </section>

                <section id="contact" className="py-14 md:py-16 border-t bg-white">
                    <div className="container mx-auto px-4 md:px-6">
                        <div className="max-w-3xl mx-auto text-center space-y-3 mb-10">
                            <h2 className="text-2xl md:text-3xl font-semibold tracking-tight text-balance">{t('landlord.contact_us')}</h2>
                            <p className="text-base text-slate-500 font-medium text-pretty">
                                {t('landlord.contact_description')}
                            </p>
                        </div>

                        <div className="grid gap-6 md:grid-cols-3 max-w-7xl mx-auto">
                            <Card className="border bg-white">
                                <CardContent className="p-6 text-center space-y-3">
                                    <Phone className="h-5 w-5 text-primary mx-auto" />
                                    <h3 className="font-semibold">{t('common.phone')}</h3>
                                    <a href="tel:+218910000000" className="text-sm text-slate-600 font-medium hover:text-primary">
                                        +218 91 000 0000
                                    </a>
                                </CardContent>
                            </Card>

                            <Card className="border bg-white">
                                <CardContent className="p-6 text-center space-y-3">
                                    <Mail className="h-5 w-5 text-primary mx-auto" />
                                    <h3 className="font-semibold">{t('common.email')}</h3>
                                    <a href="mailto:sales@booknow.ly" className="text-sm text-slate-600 font-medium hover:text-primary">
                                        sales@booknow.ly
                                    </a>
                                </CardContent>
                            </Card>

                            <Card className="border bg-white">
                                <CardContent className="p-6 text-center space-y-3">
                                    <MessageSquare className="h-5 w-5 text-primary mx-auto" />
                                    <h3 className="font-semibold">{t('landlord.live_inquiry')}</h3>
                                    <Link href="/register-agency" className="text-sm text-slate-600 font-medium hover:text-primary">
                                        {t('landlord.request_callback')}
                                    </Link>
                                </CardContent>
                            </Card>
                        </div>

                        <div className="mt-10 flex justify-center">
                            <Button asChild size="lg" className="h-11 px-6 font-semibold">
                                <Link href="/register-agency">{t('landlord.contact_sales')}</Link>
                            </Button>
                        </div>
                    </div>
                </section>
            </div>
        </StorefrontLayout>
    );
}

