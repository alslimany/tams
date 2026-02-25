import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';

export default function TenantLayout({ children }) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-background text-foreground flex">
            {/* Sidebar */}
            <aside className="w-64 border-r bg-card flex flex-col">
                <div className="p-6 border-b">
                    <Link href="/dashboard" className="text-xl font-bold tracking-tight">TAMS Agency</Link>
                </div>
                
                <nav className="flex-1 p-4 space-y-2">
                    <Link href="/dashboard" className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                        Dashboard
                    </Link>
                    <Link href="/bookings" className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                        Bookings
                    </Link>
                    <Link href="/providers" className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                        Providers
                    </Link>
                    <Link href="/users" className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                        Users
                    </Link>
                </nav>
                
                <div className="p-4 border-t">
                    <div className="flex items-center gap-3 px-4 py-2">
                        <div className="size-8 rounded-full bg-primary flex items-center justify-center text-primary-foreground font-bold">
                            {auth.user?.name?.charAt(0)}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium truncate">{auth.user?.name}</p>
                            <p className="text-xs text-muted-foreground truncate">{auth.user?.email}</p>
                        </div>
                    </div>
                </div>
            </aside>
            
            {/* Main Content */}
            <div className="flex-1 flex flex-col">
                <header className="h-16 border-b bg-card px-8 flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Dashboard</h2>
                    <div className="flex items-center gap-4">
                        <Button variant="ghost" size="sm">Settings</Button>
                        <Link href="/logout" method="post" as="button" className="text-sm font-medium text-muted-foreground hover:text-primary">
                            Logout
                        </Link>
                    </div>
                </header>
                
                <main className="flex-1 p-8 overflow-y-auto">
                    {children}
                </main>
            </div>
        </div>
    );
}
