import React from 'react';
import { Head } from '@inertiajs/react';
import { TrendingUpIcon, TrendingDownIcon, PlaneIcon, WalletIcon, TicketIcon, UsersIcon, PackageIcon } from 'lucide-react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';

function formatMoney(amount, currency = 'USD') {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency,
    }).format(amount);
}

function StatCard({ title, value, subtitle, icon: Icon, trend, trendValue }) {
    const isPositive = trend === 'up';

    return (
        <div className="bg-card text-card-foreground rounded-lg border p-6">
            <div className="flex items-center justify-between space-x-4">
                <div className="flex-1 space-y-1">
                    <p className="text-sm text-muted-foreground">{title}</p>
                    <p className="text-2xl font-semibold">{value}</p>
                    {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
                </div>
                {Icon && (
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-muted">
                        <Icon className="h-6 w-6 text-muted-foreground" />
                    </div>
                )}
            </div>
            {trendValue !== undefined && (
                <div className={`mt-2 flex items-center text-xs ${isPositive ? 'text-green-600' : 'text-red-600'}`}>
                    {isPositive ? <TrendingUpIcon className="mr-1 h-3 w-3" /> : <TrendingDownIcon className="mr-1 h-3 w-3" />}
                    <span>{trendValue}% from last month</span>
                </div>
            )}
        </div>
    );
}

function RecentBookingsTable({ bookings }) {
    return (
        <div className="rounded-lg border">
            <div className="border-b px-6 py-4">
                <h3 className="text-lg font-semibold">Recent Bookings</h3>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full">
                    <thead className="bg-muted text-muted-foreground">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium uppercase">PNR</th>
                            <th className="px-6 py-3 text-left text-xs font-medium uppercase">Customer</th>
                            <th className="px-6 py-3 text-left text-xs font-medium uppercase">Status</th>
                            <th className="px-6 py-3 text-right text-xs font-medium uppercase">Total</th>
                            <th className="px-6 py-3 text-right text-xs font-medium uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {bookings.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-6 py-4 text-center text-sm text-muted-foreground">
                                    No bookings yet
                                </td>
                            </tr>
                        ) : (
                            bookings.map((booking) => (
                                <tr key={booking.id} className="hover:bg-muted/50">
                                    <td className="whitespace-nowrap px-6 py-4 font-mono text-sm">{booking.pnr || '-'}</td>
                                    <td className="px-6 py-4">
                                        <div className="text-sm font-medium">
                                            {booking.customer?.first_name} {booking.customer?.last_name}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4">
                                        <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                                            booking.status === 'issued'
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100'
                                                : booking.status === 'pending'
                                                ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100'
                                                : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100'
                                        }`}>
                                            {booking.status}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4 text-right font-medium">
                                        {formatMoney(booking.total, booking.currency)}
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-muted-foreground">
                                        {new Date(booking.created_at).toLocaleDateString()}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function TopRoutesCard({ routes }) {
    return (
        <div className="rounded-lg border">
            <div className="border-b px-6 py-4">
                <h3 className="text-lg font-semibold">Top Routes (30 Days)</h3>
            </div>
            <div className="p-6">
                {routes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No route data yet</p>
                ) : (
                    <div className="space-y-3">
                        {routes.map((route, index) => (
                            <div key={route.route} className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                        {index + 1}
                                    </span>
                                    <span className="font-mono text-sm">{route.route}</span>
                                </div>
                                <span className="text-sm font-medium">{route.count} bookings</span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function ProvidersCard({ providers }) {
    return (
        <div className="rounded-lg border">
            <div className="border-b px-6 py-4">
                <h3 className="text-lg font-semibold">Provider Status</h3>
            </div>
            <div className="p-6">
                <div className="space-y-3">
                    {providers.map((provider) => (
                        <div key={provider.id} className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className={`h-2 w-2 rounded-full ${provider.is_active ? 'bg-green-500' : 'bg-red-500'}`} />
                                <div>
                                    <p className="text-sm font-medium">{provider.airline_name}</p>
                                    <p className="text-xs text-muted-foreground">{provider.airline_code}</p>
                                </div>
                            </div>
                            <span className={`text-xs ${provider.is_active ? 'text-green-600' : 'text-red-600'}`}>
                                {provider.is_active ? 'Active' : 'Inactive'}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function WalletCard({ wallet }) {
    const currencies = ['LYD', 'USD', 'EUR'];

    return (
        <div className="rounded-lg border">
            <div className="border-b px-6 py-4">
                <h3 className="text-lg font-semibold">Wallet Overview</h3>
            </div>
            <div className="p-6">
                <div className="grid gap-4 sm:grid-cols-3">
                    {currencies.map((currency) => {
                        const data = wallet[currency] || {};
                        return (
                            <div key={currency} className="rounded-lg bg-muted p-4">
                                <p className="text-sm font-medium text-muted-foreground">{currency}</p>
                                <p className="mt-1 text-2xl font-semibold">{formatMoney(data.net || 0, currency)}</p>
                                <div className="mt-2 flex justify-between text-xs">
                                    <span className="text-green-600">+{formatMoney(data.deposits || 0, currency)}</span>
                                    <span className="text-red-600">-{formatMoney(Math.abs(data.withdrawals || 0), currency)}</span>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export default function Dashboard({
    stats = {},
    wallet = {},
    ticketStatus = {},
    recentBookings = [],
    providerStatus = [],
    topRoutes = [],
}) {
    const {
        todaysOrders = 0,
        todaysRevenue = 0,
        monthlyOrders = 0,
        monthlyRevenue = 0,
        revenueGrowth = 0,
        ordersGrowth = 0,
        totalIssuedTickets = 0,
        activeProviders = 0,
        totalCustomers = 0,
    } = stats;

    return (
        <TenantSidebarLayout>
            <Head title="Dashboard" />

            <div className="space-y-6">
                {/* Stats Grid */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Today's Orders"
                        value={todaysOrders}
                        subtitle={formatMoney(todaysRevenue)}
                        icon={PlaneIcon}
                    />
                    <StatCard
                        title="Monthly Orders"
                        value={monthlyOrders}
                        subtitle={formatMoney(monthlyRevenue)}
                        trend={ordersGrowth >= 0 ? 'up' : 'down'}
                        trendValue={ordersGrowth}
                        icon={PackageIcon}
                    />
                    <StatCard
                        title="Issued Tickets"
                        value={totalIssuedTickets}
                        icon={TicketIcon}
                    />
                    <StatCard
                        title="Active Customers"
                        value={totalCustomers}
                        icon={UsersIcon}
                    />
                </div>

                {/* Revenue Growth Alert */}
                {revenueGrowth !== 0 && (
                    <div className={`rounded-lg p-4 ${revenueGrowth >= 0 ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'}`}>
                        <div className="flex items-center">
                            {revenueGrowth >= 0 ? (
                                <TrendingUpIcon className="mr-2 h-5 w-5" />
                            ) : (
                                <TrendingDownIcon className="mr-2 h-5 w-5" />
                            )}
                            <span className="font-medium">
                                Revenue {revenueGrowth >= 0 ? 'up' : 'down'} {Math.abs(revenueGrowth)}% vs last month
                            </span>
                        </div>
                    </div>
                )}

                {/* Wallet Overview */}
                <WalletCard wallet={wallet} />

                {/* Main Content Grid */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <RecentBookingsTable bookings={recentBookings} />
                    <div className="space-y-6">
                        <TopRoutesCard routes={topRoutes} />
                        <ProvidersCard providers={providerStatus} />
                    </div>
                </div>
            </div>
        </TenantSidebarLayout>
    );
}
