<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Booking;
use App\Models\Tenant\Ticket;
use App\Models\TenantProvider;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Tenant/Dashboard', [
            'stats' => [
                'todaysBookings' => Booking::whereDate('created_at', today())->count(),
                'issuedTickets' => Ticket::where('status', 'issued')->count(),
                'activeAgents' => User::where('is_active', true)->count(),
                'activeProviders' => TenantProvider::where('is_active', true)->count(),
            ],
            'recentBookings' => Booking::query()
                ->with(['customer:id,first_name,last_name,email', 'provider:id,airline_name'])
                ->latest()
                ->limit(5)
                ->get(),
            'providerStatus' => TenantProvider::query()
                ->latest()
                ->get(['id', 'airline_name', 'account_name', 'is_active', 'last_tested_at', 'last_test_status', 'last_test_message']),
        ]);
    }
}
