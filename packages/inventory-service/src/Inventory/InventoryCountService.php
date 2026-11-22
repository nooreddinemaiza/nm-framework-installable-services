<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Database\Connection;
use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final class InventoryCountService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InventoryBalanceService $balanceService,
        private readonly InventoryTransactionBoundary $transactionBoundary,
        private readonly InventoryMovementRuleValidator $validator,
    ) {
    }

    public function startCount(int $itemId, int $locationId, int $lotId, string $snapshotQty, ?int $countedBy = null): int
    {
        $this->assertPositiveInt($itemId, 'itemId');
        $this->assertPositiveInt($locationId, 'locationId');
        $this->assertPositiveInt($lotId, 'lotId');

        $this->validateQuantity($snapshotQty, 'snapshot_qty');
        $snapshot = new InventoryQuantity($snapshotQty);
        if ($snapshot->isNegative()) {
            throw new UnprocessableEntityException(['snapshot_qty' => ['La quantité comptée ne peut pas être négative.']]);
        }

        $this->validator->assertCountAllowed($itemId, $locationId, $lotId, $snapshot->toString());

        return $this->transactionBoundary->transaction(function () use ($itemId, $locationId, $lotId, $snapshot, $countedBy): int {
            $now = gmdate('Y-m-d H:i:s');
            $idempotencyKey = 'inventory-count:' . bin2hex(random_bytes(16));
            $operationId = InventoryRecord::table('inventory_stock_operations')->insertGetId([
                'operation_type' => 'count',
                'status' => 'draft',
                'reference' => null,
                'reason' => 'Physical inventory count',
                'operational_at' => $now,
                'validated_at' => null,
                'created_by' => $countedBy,
                'validated_by' => null,
                'source_module' => 'inventory',
                'idempotency_key' => $idempotencyKey,
                'external_module' => null,
                'external_document_type' => null,
                'external_document_id' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $countOperationId = InventoryRecord::table('inventory_stock_counts')->insertGetId([
                'operation_id' => $operationId,
                'concurrent_movement_mode' => 'snapshot_adjust',
                'opened_at' => $now,
                'submitted_at' => null,
                'reviewed_at' => null,
            ]);
            $countLineId = InventoryRecord::table('inventory_stock_count_lines')->insertGetId([
                'count_operation_id' => $countOperationId,
                'item_id' => $itemId,
                'location_id' => $locationId,
                'lot_id' => $lotId,
                'snapshot_qty' => $snapshot->toString(),
                'final_counted_qty' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            InventoryRecord::table('inventory_count_observations')->insert([
                'count_line_id' => $countLineId,
                'count_number' => 1,
                'counted_qty' => $snapshot->toString(),
                'counted_by' => $countedBy,
                'counted_at' => $now,
            ]);

            return $countOperationId;
        });
    }

    public function reconcileCount(int $countOperationId, int $itemId, int $locationId, int $lotId, string $finalCountedQty, ?int $countedBy = null): void
    {
        $this->assertPositiveInt($countOperationId, 'countOperationId');
        $this->assertPositiveInt($itemId, 'itemId');
        $this->assertPositiveInt($locationId, 'locationId');
        $this->assertPositiveInt($lotId, 'lotId');

        $this->validateQuantity($finalCountedQty, 'final_counted_qty');
        $finalQty = new InventoryQuantity($finalCountedQty);
        if ($finalQty->isNegative()) {
            throw new UnprocessableEntityException(['final_counted_qty' => ['La quantité finale ne peut pas être négative.']]);
        }
        $this->validator->assertCountAllowed($itemId, $locationId, $lotId, $finalQty->toString());

        $this->transactionBoundary->transaction(function () use ($countOperationId, $itemId, $locationId, $lotId, $finalQty, $countedBy): void {
            $line = InventoryRecord::table('inventory_stock_count_lines as line')
                ->join('inventory_stock_counts as stock_count', 'stock_count.id', '=', 'line.count_operation_id')
                ->join('inventory_stock_operations as operation', 'operation.id', '=', 'stock_count.operation_id')
                ->where('line.count_operation_id', '=', $countOperationId)
                ->where('line.item_id', '=', $itemId)
                ->where('line.location_id', '=', $locationId)
                ->where('line.lot_id', '=', $lotId)
                ->where('operation.status', '=', 'draft')
                ->select('line.id', 'line.snapshot_qty', 'line.final_counted_qty', 'stock_count.operation_id')
                ->first();
            $line = $line?->toArray();

            if ($line === null) {
                throw new UnprocessableEntityException(['count_operation_id' => ['Aucune ligne de comptage ouverte ne correspond à ces critères.']]);
            }
            if ($line['final_counted_qty'] !== null) {
                throw new UnprocessableEntityException(['final_counted_qty' => ['Cette ligne de comptage a déjà été rapprochée.']]);
            }

            $delta = $finalQty->subtract(new InventoryQuantity((string) $line['snapshot_qty']));
            $now = gmdate('Y-m-d H:i:s');

            InventoryRecord::table('inventory_stock_count_lines')->where('id', '=', $line['id'])->update([
                'final_counted_qty' => $finalQty->toString(),
                'updated_at' => $now,
            ]);
            InventoryRecord::table('inventory_count_observations')->insert([
                'count_line_id' => $line['id'],
                'count_number' => 2,
                'counted_qty' => $finalQty->toString(),
                'counted_by' => $countedBy,
                'counted_at' => $now,
            ]);

            if (!$delta->isZero()) {
                $item = InventoryRecord::table('inventory_items')
                    ->where('id', '=', $itemId)
                    ->select('reference_unit_id')
                    ->first();
                if ($item === null) {
                    throw new UnprocessableEntityException([
                        'item_id' => [sprintf('Le produit d’inventaire [%d] est introuvable.', $itemId)],
                    ]);
                }

                $operationLineId = InventoryRecord::table('inventory_operation_lines')->insertGetId([
                    'operation_id' => $line['operation_id'],
                    'item_id' => $itemId,
                    'unit_id' => $item->reference_unit_id,
                    'quantity_input' => $delta->toString(),
                    'conversion_factor' => '1',
                    'quantity_base' => $delta->toString(),
                    'source_location_id' => $locationId,
                    'destination_location_id' => null,
                    'lot_id' => $lotId,
                    'serial_id' => null,
                    'reason' => 'Physical count reconciliation',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $ledgerEntryId = InventoryRecord::table('inventory_stock_ledger_entries')->insertGetId([
                    'operation_id' => $line['operation_id'],
                    'operation_line_id' => $operationLineId,
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'lot_id' => $lotId,
                    'serial_id' => null,
                    'movement_type' => 'adjustment',
                    'quantity' => $delta->toString(),
                    'operational_at' => $now,
                    'actor_user_id' => $countedBy,
                    'reason' => 'Physical count reconciliation',
                    'group_id' => self::newUuid(),
                    'idempotency_key' => 'count-line:' . $line['id'],
                    'created_at' => $now,
                ]);

                $this->balanceService->applyDelta(
                    $itemId,
                    $locationId,
                    $lotId,
                    $delta->toString(),
                    $ledgerEntryId,
                );
            }

            InventoryRecord::table('inventory_stock_operations')
                ->where('id', '=', $line['operation_id'])
                ->where('status', '=', 'draft')
                ->increment('version', 1, [
                    'status' => 'posted',
                    'validated_at' => $now,
                    'validated_by' => $countedBy,
                    'updated_at' => $now,
                ]);
            InventoryRecord::table('inventory_stock_counts')->where('id', '=', $countOperationId)->update([
                'submitted_at' => $now,
                'reviewed_at' => $now,
            ]);
        });
    }

    private function assertPositiveInt(int $value, string $label): void
    {
        $input = Data::create([$label => $value]);
        $errors = $input->validate([$label => 'required|integer|min:1']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }

    private function validateQuantity(string $quantity, string $field): void
    {
        $input = Data::create([$field => $quantity]);
        $errors = $input->validate([$field => 'required|numeric|min:0']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }

    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
