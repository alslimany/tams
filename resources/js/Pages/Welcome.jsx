import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import LandlordLayout from '@/Layouts/LandlordLayout';

export default function Welcome() {
    return (
        <LandlordLayout>
            <div className="min-h-screen bg-background text-foreground flex flex-col items-center justify-center space-y-8 text-center p-4">
                <Head title="Welcome to TAMS" />
                
                <h1 className="text-4xl md:text-6xl font-extrabold tracking-tighter">
                    Travel Agency Management System
                </h1>
                
                <p className="max-w-[600px] text-muted-foreground text-lg md:text-xl">
                    The modern platform for travel agencies. Manage bookings, connect airline accounts, and scale your business.
                </p>
                
                <div className="flex flex-col sm:flex-row gap-4">
                    <Button asChild size="lg">
                        <Link href="/register-agency">Register your Agency</Link>
                    </Button>
                    <Button asChild variant="outline" size="lg">
                        <Link href={route('landlord.login')}>Landlord Login</Link>
                    </Button>
                </div>

                <p className="text-sm text-muted-foreground">
                    Tenant users sign in from their agency subdomain. Platform admins sign in through the landlord console.
                </p>
            </div>
        </LandlordLayout>
    );
}
