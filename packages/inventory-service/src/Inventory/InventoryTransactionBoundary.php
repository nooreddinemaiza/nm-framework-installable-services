<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Closure;
use Core\Database\Connection;
use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\UnprocessableEntityException;

final class InventoryTransactionBoundary
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Executes a callback inside the framework-managed transaction boundary.
     * Nested scopes are promoted to savepoints automatically.
     */
    public function transaction(callable $callback): mixed
    {
        return $this->connection->transaction($callback);
    }

    public function afterCommit(Closure $callback): void
    {
        $this->connection->afterCommit($callback);
    }

    public function markRollbackOnly(): void
    {
        $this->connection->markRollbackOnly();
    }

    public function persistOutbox(InventoryOutboxMessage $message): void
    {
        $row = $message->asInsertRow();

        InventoryRecord::table('inventory_stock_outbox')->insert([
            'event_id' => $row['event_id'],
            'event_type' => $row['event_type'],
            'aggregate_type' => $row['aggregate_type'],
            'aggregate_id' => $row['aggregate_id'],
            'schema_version' => $row['schema_version'],
            'payload' => $row['payload'],
            'status' => $row['status'],
            'available_at' => $row['available_at'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function ensureTransaction(): void
    {
        if (!$this->connection->inTransaction()) {
            throw new UnprocessableEntityException([
                'transaction' => ['Les mouvements de stock doivent être exécutés dans une transaction active.'],
            ]);
        }
    }
}
