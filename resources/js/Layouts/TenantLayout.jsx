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
    Share2Icon,
} from 'lucide-react';
import { Toaster, toast } from 'sonner';

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
import { useTranslation } from '@/hooks/useTranslation';

export default function TenantLayout({ children }) {
    const { auth, tenant, flash, agencySettings, locale } = usePage().props;
    const currentPath = usePage().url;
    const { t } = useTranslation();
    const isRtl = locale === 'ar';

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
        { name: t('common.dashboard'), route: 'dashboard', icon: LayoutDashboardIcon },
        { name: t('common.flights'), route: 'flights.index', icon: BookOpenCheckIcon },
        { name: t('common.hotels'), route: 'hotels.index', icon: HotelIcon },
        { name: t('common.insurance_search'), route: 'insurance.search', icon: ShieldIcon },
    ].filter((item) => hasRoute(item.route));

    const adminLinks = [
        { name: t('tenant.nav.users'), route: 'users.index', icon: UsersIcon },
        { name: t('tenant.nav.agency_network'), route: 'network.index', icon: Share2Icon },
        { name: t('tenant.nav.air_config'), route: 'settings.airlines.index', icon: PlaneTakeoffIcon, showWhen: canManageProviders },
        { name: t('tenant.nav.hotel_config'), route: 'settings.hotels.index', icon: HotelIcon },
        { name: t('tenant.nav.insurance_config'), route: 'settings.insurance.index', icon: ShieldIcon },
        { name: t('tenant.nav.general_settings'), route: 'settings.general.index', icon: SettingsIcon },
    ].filter((item) => isAdmin && hasRoute(item.route) && (item.showWhen !== false));

    const financeLinks = [
        { name: t('tenant.nav.orders'), route: 'orders.index', icon: ShoppingCartIcon },
        { name: t('tenant.nav.sales'), route: 'reports.sales', icon: BarChart3Icon },
        { name: t('tenant.nav.commissions'), route: 'reports.commissions', icon: ReceiptTextIcon },
        { name: t('tenant.nav.taxes'), route: 'reports.taxes', icon: PlaneLandingIcon },
        { name: t('tenant.nav.wallet_transactions'), route: 'wallet.transactions', icon: WalletIcon },
        { name: t('tenant.nav.reconciliation'), route: 'reports.reconciliation', icon: ScaleIcon, showWhen: isAdmin },
    ].filter((item) => hasRoute(item.route) && (item.showWhen !== false));

    const accountLinks = [
        { name: t('common.profile'), route: 'profile.edit', icon: UserIcon },
        { name: t('common.password'), route: 'user-password.edit', icon: ShieldCheckIcon },
    ].filter((item) => hasRoute(item.route));

    const currentPathLabel = currentPath
        .split('?')[0]
        .split('/')
        .filter(Boolean)
        .map((segment) => segment.replace(/-/g, ' '))
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' / ') || t('common.dashboard');

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
                    <span>{t('tenant.default_agency_notice')}</span>
                </div>
            )}
            <SidebarProvider>
                <Sidebar collapsible="icon" variant="inset" side={isRtl ? 'right' : 'left'} dir={isRtl ? 'rtl' : 'ltr'}>
                    <SidebarHeader>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton size="lg" asChild tooltip={t('common.dashboard')}>
                                    <Link href={hasRoute('dashboard') ? route('dashboard') : '/dashboard'}>
                                        <div className="flex size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                                            <LayoutDashboardIcon className="size-4" />
                                        </div>
                                        <span className="truncate text-sm font-semibold">{tenant?.companyName || t('brand.name')}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarHeader>

                    <SidebarContent>
                        {renderNavSection(t('tenant.nav.workspace'), mainLinks)}
                        {renderNavSection(t('tenant.nav.finance'), financeLinks)}
                        {renderNavSection(t('tenant.nav.administration'), adminLinks)}
                        {renderNavSection(t('tenant.nav.account'), accountLinks)}
                    </SidebarContent>

                    <SidebarFooter>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton asChild tooltip={t('common.logout')}>
                                    <Link href="/logout" method="post" as="button">
                                        <LogOutIcon />
                                        <span>{t('common.logout')}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarFooter>
                </Sidebar>

                <SidebarInset className="overflow-x-hidden">
                    <header className="sticky top-0 z-20 border-b border-sidebar-border/70 bg-background/90 backdrop-blur">
                        <div className="flex h-14 items-center gap-3 px-4 sm:px-6">
                            <SidebarTrigger className="-ms-1" />

                            <p className="truncate text-sm text-muted-foreground">{currentPathLabel}</p>
                        </div>
                    </header>

                    <main className="flex-1 overflow-y-auto p-4 sm:p-6">{children}</main>
                </SidebarInset>
            </SidebarProvider>
            <Toaster richColors position={isRtl ? 'top-left' : 'top-right'} />
        </>
    );
}
