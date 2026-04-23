import React, { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    BookOpenCheckIcon,
    LayoutDashboardIcon,
    LogOutIcon,
    MenuIcon,
    XIcon,
    PlaneTakeoffIcon,
    SettingsIcon,
    ShieldCheckIcon,
    ShoppingCartIcon,
    UserIcon,
    UsersIcon,
} from 'lucide-react';
import { Toaster, toast } from 'sonner';

import { Button } from '@/Components/ui/Button';
import { Separator } from '@/Components/ui/separator';
import { useIsMobile } from '@/hooks/use-mobile';

export default function TenantLayout({ children }) {
    const { auth, tenant, flash } = usePage().props;
    const currentPath = usePage().url;
    const isMobile = useIsMobile();
    const [desktopExpanded, setDesktopExpanded] = useState(true);
    const [mobileOpen, setMobileOpen] = useState(false);

    const isAdmin = auth.user?.role === 'admin';

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error, {
                duration: 10000,
            });
        }
    }, [flash]);

    const hasRoute = (routeName) => {
        try {
            return route().has(routeName);
        } catch {
            return false;
        }
    };

    const isActive = (routeName) => {
        try {
            return route().current(routeName) || route().current(`${routeName}.*`);
        } catch {
            return false;
        }
    };

    const mainLinks = [
        { name: 'Dashboard', route: 'dashboard', icon: LayoutDashboardIcon },
        { name: 'Flights', route: 'flights.index', icon: BookOpenCheckIcon },
        { name: 'Orders', route: 'orders.index', icon: ShoppingCartIcon },
    ].filter((item) => hasRoute(item.route));

    const adminLinks = [
        { name: 'Users', route: 'users.index', icon: UsersIcon },
        { name: 'Air Config', route: 'settings.airlines.index', icon: PlaneTakeoffIcon },
        { name: 'General Settings', route: 'settings.general.index', icon: SettingsIcon },
    ].filter((item) => isAdmin && hasRoute(item.route));

    const accountLinks = [
        { name: 'Profile', route: 'profile.edit', icon: UserIcon },
        { name: 'Password', route: 'user-password.edit', icon: ShieldCheckIcon },
    ].filter((item) => hasRoute(item.route));

    const toggleSidebar = () => {
        if (isMobile) {
            setMobileOpen((value) => !value);
            return;
        }

        setDesktopExpanded((value) => !value);
    };

    const closeMobileSidebar = () => {
        if (isMobile) {
            setMobileOpen(false);
        }
    };

    const renderNavSection = (label, links) => {
        if (links.length === 0) {
            return null;
        }

        return (
            <div className="space-y-2">
                {desktopExpanded && <p className="px-2 text-sm text-muted-foreground">{label}</p>}
                <div className="space-y-1">
                    {links.map((item) => {
                        const Icon = item.icon;

                        return (
                            <Link
                                key={item.route}
                                href={route(item.route)}
                                onClick={closeMobileSidebar}
                                className={`flex items-center gap-3 rounded-md px-3 py-2 text-base transition-colors hover:bg-accent hover:text-accent-foreground ${
                                    isActive(item.route) ? 'bg-accent text-accent-foreground font-medium' : 'text-foreground'
                                }`}
                                title={desktopExpanded ? undefined : item.name}
                            >
                                <Icon className="size-5 shrink-0" />
                                {desktopExpanded && <span className="truncate">{item.name}</span>}
                            </Link>
                        );
                    })}
                </div>
            </div>
        );
    };

    const sidebarContent = (
        <div className="flex h-full flex-col bg-card">
            <div className="border-b p-2">
                <Link
                    href={hasRoute('dashboard') ? route('dashboard') : '/dashboard'}
                    onClick={closeMobileSidebar}
                    className="flex items-center gap-3 rounded-md px-3 py-2 hover:bg-accent"
                >
                    <LayoutDashboardIcon className="size-5 shrink-0" />
                    {desktopExpanded && <span className="truncate text-xl font-semibold">{tenant?.companyName || 'TAMS Agency'}</span>}
                </Link>
            </div>

            <nav className="flex-1 space-y-6 overflow-y-auto p-4">
                {renderNavSection('Workspace', mainLinks)}
                {renderNavSection('Administration', adminLinks)}
                {renderNavSection('Account', accountLinks)}
            </nav>

            <div className="border-t p-3">
                <div className="px-2 py-1.5">
                    {desktopExpanded && <p className="truncate text-sm font-medium">{auth.user?.name}</p>}
                    {desktopExpanded && <p className="truncate text-xs text-muted-foreground">{auth.user?.email}</p>}
                    {desktopExpanded && tenant?.status && (
                        <p className="mt-1 text-[11px] uppercase tracking-[0.2em] text-muted-foreground">{tenant.status}</p>
                    )}
                </div>
                <Button asChild variant="ghost" className={`w-full ${desktopExpanded ? 'justify-start' : 'justify-center'}`}>
                    <Link href="/logout" method="post" as="button">
                        <LogOutIcon />
                        {desktopExpanded && <span>Logout</span>}
                    </Link>
                </Button>
            </div>
        </div>
    );

    return (
        <>
            <div className="flex min-h-dvh bg-background text-foreground">
                <aside
                    className={`hidden shrink-0 border-r md:flex md:h-dvh md:sticky md:top-0 md:flex-col md:transition-[width] md:duration-200 ${
                        desktopExpanded ? 'md:w-72' : 'md:w-18'
                    }`}
                >
                    {sidebarContent}
                </aside>

                {isMobile && mobileOpen && (
                    <button
                        type="button"
                        className="fixed inset-0 z-40 bg-black/40"
                        onClick={closeMobileSidebar}
                        aria-label="Close sidebar"
                    />
                )}

                <aside
                    className={`fixed inset-y-0 left-0 z-50 w-72 border-r bg-card transition-transform duration-200 md:hidden ${
                        mobileOpen ? 'translate-x-0' : '-translate-x-full'
                    }`}
                >
                    {sidebarContent}
                </aside>

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="sticky top-0 z-20 border-b bg-card">
                        <div className="flex h-14 items-center gap-3 px-4 sm:px-6">
                            <Button variant="ghost" size="icon" onClick={toggleSidebar} aria-label="Toggle sidebar">
                                {isMobile && mobileOpen ? <XIcon className="size-5" /> : <MenuIcon className="size-5" />}
                            </Button>
                            <Separator orientation="vertical" className="h-4!" />
                            <p className="text-sm text-muted-foreground">{currentPath}</p>
                        </div>
                    </header>

                    <main className="flex-1 overflow-y-auto p-6">{children}</main>
                </div>
            </div>
            <Toaster richColors position="top-right" />
        </>
    );
}
