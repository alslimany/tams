import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import StorefrontLayout from '@/Layouts/StorefrontLayout';
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
                <Head title="BookNow - Airline Ticket and Hotel Booking Platform" />
                
                {/* Hero Section */}
                <section className="relative py-14 md:py-20 overflow-hidden border-b bg-slate-50/30">
                    <div className="container mx-auto px-4 md:px-6 relative z-10">
                        <div className="flex flex-col items-center text-center space-y-6 max-w-3xl mx-auto">
                            <Badge variant="outline" className="px-3 py-1 border-primary/20 bg-primary/5 text-primary font-medium rounded-full uppercase text-[10px]">
                                Airline Ticketing and Hotel Booking
                            </Badge>
                            <h1 className="text-3xl md:text-5xl font-bold tracking-tight leading-tight text-slate-950">
                                One Platform for <span className="text-primary">Flights, Hotels, and Travel Operations</span>
                            </h1>
                            <p className="text-base md:text-lg text-slate-600 font-medium max-w-2xl leading-relaxed">
                                BookNow helps agencies and travel teams search airline fares, book hotels, issue tickets, and manage agents from one secure system.
                            </p>
                            
                            <div className="flex flex-col sm:flex-row gap-3 pt-2 w-full justify-center">
                                <Button asChild size="lg" className="font-semibold h-11 px-6 group">
                                    <Link href="/register-agency">
                                        Request a Demo <ArrowRight className="ml-2 h-4 w-4 group-hover:translate-x-1 transition-transform" />
                                    </Link>
                                </Button>
                                <Button asChild variant="outline" size="lg" className="font-semibold h-11 px-6 border-slate-200 hover:bg-slate-50">
                                    <Link href="#features">
                                        Explore Capabilities
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
                            <h2 className="text-2xl md:text-3xl font-semibold tracking-tight text-balance">Core Product Capabilities</h2>
                            <p className="text-base text-slate-500 font-medium max-w-2xl mx-auto leading-relaxed text-pretty">
                                Purpose-built for airline ticket and hotel booking businesses.
                            </p>
                        </div>

                        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                            {[
                                { title: 'Flight Search and Booking', desc: 'Search across connected airline providers and create bookings in seconds.', icon: Plane },
                                { title: 'Hotel Reservation Management', desc: 'Manage hotel offers, room selections, and itinerary-linked stays.', icon: Hotel },
                                { title: 'Ticketing and Post-Sales', desc: 'Handle issue, reissue, void, and refund workflows with full traceability.', icon: Ticket },
                                { title: 'Agency Team Control', desc: 'Secure roles, access control, and activity visibility for every agent.', icon: ShieldCheck },
                            ].map((f, i) => (
                                <Card key={i} className="border hover:border-primary/20 transition-all group overflow-hidden bg-white">
                                    <CardContent className="p-6 space-y-4">
                                        <div className="size-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center group-hover:bg-primary group-hover:text-white transition-all duration-300">
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
                                        <h3 className="font-semibold">Built for Agencies</h3>
                                    </div>
                                    <p className="text-sm text-slate-600 font-medium text-pretty">
                                        Operate B2B travel workflows with one workspace for flights, hotels, agents, and customer service.
                                    </p>
                                </CardContent>
                            </Card>
                            <Card className="border bg-white">
                                <CardContent className="p-6 space-y-3">
                                    <div className="flex items-center gap-2 text-primary">
                                        <Clock className="h-5 w-5" />
                                        <h3 className="font-semibold">Faster Operations</h3>
                                    </div>
                                    <p className="text-sm text-slate-600 font-medium text-pretty">
                                        Reduce manual steps with centralized booking handling and streamlined post-sales actions.
                                    </p>
                                </CardContent>
                            </Card>
                            <Card className="border bg-white">
                                <CardContent className="p-6 space-y-3">
                                    <div className="flex items-center gap-2 text-primary">
                                        <Globe className="h-5 w-5" />
                                        <h3 className="font-semibold">Multi-Market Ready</h3>
                                    </div>
                                    <p className="text-sm text-slate-600 font-medium text-pretty">
                                        Support regional and global customers with flexible provider integration and scalable access.
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
                            <h2 className="text-2xl md:text-3xl font-semibold tracking-tight text-slate-950 text-balance">Customer Offers</h2>
                            <p className="text-base text-slate-500 font-medium max-w-2xl mx-auto text-pretty">
                                Flexible plans and onboarding options for growing travel businesses.
                            </p>
                        </div>

                        <div className="grid md:grid-cols-2 gap-6 max-w-4xl mx-auto">
                            {[
                                {
                                    name: 'Starter',
                                    price: '49',
                                    features: ['1 Airline Provider', 'Hotel Module Ready', '5 Agent Accounts', 'Standard Support'],
                                    badge: 'Ideal for new agencies',
                                },
                                {
                                    name: 'Professional',
                                    price: '149',
                                    features: ['Unlimited Providers', 'Hotel + Flight Workflows', 'Unlimited Agents', 'Priority Support'],
                                    badge: 'Most selected by growing teams',
                                },
                            ].map((p, i) => (
                                <Card key={i} className={`border overflow-hidden bg-white ${i === 1 ? 'border-primary shadow-sm relative' : ''}`}>
                                    {i === 1 && <div className="absolute top-0 right-0 bg-primary text-white px-4 py-1 text-xs font-semibold uppercase">Recommended</div>}
                                    <CardHeader className="p-6 pb-4">
                                        <CardTitle className="text-xs font-semibold uppercase text-primary mb-2">{p.name}</CardTitle>
                                        <div className="flex items-baseline gap-1">
                                            <span className="text-4xl font-bold tracking-tight text-slate-950">${p.price}</span>
                                            <span className="text-slate-500 font-medium text-sm">/month</span>
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
                                            <Link href="/register-agency">Get Started Now</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        <div className="mt-8 text-center">
                            <Badge variant="secondary" className="px-3 py-1 text-xs font-medium">
                                <Sparkles className="h-3.5 w-3.5 mr-1" />
                                Limited Offer: Free migration and onboarding for annual plans
                            </Badge>
                        </div>
                    </div>
                </section>

                <section id="contact" className="py-14 md:py-16 border-t bg-white">
                    <div className="container mx-auto px-4 md:px-6">
                        <div className="max-w-3xl mx-auto text-center space-y-3 mb-10">
                            <h2 className="text-2xl md:text-3xl font-semibold tracking-tight text-balance">Contact Us</h2>
                            <p className="text-base text-slate-500 font-medium text-pretty">
                                Talk to our team about product fit, pricing, onboarding, or custom integrations.
                            </p>
                        </div>

                        <div className="grid gap-6 md:grid-cols-3 max-w-7xl mx-auto">
                            <Card className="border bg-white">
                                <CardContent className="p-6 text-center space-y-3">
                                    <Phone className="h-5 w-5 text-primary mx-auto" />
                                    <h3 className="font-semibold">Phone</h3>
                                    <a href="tel:+218910000000" className="text-sm text-slate-600 font-medium hover:text-primary">
                                        +218 91 000 0000
                                    </a>
                                </CardContent>
                            </Card>

                            <Card className="border bg-white">
                                <CardContent className="p-6 text-center space-y-3">
                                    <Mail className="h-5 w-5 text-primary mx-auto" />
                                    <h3 className="font-semibold">Email</h3>
                                    <a href="mailto:sales@booknow.ly" className="text-sm text-slate-600 font-medium hover:text-primary">
                                        sales@booknow.ly
                                    </a>
                                </CardContent>
                            </Card>

                            <Card className="border bg-white">
                                <CardContent className="p-6 text-center space-y-3">
                                    <MessageSquare className="h-5 w-5 text-primary mx-auto" />
                                    <h3 className="font-semibold">Live Inquiry</h3>
                                    <Link href="/register-agency" className="text-sm text-slate-600 font-medium hover:text-primary">
                                        Request callback
                                    </Link>
                                </CardContent>
                            </Card>
                        </div>

                        <div className="mt-10 flex justify-center">
                            <Button asChild size="lg" className="h-11 px-6 font-semibold">
                                <Link href="/register-agency">Contact Sales</Link>
                            </Button>
                        </div>
                    </div>
                </section>
            </div>
        </StorefrontLayout>
    );
}


