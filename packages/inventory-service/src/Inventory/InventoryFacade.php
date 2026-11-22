<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use NM\Services\InventoryService\Inventory\Discount\Services\DiscountService;
use Core\Exceptions\InternalServerException;

final class InventoryFacade
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly InventoryStockService $stockService,
        private readonly InventoryCountService $countService,
        private readonly InventoryTransactionBoundary $transactionBoundary,
        private readonly ?DiscountService $discountService = null,
    ) {
    }

    public function applyMovement(InventoryMovement $movement): array
    {
        return $this->movementService->apply($movement);
    }

    public function reserve(
        int $itemId,
        int $locationId,
        int $lotId,
        string $quantity,
        string $reservationKey,
        ?int $createdBy = null,
    ): int {
        return $this->stockService->reserve(
            itemId: $itemId,
            locationId: $locationId,
            lotId: $lotId,
            quantity: $quantity,
            reservationKey: $reservationKey,
            createdBy: $createdBy,
        );
    }

    public function releaseReservation(int $reservationId, string $quantity): void
    {
        $this->stockService->release($reservationId, $quantity);
    }

    public function consumeReservation(int $reservationId, string $quantity, ?int $consumedBy = null): void
    {
        $this->stockService->consume($reservationId, $quantity, $consumedBy);
    }

    public function startCount(int $itemId, int $locationId, int $lotId, string $snapshotQty, ?int $countedBy = null): int
    {
        return $this->countService->startCount($itemId, $locationId, $lotId, $snapshotQty, $countedBy);
    }

    public function reconcileCount(int $countOperationId, int $itemId, int $locationId, int $lotId, string $finalCountedQty, ?int $countedBy = null): void
    {
        $this->countService->reconcileCount($countOperationId, $itemId, $locationId, $lotId, $finalCountedQty, $countedBy);
    }

    public function runInTransaction(callable $callback): mixed
    {
        return $this->transactionBoundary->transaction($callback);
    }

    public function discounts(): DiscountService
    {
        return $this->discountService
            ?? throw new InternalServerException('Le service de réductions Inventory n’est pas enregistré.');
    }
}
