import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Plane } from 'lucide-react';

export default function StorefrontLayout({ children }) {
    const { tenant, auth } = usePage().props;
    const isTenant = !!tenant?.id;
    const logoUrl = '/img/logo-light.svg';

    return (
        <div className="min-h-screen bg-white text-slate-950 flex flex-col font-sans">
            {/* Navigation */}
            <header className="sticky top-0 z-50 w-full border-b bg-white/80 backdrop-blur-md">
                <div className="container mx-auto px-4 md:px-6 h-16 flex items-center justify-between">
                    <div className="flex items-center gap-8">
                        <Link href="/" className="flex items-center gap-2 group">
                            <div className="size-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary shadow-sm group-hover:scale-105 transition-transform overflow-hidden">
                                <img
                                    src={logoUrl}
                                    alt="BookNow Logo"
                                    className="h-6 w-6 object-contain"
                                    onError={(event) => {
                                        event.currentTarget.style.display = 'none';
                                        event.currentTarget.parentElement?.classList.add('bg-primary');
                                        const fallback = event.currentTarget.nextElementSibling;
                                        if (fallback) {
                                            fallback.style.display = 'block';
                                        }
                                    }}
                                />
                                <Plane className="h-4 w-4 text-white" style={{ display: 'none' }} />
                            </div>
                            <span className="text-lg font-bold tracking-tight">
                                {isTenant ? (tenant.companyName || tenant.id) : 'BOOKNOW'}
                            </span>
                        </Link>
                        
                        {!isTenant && (
                            <nav className="hidden md:flex items-center gap-6">
                                <Link href="#features" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">Features</Link>
                                <Link href="#offers" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">Offers</Link>
                                <Link href="#contact" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">Contact</Link>
                            </nav>
                        )}
                    </div>

                    <div className="flex items-center gap-4">
                        {isTenant ? (
                            auth.user ? (
                                <Button asChild variant="outline" className="rounded-full font-medium">
                                    <Link href={route('dashboard')}>Go to Dashboard</Link>
                                </Button>
                            ) : (
                                <Button asChild className="rounded-full font-medium">
                                    <Link href={route('login')}>Agent Login</Link>
                                </Button>
                            )
                        ) : (
                            <>
                                <Link href={route('landlord.login')} className="text-sm font-medium text-slate-600 hover:text-primary transition-colors px-2">Admin</Link>
                                <Button asChild className="rounded-full font-medium px-5">
                                    <Link href="/register-agency">Get Started</Link>
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            </header>

            {/* Main Content */}
            <main className="flex-1">
                {children}
            </main>

            {/* Footer */}
            <footer className="border-t bg-slate-50 py-10">
                <div className="container mx-auto px-4 md:px-6">
                    <div className="flex flex-col md:flex-row justify-between gap-12">
                        <div className="space-y-6 max-w-xs">
                            <div className="flex items-center gap-2">
                                <div className="size-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary overflow-hidden">
                                    <img
                                        src={logoUrl}
                                        alt="BookNow Logo"
                                        className="h-6 w-6 object-contain"
                                        onError={(event) => {
                                            event.currentTarget.style.display = 'none';
                                            const fallback = event.currentTarget.nextElementSibling;
                                            if (fallback) {
                                                fallback.style.display = 'block';
                                            }
                                        }}
                                    />
                                    <Plane className="h-4 w-4" style={{ display: 'none' }} />
                                </div>
                                <span className="text-lg font-bold tracking-tight">BOOKNOW</span>
                            </div>
                            <p className="text-sm text-slate-500 font-medium leading-relaxed">
                                Empowering travel agencies with modern tools to manage bookings, airline accounts, and agent productivity.
                            </p>
                        </div>
                        
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-12 md:gap-24">
                            <div className="space-y-4">
                                <h4 className="font-semibold text-sm uppercase tracking-wider text-slate-500">Product</h4>
                                <ul className="space-y-3">
                                    <li><Link href="#" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">Features</Link></li>
                                    <li><Link href="#" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">Integrations</Link></li>
                                    <li><Link href="#offers" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">Offers</Link></li>
                                </ul>
                            </div>
                            <div className="space-y-4">
                                <h4 className="font-semibold text-sm uppercase tracking-wider text-slate-500">Company</h4>
                                <ul className="space-y-3">
                                    <li><Link href="#contact" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">Contact Us</Link></li>
                                    <li><a href="mailto:sales@booknow.ly" className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">sales@booknow.ly</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div className="border-t mt-12 pt-6 flex flex-col md:flex-row justify-between items-center gap-4">
                        <p className="text-xs font-medium text-slate-500">
                            © {new Date().getFullYear()} BookNow. All rights reserved.
                        </p>
                    </div>
                </div>
            </footer>
        </div>
    );
}
