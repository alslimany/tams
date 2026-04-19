import React, { useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Badge } from '@/Components/ui/Badge';
import { Toaster, toast } from "sonner";
import { LayoutDashboard, Users, Settings, LogOut, Plane, ShieldCheck, BarChart3 } from 'lucide-react';

export default function LandlordLayout({ children }) {
    const { auth, flash } = usePage().props;
    const landlordUser = auth.landlordUser;

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    return (
        <div className="min-h-screen bg-slate-50 text-slate-950 flex">
            {/* Sidebar */}
            <aside className="w-72 border-r bg-white flex flex-col sticky top-0 h-screen">
                <div className="p-8 border-b flex items-center gap-3">
                    <div className="size-10 rounded-xl bg-blue-600 flex items-center justify-center text-white shadow-lg shadow-blue-600/20">
                        <Plane className="h-6 w-6" />
                    </div>
                    <div>
                        <h1 className="text-xl font-black tracking-tighter">TAMS</h1>
                        <p className="text-[10px] uppercase tracking-widest font-bold text-slate-400">Landlord Console</p>
                    </div>
                </div>

                <nav className="flex-1 p-6 space-y-2">
                    <div className="text-[10px] uppercase tracking-[0.2em] font-black text-slate-400 mb-4 px-4">Management</div>
                    
                    <Link 
                        href={route('landlord.dashboard')} 
                        className={`flex items-center gap-3 px-4 py-3 text-sm font-bold rounded-xl transition-all ${route().current('landlord.dashboard') ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/20' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'}`}
                    >
                        <LayoutDashboard className="h-5 w-5" /> Dashboard
                    </Link>
                    
                    <Link 
                        href={route('landlord.tenants.index')} 
                        className={`flex items-center gap-3 px-4 py-3 text-sm font-bold rounded-xl transition-all ${route().current('landlord.tenants.*') ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/20' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'}`}
                    >
                        <Users className="h-5 w-5" /> Agencies
                    </Link>

                    <div className="pt-8 text-[10px] uppercase tracking-[0.2em] font-black text-slate-400 mb-4 px-4">System</div>
                    
                    <Link 
                        href="#" 
                        className="flex items-center gap-3 px-4 py-3 text-sm font-bold rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-all"
                    >
                        <BarChart3 className="h-5 w-5" /> Analytics
                    </Link>
                    
                    <Link 
                        href="#" 
                        className="flex items-center gap-3 px-4 py-3 text-sm font-bold rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-all"
                    >
                        <Settings className="h-5 w-5" /> Platform Settings
                    </Link>
                </nav>

                <div className="p-6 border-t bg-slate-50/50">
                    <div className="flex items-center gap-3 px-2 py-2 mb-4">
                        <div className="size-10 rounded-full bg-slate-200 border-2 border-white flex items-center justify-center text-slate-600 font-black shadow-sm">
                            {landlordUser?.name?.charAt(0)}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-black truncate">{landlordUser?.name}</p>
                            <p className="text-[10px] font-bold text-slate-400 truncate uppercase">{landlordUser?.email}</p>
                        </div>
                    </div>
                    <Link 
                        href={route('landlord.logout')} 
                        method="post" 
                        as="button" 
                        className="flex items-center justify-center gap-2 w-full px-4 py-3 text-xs font-black uppercase tracking-widest text-destructive hover:bg-destructive/5 rounded-xl transition-all border border-destructive/10"
                    >
                        <LogOut className="h-4 w-4" /> Logout Console
                    </Link>
                </div>
            </aside>

            {/* Main Content */}
            <div className="flex-1 flex flex-col min-h-screen">
                <header className="h-20 border-b bg-white/80 backdrop-blur-md px-8 flex items-center justify-between sticky top-0 z-40">
                    <div className="flex items-center gap-4">
                        <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-100 font-bold px-3 py-1">Platform Administrator</Badge>
                    </div>
                    <div className="flex items-center gap-6">
                        <Link href="/" className="text-xs font-black uppercase tracking-widest text-slate-400 hover:text-blue-600 transition-colors">View Storefront</Link>
                        <div className="size-8 rounded-full bg-emerald-500/10 flex items-center justify-center">
                            <div className="size-2 rounded-full bg-emerald-500 animate-pulse" />
                        </div>
                    </div>
                </header>

                <main className="flex-1 p-10 overflow-y-auto">
                    {children}
                </main>
            </div>
            <Toaster richColors position="top-right" />
        </div>
    );
}

