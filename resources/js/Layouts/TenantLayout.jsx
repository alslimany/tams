import React, { useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3Icon,
    BookOpenCheckIcon,
    LayoutDashboardIcon,
    LogOutIcon,
    PlaneTakeoffIcon,
    HotelIcon,
    PlaneLandingIcon,
    ReceiptTextIcon,
    ScaleIcon,
    SettingsIcon,
    ShieldCheckIcon,
    ShoppingCartIcon,
    ShieldIcon,
    UserIcon,
    UsersIcon,
    WalletIcon,
} from 'lucide-react';
import { Toaster, toast } from 'sonner';
import { AlertCircleIcon } from 'lucide-react';

import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarTrigger,
} from '@/Components/ui/sidebar';
import { Separator } from '@/Components/ui/separator';

export default function TenantLayout({ children }) {
    const { auth, tenant, flash, agencySettings } = usePage().props;
    const currentPath = usePage().url;

    const isAdmin = auth.user?.role === 'admin';
    const canManageProviders = agencySettings?.can_manage_providers ?? true;

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
        { name: 'Hotels', route: 'hotels.index', icon: HotelIcon },
        { name: 'Insurance Search', route: 'insurance.search', icon: ShieldIcon },
    ].filter((item) => hasRoute(item.route));

    const adminLinks = [
        { name: 'Users', route: 'users.index', icon: UsersIcon },
        { name: 'Air Config', route: 'settings.airlines.index', icon: PlaneTakeoffIcon, showWhen: canManageProviders },
        { name: 'Hotel Config', route: 'settings.hotels.index', icon: HotelIcon },
        { name: 'Insurance Config', route: 'settings.insurance.index', icon: ShieldIcon },
        { name: 'General Settings', route: 'settings.general.index', icon: SettingsIcon },
    ].filter((item) => isAdmin && hasRoute(item.route) && (item.showWhen !== false));

    const financeLinks = [
        { name: 'Orders', route: 'orders.index', icon: ShoppingCartIcon },
        { name: 'Sales', route: 'reports.sales', icon: BarChart3Icon },
        { name: 'Commissions', route: 'reports.commissions', icon: ReceiptTextIcon },
        { name: 'Taxes', route: 'reports.taxes', icon: PlaneLandingIcon },
        { name: 'Wallet Txns', route: 'wallet.transactions', icon: WalletIcon },
        { name: 'Reconciliation', route: 'reports.reconciliation', icon: ScaleIcon, showWhen: isAdmin },
    ].filter((item) => hasRoute(item.route) && (item.showWhen !== false));

    const accountLinks = [
        { name: 'Profile', route: 'profile.edit', icon: UserIcon },
        { name: 'Password', route: 'user-password.edit', icon: ShieldCheckIcon },
    ].filter((item) => hasRoute(item.route));

    const currentPathLabel = currentPath
        .split('?')[0]
        .split('/')
        .filter(Boolean)
        .map((segment) => segment.replace(/-/g, ' '))
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' / ') || 'Dashboard';

    const renderNavSection = (label, links) => {
        if (links.length === 0) {
            return null;
        }

        return (
            <SidebarGroup>
                <SidebarGroupLabel>{label}</SidebarGroupLabel>
                <SidebarGroupContent>
                    <SidebarMenu>
                    {links.map((item) => {
                        const Icon = item.icon;

                        return (
                            <SidebarMenuItem key={item.route}>
                                <SidebarMenuButton asChild isActive={isActive(item.route)} tooltip={item.name}>
                                    <Link href={route(item.route)}>
                                        <Icon />
                                        <span>{item.name}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
        );
    };

    return (
        <>
            {agencySettings?.force_use_default_agency && (
                <div className="flex items-center gap-2 bg-amber-50 px-4 py-2 text-sm text-amber-800 border-b border-amber-200">
                    <PlaneLandingIcon className="size-4" />
                    <span>Airline providers are managed by the system. Your bookings use the default agency credentials.</span>
                </div>
            )}
            <SidebarProvider>
                <Sidebar collapsible="icon" variant="inset">
                    <SidebarHeader>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton size="lg" asChild tooltip="Dashboard">
                                    <Link href={hasRoute('dashboard') ? route('dashboard') : '/dashboard'}>
                                        <div className="flex size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                                            <LayoutDashboardIcon className="size-4" />
                                        </div>
                                        <span className="truncate text-sm font-semibold">{tenant?.companyName || 'TAMS Agency'}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarHeader>

                    <SidebarContent>
                        {renderNavSection('Workspace', mainLinks)}
                        {renderNavSection('Finance', financeLinks)}
                        {renderNavSection('Administration', adminLinks)}
                        {renderNavSection('Account', accountLinks)}
                    </SidebarContent>

                    <SidebarFooter>
                        <div className="rounded-md border border-sidebar-border bg-sidebar-accent/40 px-2 py-2 text-xs group-data-[collapsible=icon]:hidden">
                            <p className="truncate font-medium text-sidebar-foreground">{auth.user?.name}</p>
                            <p className="truncate text-sidebar-foreground/70">{auth.user?.email}</p>
                            {tenant?.status && (
                                <p className="mt-1 truncate uppercase tracking-[0.16em] text-[10px] text-sidebar-foreground/60">{tenant.status}</p>
                            )}
                        </div>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton asChild tooltip="Logout">
                                    <Link href="/logout" method="post" as="button">
                                        <LogOutIcon />
                                        <span>Logout</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarFooter>
                </Sidebar>

                <SidebarInset className="overflow-x-hidden">
                    <header className="sticky top-0 z-20 border-b border-sidebar-border/70 bg-background/90 backdrop-blur">
                        <div className="flex h-14 items-center gap-3 px-4 sm:px-6">
                            <SidebarTrigger className="-ml-1" />
                          
                            <p className="truncate text-sm text-muted-foreground">{currentPathLabel}</p>
                        </div>
                    </header>

                    <main className="flex-1 overflow-y-auto p-4 sm:p-6">{children}</main>
                </SidebarInset>
            </SidebarProvider>
            <Toaster richColors position="top-right" />
        </>
    );
}
