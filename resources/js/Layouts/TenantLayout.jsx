import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Toaster } from "sonner";

export default function TenantLayout({ children }) {
    const { auth, tenant } = usePage().props;
    const canManageTickets = auth.user?.role === 'admin' || auth.user?.role === 'manager';

    return (
        <div className="min-h-screen bg-background text-foreground flex">
            {/* Sidebar */}
            <aside className="w-64 border-r bg-card flex flex-col">
                <div className="p-6 border-b flex items-center justify-between">
                    <div>
                        <Link href={route('dashboard')} className="text-xl font-bold tracking-tight">{tenant?.companyName || 'TAMS Agency'}</Link>
                        {tenant?.status && (
                            <p className="text-xs uppercase tracking-[0.2em] text-muted-foreground mt-1">{tenant.status}</p>
                        )}
                    </div>
                </div>

                <nav className="flex-1 p-4 space-y-2">
                    <Link href={route('dashboard')} className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                        Dashboard
                    </Link>
                    <Link href={route('bookings.index')} className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                        Bookings
                    </Link>
                    {canManageTickets && (
                        <div className="px-4 py-1 text-xs uppercase tracking-[0.2em] text-muted-foreground">
                            Ticketing enabled
                        </div>
                    )}
                    {auth.user?.role === 'admin' && (
                        <>
                            <Link href={route('users.index')} className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                                Users
                            </Link>
                            <Link href={route('settings.airlines.index')} className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                                Air Config
                            </Link>
                            <Link href={route('settings.general.index')} className="block px-4 py-2 text-sm font-medium rounded-md hover:bg-accent hover:text-accent-foreground transition-colors">
                                General Settings
                            </Link>
                        </>
                    )}
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
                    <h2 className="text-lg font-semibold">Agency Workspace</h2>
                    <div className="flex items-center gap-4">
                        <Link href="/logout" method="post" as="button" className="text-sm font-medium text-muted-foreground hover:text-primary">
                            Logout
                        </Link>
                    </div>
                </header>

                <main className="flex-1 p-8 overflow-y-auto">
                    {children}
                </main>
            </div>
            <Toaster richColors position="top-right" />
        </div>
    );
}
