<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final class InventoryMovementService
{
    private const array VALID_MOVEMENT_TYPES = [
        'receipt',
        'issue',
        'adjustment',
        'transfer_in',
        'transfer_out',
        'initial',
        'reversal',
    ];

    public function __construct(
        private readonly InventoryTransactionBoundary $transactionBoundary,
        private readonly InventoryBalanceService $balanceService,
        private readonly InventoryMovementRuleValidator $validator,
    ) {
    }

    /**
     * Creates a stock operation and persists its first ledger/operation records.
     * This is intentionally an atomic boundary for the Inventory core; business
     * rules remain in higher-level services.
     *
     * @return array{operation_id: int|string, quantity: string, event_id: string}
     */
    public function apply(InventoryMovement $movement): array
    {
        $this->assertValidMovementType($movement->movementType);

        $signedQuantity = InventoryQuantity::signedFor($movement->movementType, $movement->quantity);

        $this->assertRequiredContext($movement);

        return $this->transactionBoundary->transaction(function () use ($movement, $signedQuantity): array {
            $this->transactionBoundary->ensureTransaction();
            $this->validator->assertMovementAllowed($movement);

            $now = gmdate('Y-m-d H:i:s');

            $operationId = InventoryRecord::table('inventory_stock_operations')->insertGetId([
                'operation_type' => $movement->movementType,
                'status' => 'posted',
                'reference' => $movement->context['reference'] ?? null,
                'reason' => $movement->context['reason'] ?? null,
                'operational_at' => $now,
                'validated_at' => $now,
                'created_by' => $movement->context['created_by'] ?? null,
                'validated_by' => $movement->context['validated_by'] ?? null,
                'source_module' => 'inventory',
                'idempotency_key' => $movement->context['idempotency_key'] ?? 'inventory:' . uniqid('', true),
                'external_module' => $movement->context['external_module'] ?? null,
                'external_document_type' => $movement->context['external_document_type'] ?? null,
                'external_document_id' => $movement->context['external_document_id'] ?? null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $lineQuantity = $signedQuantity->toString();
            $lineId = InventoryRecord::table('inventory_operation_lines')->insertGetId([
                'operation_id' => $operationId,
                'item_id' => $movement->itemId,
                'unit_id' => $movement->context['unit_id'] ?? 0,
                'quantity_input' => $lineQuantity,
                'conversion_factor' => $movement->context['conversion_factor'] ?? 1,
                'quantity_base' => $lineQuantity,
                'source_location_id' => $movement->locationId,
                'destination_location_id' => $movement->context['destination_location_id'] ?? null,
                'lot_id' => $movement->lotId,
                'serial_id' => $movement->context['serial_id'] ?? null,
                'reason' => $movement->context['reason'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $groupId = $movement->context['group_id'] ?? self::newUuid();
            $groupData = Data::create(['group_id' => $groupId]);
            $errors = $groupData->validate(['group_id' => 'required|uuid']);
            if ($errors !== []) {
                throw new UnprocessableEntityException($errors);
            }

            $ledgerEntryId = InventoryRecord::table('inventory_stock_ledger_entries')->insertGetId([
                'operation_id' => $operationId,
                'operation_line_id' => $lineId,
                'item_id' => $movement->itemId,
                'location_id' => $movement->locationId,
                'lot_id' => $movement->lotId,
                'serial_id' => $movement->context['serial_id'] ?? null,
                'movement_type' => $movement->movementType,
                'quantity' => $lineQuantity,
                'operational_at' => $now,
                'actor_user_id' => $movement->context['actor_user_id'] ?? null,
                'reason' => $movement->context['reason'] ?? null,
                'group_id' => $groupId,
                'idempotency_key' => $movement->context['idempotency_key'] ?? 'inventory:' . uniqid('', true),
                'created_at' => $now,
            ]);

            $this->balanceService->applyDelta(
                $movement->itemId,
                $movement->locationId,
                $movement->lotId,
                $lineQuantity,
                $ledgerEntryId,
            );

            $event = InventoryOutboxMessage::fromMovement($movement, $operationId);
            $this->transactionBoundary->persistOutbox($event);

            return [
                'operation_id' => $operationId,
                'quantity' => $signedQuantity->toString(),
                'event_id' => $event->eventId,
            ];
        });
    }

    private function assertValidMovementType(string $movementType): void
    {
        $input = Data::create(['movement_type' => $movementType]);
        $errors = $input->validate([
            'movement_type' => 'required|in:' . implode(',', self::VALID_MOVEMENT_TYPES),
        ]);
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

    /**
     * @param array<string, mixed> $context
     */
    private function assertRequiredContext(InventoryMovement $movement): void
    {
        $input = Data::create([
            'unit_id' => $movement->context['unit_id'] ?? null,
            'item_id' => $movement->itemId,
            'location_id' => $movement->locationId,
            'lot_id' => $movement->lotId,
        ]);
        $errors = $input->validate([
            'unit_id' => 'required|integer|min:1',
            'item_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
            'lot_id' => 'required|integer|min:1',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }
}
