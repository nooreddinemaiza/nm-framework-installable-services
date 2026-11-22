<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final readonly class InventoryOutboxMessage
{
    public string $eventId;
    public string $availableAt;

    public function __construct(
        public string $eventType,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public int $schemaVersion = 1,
        public string $status = 'pending',
        ?string $availableAt = null,
        ?string $eventId = null,
    ) {
        $errors = Data::create([
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'schema_version' => $schemaVersion,
            'status' => $status,
        ])->validate([
            'event_type' => 'required|string',
            'aggregate_type' => 'required|string',
            'aggregate_id' => 'required|string',
            'schema_version' => 'required|integer|min:1',
            'status' => 'required|string',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        if (trim($eventType) === '') {
            throw new UnprocessableEntityException(['event_type' => ['Le type d’événement est obligatoire.']]);
        }

        if (trim($aggregateType) === '') {
            throw new UnprocessableEntityException(['aggregate_type' => ['Le type d’agrégat est obligatoire.']]);
        }

        if (trim($aggregateId) === '') {
            throw new UnprocessableEntityException(['aggregate_id' => ['L’identifiant d’agrégat est obligatoire.']]);
        }

        $this->eventId = $eventId ?? self::generateEventId();
        $this->availableAt = $availableAt ?? gmdate('Y-m-d H:i:s');
    }

    public static function fromMovement(
        InventoryMovement $movement,
        int|string $operationId,
        ?string $eventId = null,
    ): self {
        $signedQuantity = InventoryQuantity::signedFor($movement->movementType, $movement->quantity);

        return new self(
            eventType: 'inventory.stock.movement.applied',
            aggregateType: 'inventory_stock_operation',
            aggregateId: (string) $operationId,
            payload: [
                'movement_type' => $movement->movementType,
                'item_id' => $movement->itemId,
                'location_id' => $movement->locationId,
                'lot_id' => $movement->lotId,
                'quantity' => $signedQuantity->toString(),
                'context' => $movement->context,
            ],
            eventId: $eventId,
        );
    }

    /**
     * @return array{event_id: string, event_type: string, aggregate_type: string, aggregate_id: string, schema_version: int, payload: string, status: string, available_at: string}
     */
    public function asInsertRow(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'schema_version' => $this->schemaVersion,
            'payload' => json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'status' => $this->status,
            'available_at' => $this->availableAt,
        ];
    }

    private static function generateEventId(): string
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }

        return md5((string) microtime(true) . uniqid('', true));
    }
}
