<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final class InventoryStockService
{
    public function __construct(
        private readonly InventoryBalanceService $balanceService,
        private readonly InventoryReservationService $reservationService,
        private readonly InventoryMovementService $movementService,
        private readonly InventoryTransactionBoundary $transactionBoundary,
        private readonly InventoryMovementRuleValidator $validator,
    ) {
    }

    public function reserve(
        int $itemId,
        int $locationId,
        int $lotId,
        string $quantity,
        string $reservationKey,
        ?int $createdBy = null,
    ): int {
        $this->validatePositiveQuantity($quantity);
        $normalized = new InventoryQuantity($quantity);
        if ($normalized->isNegative() || $normalized->isZero()) {
            throw new UnprocessableEntityException(['quantity' => ['La quantité réservée doit être supérieure à zéro.']]);
        }

        $this->validator->assertReservationAllowed($itemId, $locationId, $lotId, $normalized->toString());

        return $this->transactionBoundary->transaction(function () use ($itemId, $locationId, $lotId, $normalized, $reservationKey, $createdBy): int {
            $location = InventoryRecord::table('inventory_locations')
                ->where('id', '=', $locationId)
                ->where('is_active', '=', 1)
                ->select('warehouse_id')
                ->first();
            if ($location === null) {
                throw new UnprocessableEntityException([
                    'location_id' => [sprintf('L’emplacement [%d] est introuvable.', $locationId)],
                ]);
            }

            $balance = $this->balanceService->getBalance($itemId, $locationId, $lotId);
            if (!$balance->availableForReservation($normalized->toString())) {
                throw new UnprocessableEntityException([
                    'quantity' => [sprintf(
                        'Stock disponible insuffisant pour réserver [%s] pour l’article [%d] à l’emplacement [%d].',
                        $normalized->toString(),
                        $itemId,
                        $locationId
                    )],
                ]);
            }

            $reservationId = $this->reservationService->createReservation(
                itemId: $itemId,
                warehouseId: (int) $location->warehouse_id,
                quantity: $normalized->toString(),
                reservationKey: $reservationKey,
                createdBy: $createdBy,
            );

            $balanceRow = InventoryRecord::table('inventory_stock_balances')
                ->where('item_id', '=', $itemId)
                ->where('location_id', '=', $locationId)
                ->where('lot_id', '=', $lotId)
                ->lockForUpdate()
                ->first();

            if ($balanceRow === null) {
                throw new UnprocessableEntityException([
                    'balance' => [sprintf('Solde introuvable pour l’article [%d], emplacement [%d] et lot [%d].', $itemId, $locationId, $lotId)],
                ]);
            }

            $now = date('Y-m-d H:i:s');
            InventoryRecord::table('inventory_stock_balances')->where('id', '=', $balanceRow->id)->update([
                'reserved_qty' => (new InventoryQuantity((string) $balanceRow->reserved_qty))
                    ->add($normalized)
                    ->toString(),
                'updated_at' => $now,
            ]);
            InventoryRecord::table('inventory_reservation_allocations')->insert([
                'reservation_id' => $reservationId,
                'balance_id' => $balanceRow->id,
                'quantity_reserved' => $normalized->toString(),
                'quantity_consumed' => '0',
                'quantity_released' => '0',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $reservationId;
        });
    }

    public function release(int $reservationId, string $quantity): void
    {
        $this->validatePositiveQuantity($quantity);
        $normalized = new InventoryQuantity($quantity);
        if ($normalized->isNegative() || $normalized->isZero()) {
            throw new UnprocessableEntityException(['quantity' => ['La quantité libérée doit être supérieure à zéro.']]);
        }

        $this->finishReservation($reservationId, $normalized, 'release');
    }

    public function consume(int $reservationId, string $quantity, ?int $consumedBy = null): void
    {
        $this->validatePositiveQuantity($quantity);
        $normalized = new InventoryQuantity($quantity);
        if ($normalized->isNegative() || $normalized->isZero()) {
            throw new UnprocessableEntityException(['quantity' => ['La quantité consommée doit être supérieure à zéro.']]);
        }

        $this->finishReservation($reservationId, $normalized, 'consume', $consumedBy);
    }

    private function finishReservation(
        int $reservationId,
        InventoryQuantity $quantity,
        string $action,
        ?int $actorId = null,
    ): void
    {
        if ($reservationId <= 0) {
            throw new UnprocessableEntityException(['reservation_id' => ['L’identifiant de réservation doit être supérieur à zéro.']]);
        }

        $this->transactionBoundary->transaction(function () use ($reservationId, $quantity, $action, $actorId): void {
            $reservation = InventoryRecord::table('inventory_stock_reservations')
                ->where('id', '=', $reservationId)
                ->lockForUpdate()
                ->first();
            $reservation = $reservation?->toArray();
            if ($reservation === null || (string) $reservation['status'] !== 'active') {
                throw new UnprocessableEntityException([
                    'reservation_id' => [sprintf('La réservation active [%d] est introuvable.', $reservationId)],
                ]);
            }

            $remaining = (new InventoryQuantity((string) $reservation['quantity_reserved']))
                ->subtract(new InventoryQuantity((string) $reservation['quantity_consumed']))
                ->subtract(new InventoryQuantity((string) $reservation['quantity_released']));
            if (InventoryQuantity::compare($quantity->toString(), $remaining->toString()) > 0) {
                throw new UnprocessableEntityException([
                    'quantity' => [sprintf('La quantité [%s] dépasse le solde de réservation [%s].', $quantity->toString(), $remaining->toString())],
                ]);
            }

            $allocations = InventoryRecord::table('inventory_reservation_allocations as allocation')
                ->join('inventory_stock_balances as balance', 'balance.id', '=', 'allocation.balance_id')
                ->where('allocation.reservation_id', '=', $reservationId)
                ->select(
                    'allocation.id',
                    'allocation.balance_id',
                    'allocation.quantity_reserved',
                    'allocation.quantity_consumed',
                    'allocation.quantity_released',
                    'balance.item_id',
                    'balance.location_id',
                    'balance.lot_id',
                    'balance.reserved_qty',
                )
                ->orderBy('allocation.id')
                ->lockForUpdate()
                ->get();
            $allocations = array_map(static fn(InventoryRecord $row): array => $row->toArray(), $allocations);
            $toApply = $quantity;
            $consumedTotal = new InventoryQuantity((string) $reservation['quantity_consumed']);
            $releasedTotal = new InventoryQuantity((string) $reservation['quantity_released']);

            foreach ($allocations as $allocation) {
                if ($toApply->isZero()) {
                    break;
                }

                $allocationRemaining = (new InventoryQuantity((string) $allocation['quantity_reserved']))
                    ->subtract(new InventoryQuantity((string) $allocation['quantity_consumed']))
                    ->subtract(new InventoryQuantity((string) $allocation['quantity_released']));
                if (!$allocationRemaining->isPositive()) {
                    continue;
                }

                $part = InventoryQuantity::compare($allocationRemaining->toString(), $toApply->toString()) < 0
                    ? $allocationRemaining
                    : $toApply;

                if ($action === 'consume') {
                    $item = InventoryRecord::table('inventory_items')
                        ->where('id', '=', $allocation['item_id'])
                        ->select('reference_unit_id')
                        ->first();
                    if ($item === null) {
                        throw new UnprocessableEntityException([
                            'item_id' => [sprintf('Le produit d’inventaire [%d] est introuvable.', $allocation['item_id'])],
                        ]);
                    }

                    $this->movementService->apply(new InventoryMovement(
                        movementType: 'issue',
                        itemId: (int) $allocation['item_id'],
                        locationId: (int) $allocation['location_id'],
                        lotId: (int) $allocation['lot_id'],
                        quantity: $part->toString(),
                        context: [
                            'unit_id' => (int) $item->reference_unit_id,
                            'reason' => 'Reservation consumption',
                            'reservation_consumption' => true,
                            'created_by' => $actorId,
                            'validated_by' => $actorId,
                            'actor_user_id' => $actorId,
                            'idempotency_key' => 'reservation-consume:' . $reservationId . ':' . bin2hex(random_bytes(8)),
                        ],
                    ));

                    InventoryRecord::table('inventory_reservation_allocations')
                        ->where('id', '=', $allocation['id'])
                        ->update([
                            'quantity_consumed' => (new InventoryQuantity((string) $allocation['quantity_consumed']))
                                ->add($part)
                                ->toString(),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    $consumedTotal = $consumedTotal->add($part);
                } else {
                    $balanceReserved = new InventoryQuantity((string) $allocation['reserved_qty']);
                    if (InventoryQuantity::compare($balanceReserved->toString(), $part->toString()) < 0) {
                        throw new UnprocessableEntityException([
                            'quantity' => ['La quantité allouée dépasse la quantité réservée au solde.'],
                        ]);
                    }

                    InventoryRecord::table('inventory_reservation_allocations')
                        ->where('id', '=', $allocation['id'])
                        ->update([
                            'quantity_released' => (new InventoryQuantity((string) $allocation['quantity_released']))
                                ->add($part)
                                ->toString(),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    $releasedTotal = $releasedTotal->add($part);
                }

                $balanceReserved = new InventoryQuantity((string) $allocation['reserved_qty']);
                if (InventoryQuantity::compare($balanceReserved->toString(), $part->toString()) < 0) {
                    throw new UnprocessableEntityException([
                        'quantity' => ['La quantité allouée dépasse la quantité réservée au solde.'],
                    ]);
                }
                InventoryRecord::table('inventory_stock_balances')
                    ->where('id', '=', $allocation['balance_id'])
                    ->update([
                        'reserved_qty' => $balanceReserved->subtract($part)->toString(),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                $toApply = $toApply->subtract($part);
            }

            if (!$toApply->isZero()) {
                throw new UnprocessableEntityException([
                    'quantity' => ['Les allocations de réservation ne couvrent pas la quantité demandée.'],
                ]);
            }

            $isComplete = InventoryQuantity::compare(
                $consumedTotal->add($releasedTotal)->toString(),
                (string) $reservation['quantity_reserved']
            ) >= 0;
            $status = $isComplete
                ? ($consumedTotal->isPositive() ? 'consumed' : 'released')
                : 'active';
            InventoryRecord::table('inventory_stock_reservations')
                ->where('id', '=', $reservationId)
                ->update([
                    'quantity_consumed' => $consumedTotal->toString(),
                    'quantity_released' => $releasedTotal->toString(),
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        });
    }

    private function validatePositiveQuantity(string $quantity): void
    {
        $input = Data::create(['quantity' => $quantity]);
        $errors = $input->validate(['quantity' => 'required|numeric|positive']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }
}
