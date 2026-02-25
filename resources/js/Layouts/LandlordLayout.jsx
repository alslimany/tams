import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';

export default function LandlordLayout({ children }) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-background text-foreground flex flex-col">
            <header className="border-b bg-card px-6 py-4 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Link href="/" className="text-2xl font-bold tracking-tight">TAMS</Link>
                </div>
                
                <nav className="flex items-center gap-4">
                    <Link href="/" className="text-sm font-medium hover:text-primary transition-colors">Home</Link>
                    {auth.user ? (
                        <Link href="/dashboard" className="text-sm font-medium hover:text-primary transition-colors">Dashboard</Link>
                    ) : (
                        <>
                            <Link href="/login" className="text-sm font-medium hover:text-primary transition-colors">Login</Link>
                            <Button asChild variant="default" size="sm">
                                <Link href="/register-agency">Register Agency</Link>
                            </Button>
                        </>
                    )}
                </nav>
            </header>
            
            <main className="flex-1">
                {children}
            </main>
            
            <footer className="border-t py-6 text-center text-sm text-muted-foreground">
                © {new Date().getFullYear()} TAMS Inc. All rights reserved.
            </footer>
        </div>
    );
}
