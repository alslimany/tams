import React from 'react';
import { Head } from '@inertiajs/react';
import {
    BookOpenCheckIcon,
    PlaneTakeoffIcon,
    TicketCheckIcon,
    UsersIcon,
} from 'lucide-react';

import ChartSalesMetrics from '@/Components/shadcn-studio/blocks/chart-sales-metrics';
import TransactionDatatable from '@/Components/shadcn-studio/blocks/datatable-transaction';
import StatisticsCard from '@/Components/shadcn-studio/blocks/statistics-card-01';
import TotalEarningCard from '@/Components/shadcn-studio/blocks/widget-total-earning';
import ProductInsightsCard from '@/Components/shadcn-studio/blocks/widget-product-insights';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import TenantLayout from '@/Layouts/TenantLayout';

const earningData = [
    {
        img: 'https://cdn.shadcnstudio.com/ss-assets/blocks/dashboard-application/widgets/icon-4.svg',
        platform: 'App booking',
        technologies: 'Live + API',
        earnings: '$14,850',
        progressPercentage: 40,
    },
    {
        img: 'https://cdn.shadcnstudio.com/ss-assets/blocks/dashboard-application/widgets/icon-5.svg',
        platform: 'Portal booking',
        technologies: 'Web + Widget',
        earnings: '$10,430',
        progressPercentage: 32,
    },
    {
        img: 'https://cdn.shadcnstudio.com/ss-assets/blocks/dashboard-application/widgets/icon-6.svg',
        platform: 'Agent booking',
        technologies: 'Backoffice',
        earnings: '$9,125',
        progressPercentage: 28,
    },
];

const getInitials = (fullName) => {
    if (!fullName) {
        return 'GU';
    }

    const parts = fullName.trim().split(' ').filter(Boolean);

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
};

export default function Dashboard({ stats = {}, recentBookings = [] }) {
    const statCards = [
        {
            icon: <BookOpenCheckIcon className="size-4" />,
            title: "Today's bookings",
            value: (stats?.todaysBookings ?? 0).toLocaleString(),
            changePercentage: '+0%',
        },
        {
            icon: <TicketCheckIcon className="size-4" />,
            title: 'Issued tickets',
            value: (stats?.issuedTickets ?? 0).toLocaleString(),
            changePercentage: '+0%',
        },
        {
            icon: <UsersIcon className="size-4" />,
            title: 'Active agents',
            value: (stats?.activeAgents ?? 0).toLocaleString(),
            changePercentage: '+0%',
        },
        {
            icon: <PlaneTakeoffIcon className="size-4" />,
            title: 'Active providers',
            value: (stats?.activeProviders ?? 0).toLocaleString(),
            changePercentage: '+0%',
        },
    ];

    const transactionData = (recentBookings ?? []).map((booking, index) => {
        const customerName = `${booking?.first_name ?? ''} ${booking?.surname ?? ''}`.trim() || 'Guest Customer';

        return {
            id: booking?.id,
            name: customerName,
            avatar: null,
            avatarFallback: getInitials(customerName),
            email: booking?.email || 'no-email@guest.local',
            amount: Number(booking?.total_price ?? 0),
            status: booking?.status || 'pending',
            paidBy: index % 2 === 0 ? 'mastercard' : 'visa',
        };
    });

    return (
        <TenantLayout>
            <Head title="Dashboard" />

            <div className="space-y-6">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {statCards.map((card) => (
                        <StatisticsCard
                            key={card.title}
                            icon={card.icon}
                            title={card.title}
                            value={card.value}
                            changePercentage={card.changePercentage}
                        />
                    ))}
                </div>

                <div className="grid gap-4 xl:grid-cols-4">
                    <TotalEarningCard
                        title="Total booking value"
                        earning={Number(stats?.ticketValue ?? 0).toLocaleString()}
                        trend="up"
                        percentage="8"
                        comparisonText="Tracking current booking channels"
                        earningData={earningData}
                        className="xl:col-span-2"
                    />
                    <ProductInsightsCard className="xl:col-span-2" />
                </div>

                <ChartSalesMetrics />

                <Card>
                    <CardHeader>
                        <CardTitle>Recent bookings</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <TransactionDatatable data={transactionData} />
                    </CardContent>
                </Card>
            </div>
        </TenantLayout>
    );
}
