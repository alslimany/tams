import React from 'react';
import { Head } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';

export default function Dashboard({ stats = {}, recentBookings = [] }) {
    return (
        <TenantNavbarLayout>
            <Head title="Dashboard" />

            <p>Welcome</p>
            
        </TenantNavbarLayout>
    );
}
