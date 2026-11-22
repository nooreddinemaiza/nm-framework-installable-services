<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Closure;
use NM\Services\InventoryService\Models\InventoryRecord;
use Throwable;

final class InventoryOutboxDrainer
{
    /**
     * @param Closure(string, array<string, mixed>, array<string, mixed>): void $dispatcher
     */
    public function __construct(
        private readonly Closure $dispatcher,
    ) {
    }

    /**
     * @return int number of drained messages
     */
    public function drain(int $limit = 50): int
    {
        $rows = InventoryRecord::table('inventory_stock_outbox')
            ->where('status', '=', 'pending')
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        if ($rows === []) {
            return 0;
        }

        $drained = 0;

        foreach ($rows as $record) {
            $row = $record->toArray();
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

            try {
                ($this->dispatcher)($row['event_type'], $payload, $row);

                InventoryRecord::table('inventory_stock_outbox')
                    ->where('event_id', '=', $row['event_id'])
                    ->increment('attempts', 1, [
                        'status' => 'dispatched',
                        'dispatched_at' => date('Y-m-d H:i:s'),
                        'last_error' => null,
                    ]);

                ++$drained;
            } catch (Throwable $e) {
                InventoryRecord::table('inventory_stock_outbox')
                    ->where('event_id', '=', $row['event_id'])
                    ->increment('attempts', 1, [
                        'status' => 'pending',
                        'last_error' => $e->getMessage(),
                        'available_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        }

        return $drained;
    }
}
