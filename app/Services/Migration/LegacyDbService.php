<?php

namespace App\Services\Migration;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LegacyDbService
{
    private Connection $conn;

    public function __construct()
    {
        $this->conn = DB::connection('legacy');
    }

    public function testConnection(): bool
    {
        try {
            $this->conn->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getAgents(): Collection
    {
        return $this->conn->table('agents')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();
    }

    public function getAgent(int $agentId): ?object
    {
        return $this->conn->table('agents')
            ->where('id', $agentId)
            ->whereNull('deleted_at')
            ->first();
    }

    public function getAgentUsers(int $agentId): Collection
    {
        return $this->conn->table('users')
            ->where('related_to_type', 'App\\Models\\Agent')
            ->where('related_to_id', $agentId)
            ->get();
    }

    public function getAgentOrders(int $agentId): Collection
    {
        return $this->conn->table('orders')
            ->where('owner_type', 'App\\Models\\Agent')
            ->where('owner_id', $agentId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function getOrderItems(string $orderId): Collection
    {
        return $this->conn->table('order_items')
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function getOrderItemSales(int $orderItemId): Collection
    {
        return $this->conn->table('order_item_sales')
            ->where('order_item_id', $orderItemId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function getContacts(int $agentId): Collection
    {
        return $this->conn->table('contacts')
            ->where('owner_type', 'App\\Models\\Agent')
            ->where('owner_id', $agentId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function countAgentOrders(int $agentId): int
    {
        return $this->conn->table('orders')
            ->where('owner_type', 'App\\Models\\Agent')
            ->where('owner_id', $agentId)
            ->whereNull('deleted_at')
            ->count();
    }

    public function countAgentContacts(int $agentId): int
    {
        return $this->conn->table('contacts')
            ->where('owner_type', 'App\\Models\\Agent')
            ->where('owner_id', $agentId)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Return agent type label from agent_types table if available.
     */
    public function getAgentTypeName(int $agentTypeId): string
    {
        $type = $this->conn->table('agent_types')
            ->where('id', $agentTypeId)
            ->first();

        return $type?->name ?? 'direct';
    }
}
