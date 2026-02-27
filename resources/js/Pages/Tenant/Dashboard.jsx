import React from 'react';
import { Head } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';

export default function Dashboard() {
    return (
        <TenantLayout>
            <Head title="Dashboard" />
            
            <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                <div className="aspect-video rounded-xl border bg-card p-6 shadow-sm">
                    <h3 className="text-sm font-medium text-muted-foreground">Today's Bookings</h3>
                    <p className="mt-2 text-3xl font-bold">0</p>
                </div>
                <div className="aspect-video rounded-xl border bg-card p-6 shadow-sm">
                    <h3 className="text-sm font-medium text-muted-foreground">Issued Tickets</h3>
                    <p className="mt-2 text-3xl font-bold">0</p>
                </div>
                <div className="aspect-video rounded-xl border bg-card p-6 shadow-sm">
                    <h3 className="text-sm font-medium text-muted-foreground">Active Agents</h3>
                    <p className="mt-2 text-3xl font-bold">1</p>
                </div>
            </div>

            <div className="mt-8 rounded-xl border bg-card p-6 shadow-sm h-64 flex items-center justify-center text-muted-foreground">
                No recent activity found.
            </div>
        </TenantLayout>
    );
}
