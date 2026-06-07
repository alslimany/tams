import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, MenuIcon, Package2, Plane, ShieldCheck, SmartphoneNfc } from 'lucide-react';
import { useTranslation } from "@/hooks/useTranslation";
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/Button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/Components/ui/sheet';
import UserMenu from '@/Components/UserMenu';

const Navbar = ({ children }) => {
    const { props } = usePage();
    const currentLocale = props.locale || 'en';
    const isRTL = currentLocale === 'ar';
    const [isProductsOpen, setIsProductsOpen] = React.useState(false);
    const tenantName = props.tenant?.companyName || props.tenant?.id || 'BookNow';
    const logoUrl = '/img/logo-light.svg';


     const { t } = useTranslation();
    const navLinks = [
       
        {
            title: t('common.flights'),
            href: route('flights.index'),
            active: route().current('flights.*'),
        },
        {
            title: t('common.insurance_search'),
            href: route('insurance.search'),
            active: route().current('insurance.*'),
        },
        {
            title: t('common.hotels'),
            href: route('hotels.index'),
            active: route().current('hotels.*'),
        },
        {
            title: t('common.esim'),
            href: route('esim.index'),
            active: route().current('esim.*'),
        },
        {
            title: t('common.my_orders'),
            href: route('orders.index'),
            active: route().current('orders.*'),
        },
    ];

    

    return (
        <div dir={isRTL ? 'rtl' : 'ltr'} className={isRTL ? 'rtl' : ''}>
            <header className="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur-md supports-backdrop-filter:bg-white/75">
                <div className="mx-auto flex max-w-7xl items-center justify-between gap-6 px-4 py-4 sm:px-6 lg:px-8">
                    <div className="flex items-center gap-8">
                        <Link href={route('dashboard')} className="flex items-center gap-3">
                           
                                <img
                                    src={logoUrl}
                                    alt="BookNow Logo"
                                    className="h-10 w-10 object-contain"
                                    onError={(event) => {
                                        event.currentTarget.style.display = 'none';
                                        event.currentTarget.parentElement?.classList.add('bg-primary');
                                    }}
                                />
                            
                            <div className="hidden sm:block">
                                <p className="text-[11px] font-black uppercase tracking-[0.24em] text-slate-400">Travel Workspace</p>
                                <p className="text-sm font-bold tracking-tight text-slate-950">{tenantName}</p>
                            </div>
                        </Link>

                        <nav className="hidden items-center gap-2 lg:flex">
                            {navLinks.map((item) => (
                                <Link
                                    key={item.title}
                                    href={item.href}
                                    className={cn(
                                        'rounded-full px-4 py-2 text-sm font-semibold transition-colors',
                                        item.active
                                            ? 'bg-slate-950 text-white shadow-sm'
                                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'
                                    )}
                                >
                                    {item.title}
                                </Link>
                            ))}

                           
                        </nav>
                    </div>

                    <div className="flex items-center gap-3">
                        <div className="hidden lg:block">
                            <UserMenu />
                        </div>

                        <Sheet>
                            <SheetTrigger asChild>
                                <Button variant="outline" size="icon" className="lg:hidden">
                                    <MenuIcon className="h-5 w-5" />
                                    <span className="sr-only">Open navigation</span>
                                </Button>
                            </SheetTrigger>
                            <SheetContent side={isRTL ? 'left' : 'right'} className="w-full max-w-sm border-slate-200 bg-white p-0">
                                <SheetHeader className="border-b border-slate-200 px-5 py-5 text-left">
                                    <SheetTitle>
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-slate-950 shadow-sm">
                                                <img src={logoUrl} alt="BookNow Logo" className="h-6 w-6 object-contain" />
                                            </div>
                                            <div>
                                                <p className="text-[11px] font-black uppercase tracking-[0.24em] text-slate-400">Travel Workspace</p>
                                                <p className="text-sm font-bold tracking-tight text-slate-950">{tenantName}</p>
                                            </div>
                                        </div>
                                    </SheetTitle>
                                </SheetHeader>

                                <div className="flex flex-col gap-6 px-5 py-6">
                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-2">
                                        {navLinks.map((item) => (
                                            <Link
                                                key={item.title}
                                                href={item.href}
                                                className={cn(
                                                    'block rounded-xl px-4 py-3 text-sm font-semibold transition-colors',
                                                    item.active
                                                        ? 'bg-slate-950 text-white shadow-sm'
                                                        : 'text-slate-700 hover:bg-white hover:text-slate-950'
                                                )}
                                            >
                                                {item.title}
                                            </Link>
                                        ))}
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 bg-white">
                                        <button
                                            type="button"
                                            onClick={() => setIsProductsOpen((current) => !current)}
                                            className="flex w-full items-center justify-between px-4 py-4 text-left"
                                        >
                                            <div>
                                                <p className="text-sm font-bold text-slate-950">Products</p>
                                                <p className="text-xs font-medium text-slate-500">Flights and insurance tools</p>
                                            </div>
                                            <ChevronDown className={cn('h-4 w-4 text-slate-500 transition-transform', isProductsOpen && 'rotate-180')} />
                                        </button>

                                        {isProductsOpen && (
                                            <div className="space-y-2 border-t border-slate-200 px-3 py-3">
                                                {productLinks.map((item) => {
                                                    const Icon = item.icon;

                                                    return (
                                                        <Link
                                                            key={item.title}
                                                            href={item.href}
                                                            className="flex items-start gap-3 rounded-xl px-3 py-3 transition hover:bg-slate-50"
                                                        >
                                                            <div className="mt-0.5 flex h-9 w-9 items-center justify-center rounded-xl bg-slate-950 text-white">
                                                                <Icon className="h-4 w-4" />
                                                            </div>
                                                            <div>
                                                                <p className="text-sm font-bold text-slate-950">{item.title}</p>
                                                                <p className="text-xs leading-relaxed text-slate-500">{item.description}</p>
                                                            </div>
                                                        </Link>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                        <div className="mb-3 flex items-center gap-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-950 text-white">
                                                <Package2 className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-bold text-slate-950">Account</p>
                                                <p className="text-xs font-medium text-slate-500">Open your user menu from the top-right on desktop.</p>
                                            </div>
                                        </div>
                                        <div className="flex justify-start">
                                            <UserMenu />
                                        </div>
                                    </div>
                                </div>
                            </SheetContent>
                        </Sheet>
                    </div>
                </div>
            </header>

            <main className="flex-1">{children}</main>
        </div>
    );
};

export default Navbar;
