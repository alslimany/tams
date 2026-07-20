import { Link, usePage } from '@inertiajs/react';
import {
    ArrowDownToLineIcon,
    ArrowRightLeftIcon,
    ArrowUpFromLineIcon,
    BarChart3Icon,
    BookTextIcon,
    CalculatorIcon,
    FileTextIcon,
    HistoryIcon,
    LayoutDashboardIcon,
    ListIcon,
    PackageIcon,
    ScaleIcon,
    SettingsIcon,
    Share2Icon,
    ShieldOffIcon,
    WalletIcon,
    WarehouseIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useTranslation } from '@/hooks/useTranslation';

function AccountingSidebarLink({ item }) {
    const Icon = item.icon;
    const currentUrl = usePage().url;

    const hasRoute = (() => {
        try {
            return route().has(item.route);
        } catch {
            return false;
        }
    })();

    if (!hasRoute) return null;

    const itemUrl = (() => {
        try {
            return new URL(route(item.route)).pathname;
        } catch {
            return null;
        }
    })();

    const isActive = (() => {
        try {
            // Exact route match or child route match
            if (route().current(item.route) || route().current(`${item.route}.*`)) {
                return true;
            }
            // URL prefix fallback for SPA navigation (e.g. wallets.show highlights wallets.index)
            if (itemUrl && currentUrl.split('?')[0].startsWith(itemUrl)) {
                return true;
            }
            return false;
        } catch {
            return false;
        }
    })();

    return (
        <Link
            href={route(item.route)}
            className={cn(
                'flex items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors',
                isActive
                    ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium border-l-2 border-primary'
                    : 'text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
            )}
        >
            <Icon className="size-4 shrink-0" />
            <span>{item.label}</span>
        </Link>
    );
}

export default function AccountingLayout({ children, title, subtitle, actions }) {
    const { t } = useTranslation();

    const navSections = [
        {
            heading: t('accounting.nav.overview'),
            items: [
                { label: t('accounting.nav.dashboard'), route: 'accounting.dashboard', icon: LayoutDashboardIcon },
            ],
        },
        {
            heading: t('accounting.nav.wallets_balances'),
            items: [
                { label: t('accounting.nav.all_wallets'), route: 'accounting.wallets.index', icon: WalletIcon },
                { label: t('accounting.nav.provider_wallets'), route: 'accounting.providers.index', icon: Share2Icon },
            ],
        },
        {
            heading: t('accounting.nav.ledger'),
            items: [
                { label: t('accounting.nav.journal_entries'), route: 'accounting.ledger.journal', icon: BookTextIcon },
                { label: t('accounting.nav.trial_balance'), route: 'accounting.ledger.trial-balance', icon: ScaleIcon },
                { label: t('accounting.nav.chart_of_accounts'), route: 'accounting.ledger.coa', icon: ListIcon },
            ],
        },
        {
            heading: t('accounting.nav.operations'),
            items: [
                { label: t('accounting.nav.issuance_history'), route: 'accounting.issuances.index', icon: HistoryIcon },
                { label: t('accounting.nav.settlement'), route: 'accounting.settlement.index', icon: FileTextIcon },
                { label: t('accounting.nav.cancellations'), route: 'accounting.cancellations.index', icon: ShieldOffIcon },
            ],
        },
        {
            heading: t('accounting.nav.inventory'),
            items: [
                { label: t('accounting.nav.warehouses'), route: 'accounting.inventory.warehouses', icon: WarehouseIcon },
                { label: t('accounting.nav.item_catalogue'), route: 'accounting.inventory.items', icon: PackageIcon },
                { label: t('accounting.nav.receive_goods'), route: 'accounting.inventory.receive', icon: ArrowDownToLineIcon },
                { label: t('accounting.nav.deliver_goods'), route: 'accounting.inventory.deliver', icon: ArrowUpFromLineIcon },
                { label: t('accounting.nav.transfer_goods'), route: 'accounting.inventory.transfer', icon: ArrowRightLeftIcon },
                { label: t('accounting.nav.movement_log'), route: 'accounting.inventory.movements', icon: HistoryIcon },
            ],
        },
        {
            heading: t('accounting.nav.reports'),
            items: [
                { label: t('accounting.nav.general_ledger'), route: 'accounting.reports.general-ledger', icon: BookTextIcon },
                { label: t('accounting.nav.balance_sheet'), route: 'accounting.reports.balance-sheet', icon: ScaleIcon },
                { label: t('accounting.nav.income_statement'), route: 'accounting.reports.income-statement', icon: BarChart3Icon },
                { label: t('accounting.nav.revenue'), route: 'accounting.reports.revenue', icon: BarChart3Icon },
                { label: t('accounting.nav.gross_margin'), route: 'accounting.reports.gross-margin', icon: BarChart3Icon },
                { label: t('accounting.nav.vat_summary'), route: 'accounting.reports.vat', icon: FileTextIcon },
                { label: t('accounting.nav.reconciliation'), route: 'accounting.reports.reconciliation', icon: ScaleIcon },
            ],
        },
        {
            heading: t('accounting.nav.settings_heading'),
            items: [
                { label: t('accounting.nav.preferences'), route: 'accounting.settings.index', icon: SettingsIcon },
                { label: t('accounting.nav.account_routing'), route: 'accounting.settings.routing', icon: Share2Icon },
            ],
        },
    ];

    return (
        <div className="flex h-full min-h-screen">
            {/* Accounting secondary sidebar */}
            <aside className="hidden w-56 shrink-0 border-r bg-sidebar lg:flex flex-col">
                <div className="flex items-center gap-2 px-4 py-4 border-b">
                    <CalculatorIcon className="size-4 text-primary" />
                    <span className="text-sm font-semibold">{t('accounting.nav.section_title')}</span>
                </div>
                <div className="flex-1 overflow-y-auto px-2 py-3">
                    <nav className="space-y-4">
                        {navSections.map((section) => (
                            <div key={section.heading}>
                                <p className="mb-1 px-3 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    {section.heading}
                                </p>
                                <div className="space-y-0.5">
                                    {section.items.map((item) => (
                                        <AccountingSidebarLink key={item.route} item={item} />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>
                </div>
            </aside>

            {/* Main content area */}
            <div className="flex-1 overflow-auto">
                <div className="border-b bg-background px-6 py-4">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h1 className="text-xl font-semibold">{title}</h1>
                            {subtitle && <p className="mt-0.5 text-sm text-muted-foreground">{subtitle}</p>}
                        </div>
                        {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
                    </div>
                </div>
                <div className="p-6">{children}</div>
            </div>
        </div>
    );
}
