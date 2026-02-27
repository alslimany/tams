import React from 'react';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children, className = "sm:max-w-md" }) {
    return (
        <div className="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-background">
            <div>
                <Link href="/">
                    <h1 className="text-3xl font-bold tracking-tighter">TAMS</h1>
                </Link>
            </div>

            <div className={`w-full mt-6 px-6 py-4 bg-card shadow-md overflow-hidden sm:rounded-lg border ${className}`}>
                {children}
            </div>
        </div>
    );
}
