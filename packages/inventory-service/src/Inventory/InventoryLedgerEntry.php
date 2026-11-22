<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final readonly class InventoryLedgerEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $entryType,
        public int $itemId,
        public int $locationId,
        public int $lotId,
        public string $quantity,
        public string $reason,
        public array $context = [],
    ) {
        $errors = Data::create(['entry_type' => $entryType])->validate([
            'entry_type' => 'required|in:receipt,issue,adjustment,transfer_in,transfer_out,initial,reversal',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }

    /**
     * @return array{entry_type: string, item_id: int, location_id: int, lot_id: int, quantity: string, reason: string, source: string, created_at: string}
     */
    public function asInsertRow(): array
    {
        return [
            'entry_type' => $this->entryType,
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'lot_id' => $this->lotId,
            'quantity' => InventoryQuantity::signedFor($this->entryType, $this->quantity)->toString(),
            'reason' => $this->reason,
            'source' => $this->context['source'] ?? 'inventory',
            'created_at' => $this->context['created_at'] ?? gmdate('Y-m-d H:i:s'),
        ];
    }
}
