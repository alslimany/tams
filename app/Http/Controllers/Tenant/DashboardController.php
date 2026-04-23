<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Order;
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
                'todaysBookings' => Order::whereDate('created_at', today())->count(),
                'issuedTickets' => Ticket::where('status', 'issued')->count(),
                'activeAgents' => User::where('is_active', true)->count(),
                'activeProviders' => TenantProvider::where('is_active', true)->count(),
                'ticketValue' => (float) Order::whereDate('created_at', today())->sum('grand_total'),
            ],
            'recentBookings' => Order::query()
                ->with(['items:id,order_id,provider_reference', 'owner'])
                ->latest()
                ->limit(5)
                ->get()
                ->map(function (Order $order): array {
                    return [
                        'id' => $order->id,
                        'first_name' => data_get($order->contact, 'first_name', data_get($order->owner, 'name', '')),
                        'surname' => data_get($order->contact, 'last_name', ''),
                        'email' => data_get($order->contact, 'email', data_get($order->owner, 'email', '')),
                        'status' => $order->status,
                        'total_price' => (float) $order->grand_total,
                        'currency' => $order->currency,
                    ];
                })
                ->values(),
            'providerStatus' => TenantProvider::query()
                ->latest()
                ->get(['id', 'airline_name', 'account_name', 'is_active', 'last_tested_at', 'last_test_status', 'last_test_message']),
        ]);
    }
}
