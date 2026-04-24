import React from 'react';
import { Head } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';

export default function Dashboard({ stats = {}, recentBookings = [] }) {
    return (
        <TenantLayout>
            <Head title="Dashboard" />

            <p>Welcome</p>
            
        </TenantLayout>
    );
}
