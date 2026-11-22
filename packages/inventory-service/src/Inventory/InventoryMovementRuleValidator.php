<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final class InventoryMovementRuleValidator
{
    public function assertMovementAllowed(InventoryMovement $movement): void
    {
        $this->validate([
            'item_id' => $movement->itemId,
            'location_id' => $movement->locationId,
            'lot_id' => $movement->lotId,
            'movement_type' => $movement->movementType,
            'quantity' => $movement->quantity,
        ], [
            'item_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
            'lot_id' => 'required|integer|min:1',
            'movement_type' => 'required|in:receipt,issue,adjustment,transfer_in,transfer_out,initial,reversal',
            'quantity' => 'required|numeric|positive',
        ]);
        $item = $this->findTrackableItem($movement->itemId);

        if (!((bool) ($item['track_inventory'] ?? true))) {
            throw new UnprocessableEntityException([
                'item_id' => [sprintf('L’article [%d] est configuré sans suivi de stock.', $movement->itemId)],
            ]);
        }

        $this->assertLocationExists($movement->locationId);

        if ((string) ($item['tracking_mode'] ?? 'none') === 'lot') {
            $this->assertLotBelongsToItem((int) $movement->lotId, $movement->itemId);
        }

        if ((string) ($item['tracking_mode'] ?? 'none') === 'serial') {
            $this->assertSerialBelongsToItem(
                (int) ($movement->context['serial_id'] ?? 0),
                $movement->itemId,
                $movement->locationId,
            );
        }

        $delta = InventoryQuantity::signedFor($movement->movementType, $movement->quantity);
        $this->assertAvailableOrThresholds($movement, $item, $delta);
    }

    public function assertReservationAllowed(int $itemId, int $locationId, int $lotId, string $quantity): void
    {
        $this->validate([
            'item_id' => $itemId,
            'location_id' => $locationId,
            'lot_id' => $lotId,
            'quantity' => $quantity,
        ], [
            'item_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
            'lot_id' => 'required|integer|min:1',
            'quantity' => 'required|numeric|positive',
        ]);
        $item = $this->findTrackableItem($itemId);

        if (!((bool) ($item['track_inventory'] ?? true))) {
            throw new UnprocessableEntityException([
                'item_id' => [sprintf('L’article [%d] est configuré sans suivi de stock.', $itemId)],
            ]);
        }

        $this->assertLocationExists($locationId);
        $this->assertLotBelongsToItem($lotId, $itemId);

        $balance = InventoryRecord::table('inventory_stock_balances')
            ->where('item_id', '=', $itemId)
            ->where('location_id', '=', $locationId)
            ->where('lot_id', '=', $lotId)
            ->selectRaw('COALESCE(physical_qty, 0) AS physical_qty, COALESCE(reserved_qty, 0) AS reserved_qty')
            ->first();
        $balance = $balance?->toArray();

        $currentQty = new InventoryQuantity((string) ($balance['physical_qty'] ?? '0'));
        $reservedQty = new InventoryQuantity((string) ($balance['reserved_qty'] ?? '0'));
        $available = $currentQty->subtract($reservedQty);
        $required = new InventoryQuantity($quantity);

        if (InventoryQuantity::compare($available->toString(), $required->toString()) < 0) {
            throw new UnprocessableEntityException([
                'quantity' => [sprintf(
                    'Stock disponible insuffisant pour l’article [%d] à l’emplacement [%d] : disponible [%s], demandé [%s].',
                    $itemId,
                    $locationId,
                    $available->toString(),
                    $required->toString()
                )],
            ]);
        }
    }

    public function assertCountAllowed(int $itemId, int $locationId, int $lotId, string $snapshotQty): void
    {
        $this->validate([
            'item_id' => $itemId,
            'location_id' => $locationId,
            'lot_id' => $lotId,
            'snapshot_qty' => $snapshotQty,
        ], [
            'item_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
            'lot_id' => 'required|integer|min:1',
            'snapshot_qty' => 'required|numeric|min:0',
        ]);
        $item = $this->findTrackableItem($itemId);

        $this->assertLocationExists($locationId);
        $this->assertLotBelongsToItem($lotId, $itemId);

        $snapshot = new InventoryQuantity($snapshotQty);
        if ($snapshot->isNegative()) {
            throw new UnprocessableEntityException(['snapshot_qty' => ['La quantité de référence ne peut pas être négative.']]);
        }

        $itemMin = $item['min_qty'] !== null ? new InventoryQuantity((string) $item['min_qty']) : null;
        $itemMax = $item['max_qty'] !== null ? new InventoryQuantity((string) $item['max_qty']) : null;

        if ($itemMin !== null && InventoryQuantity::compare($snapshot->toString(), $itemMin->toString()) < 0) {
            throw new UnprocessableEntityException([
                'snapshot_qty' => [sprintf('Le comptage de l’article [%d] est inférieur au minimum [%s].', $itemId, $itemMin->toString())],
            ]);
        }

        if ($itemMax !== null && InventoryQuantity::compare($snapshot->toString(), $itemMax->toString()) > 0) {
            throw new UnprocessableEntityException([
                'snapshot_qty' => [sprintf('Le comptage de l’article [%d] dépasse le maximum [%s].', $itemId, $itemMax->toString())],
            ]);
        }
    }

    private function assertLocationExists(int $locationId): void
    {
        if ($locationId <= 0) {
            throw new UnprocessableEntityException(['location_id' => ['Un emplacement valide est requis.']]);
        }

        $location = InventoryRecord::table('inventory_locations')
            ->where('id', '=', $locationId)
            ->where('is_active', '=', 1)
            ->where('is_available', '=', 1)
            ->first();

        if ($location === null) {
            throw new UnprocessableEntityException([
                'location_id' => [sprintf('L’emplacement [%d] est inactif ou introuvable.', $locationId)],
            ]);
        }
    }

    /**
     * Inventory owns stock-tracking settings; Catalog remains the source of
     * product identity and decides whether a variant can represent stock.
     *
     * @return array<string, mixed>
     */
    private function findTrackableItem(int $itemId): array
    {
        $item = InventoryRecord::table('inventory_items as i')
            ->join('catalog_variants as v', 'v.id', '=', 'i.variant_id')
            ->join('catalog_products as p', 'p.id', '=', 'v.product_id')
            ->where('i.id', '=', $itemId)
            ->select(
                'i.*',
                'v.status as variant_status',
                'p.status as product_status',
                'p.kind as product_kind',
            )
            ->first();
        $item = $item?->toArray();
        if ($item === null) {
            throw new UnprocessableEntityException(['item_id' => [sprintf('L’article [%d] est introuvable.', $itemId)]]);
        }
        if ((string) $item['product_kind'] !== 'goods') {
            throw new UnprocessableEntityException(['item_id' => ['Un produit de service ne peut pas être suivi comme stock.']]);
        }
        if ((string) $item['product_status'] !== 'active' || (string) $item['variant_status'] !== 'active') {
            throw new UnprocessableEntityException(['item_id' => ['Le produit ou sa déclinaison est archivé ou inactif.']]);
        }
        if ((string) $item['status'] !== 'active' || $item['archived_at'] !== null) {
            throw new UnprocessableEntityException(['item_id' => ['L’article d’inventaire est archivé ou inactif.']]);
        }

        return $item;
    }

    private function assertLotBelongsToItem(int $lotId, int $itemId): void
    {
        if ($lotId <= 0) {
            throw new UnprocessableEntityException(['lot_id' => ['Un lot valide est requis pour ce mouvement.']]);
        }

        $lot = InventoryRecord::table('inventory_item_lots')
            ->where('id', '=', $lotId)
            ->where('item_id', '=', $itemId)
            ->first();

        if ($lot === null) {
            throw new UnprocessableEntityException(['lot_id' => [sprintf('Le lot [%d] n’appartient pas à l’article [%d].', $lotId, $itemId)]]);
        }
    }

    private function assertSerialBelongsToItem(int $serialId, int $itemId, int $locationId): void
    {
        if ($serialId <= 0) {
            throw new UnprocessableEntityException(['serial_id' => ['Un numéro de série valide est requis.']]);
        }

        $serial = InventoryRecord::table('inventory_serial_numbers')
            ->where('id', '=', $serialId)
            ->where('item_id', '=', $itemId)
            ->select('id', 'status', 'current_location_id')
            ->first();
        $serial = $serial?->toArray();

        if ($serial === null) {
            throw new UnprocessableEntityException(['serial_id' => [sprintf('Le numéro de série [%d] n’appartient pas à l’article [%d].', $serialId, $itemId)]]);
        }

        if ((string) ($serial['status'] ?? 'in_stock') === 'reserved' && (int) ($serial['current_location_id'] ?? 0) !== $locationId) {
            throw new UnprocessableEntityException(['serial_id' => [sprintf('Le numéro de série [%d] est réservé et ne peut pas être déplacé.', $serialId)]]);
        }
    }

    private function assertAvailableOrThresholds(InventoryMovement $movement, array $item, InventoryQuantity $delta): void
    {
        $balance = InventoryRecord::table('inventory_stock_balances')
            ->where('item_id', '=', $movement->itemId)
            ->where('location_id', '=', $movement->locationId)
            ->where('lot_id', '=', $movement->lotId)
            ->selectRaw('COALESCE(physical_qty, 0) AS physical_qty, COALESCE(reserved_qty, 0) AS reserved_qty')
            ->lockForUpdate()
            ->first();
        $balance = $balance?->toArray();

        $currentQty = new InventoryQuantity((string) ($balance['physical_qty'] ?? '0'));
        $reservedQty = new InventoryQuantity((string) ($balance['reserved_qty'] ?? '0'));
        $available = $currentQty->subtract($reservedQty);
        $availableForMovement = !empty($movement->context['reservation_consumption'])
            ? $currentQty
            : $available;

        if (($movement->movementType === 'issue' || $movement->movementType === 'transfer_out' || $movement->movementType === 'reversal') && InventoryQuantity::compare($availableForMovement->toString(), $delta->negate()->toString()) < 0) {
            throw new UnprocessableEntityException([
                'quantity' => [sprintf(
                    'Stock disponible insuffisant pour l’article [%d] : disponible [%s], mouvement [%s].',
                    $movement->itemId,
                    $availableForMovement->toString(),
                    $movement->quantity
                )],
            ]);
        }

        $allowNegative = (bool) ($item['allow_negative_stock'] ?? false);
        if (!$allowNegative && $movement->movementType !== 'receipt' && $movement->movementType !== 'transfer_in' && $movement->movementType !== 'initial') {
            $projected = $currentQty->add($delta);
            if ($projected->isNegative()) {
                throw new UnprocessableEntityException([
                    'quantity' => [sprintf('L’article [%d] n’autorise pas un stock négatif après un mouvement [%s].', $movement->itemId, $movement->movementType)],
                ]);
            }
        }

        $minQty = $item['min_qty'] !== null ? new InventoryQuantity((string) $item['min_qty']) : null;
        $maxQty = $item['max_qty'] !== null ? new InventoryQuantity((string) $item['max_qty']) : null;
        $projected = $currentQty->add($delta);

        if ($minQty !== null && InventoryQuantity::compare($projected->toString(), $minQty->toString()) < 0) {
            throw new UnprocessableEntityException([
                'quantity' => [sprintf('Le stock de l’article [%d] passerait sous le minimum [%s].', $movement->itemId, $minQty->toString())],
            ]);
        }

        if ($maxQty !== null && InventoryQuantity::compare($projected->toString(), $maxQty->toString()) > 0) {
            throw new UnprocessableEntityException([
                'quantity' => [sprintf('Le stock de l’article [%d] dépasserait le maximum [%s].', $movement->itemId, $maxQty->toString())],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $rules
     */
    private function validate(array $values, array $rules): void
    {
        $errors = Data::create($values)->validate($rules);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }
}
