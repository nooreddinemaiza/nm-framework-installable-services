<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Database\Connection;
use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final class InventoryReservationService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InventoryBalanceService $balanceService,
    ) {
    }

    public function createReservation(int $itemId, int $warehouseId, string $quantity, string $reservationKey, ?int $createdBy = null): int
    {
        $this->assertTransactionOpen();

        $input = Data::create(['quantity' => $quantity]);
        $errors = $input->validate(['quantity' => 'required|numeric|positive']);
        if ($errors !== []) {
            throw new UnprocessableEntityException(
                $errors
            );
        }
        $reserved = new InventoryQuantity($quantity);
        if ($reserved->isNegative() || $reserved->isZero()) {
            throw new UnprocessableEntityException(['quantity' => ['La quantité réservée doit être supérieure à zéro.']]);
        }

        $now = date('Y-m-d H:i:s');

        return InventoryRecord::table('inventory_stock_reservations')->insertGetId([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity_reserved' => $reserved->toString(),
            'quantity_consumed' => '0',
            'quantity_released' => '0',
            'status' => 'active',
            'reservation_key' => $reservationKey,
            'expires_at' => null,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function allocateToBalance(int $reservationId, int $balanceId, string $quantity): void
    {
        $this->assertTransactionOpen();

        $input = Data::create(['quantity' => $quantity]);
        $errors = $input->validate(['quantity' => 'required|numeric|positive']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $reserved = new InventoryQuantity($quantity);
        if ($reserved->isNegative() || $reserved->isZero()) {
            throw new UnprocessableEntityException(['quantity' => ['La quantité allouée doit être supérieure à zéro.']]);
        }

        $now = date('Y-m-d H:i:s');
        InventoryRecord::table('inventory_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'balance_id' => $balanceId,
            'quantity_reserved' => $reserved->toString(),
            'quantity_consumed' => '0',
            'quantity_released' => '0',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $balance = InventoryRecord::table('inventory_stock_balances')
            ->where('id', '=', $balanceId)
            ->lockForUpdate()
            ->first();
        if ($balance === null) {
            throw new UnprocessableEntityException([
                'balance_id' => ['Le solde de stock sélectionné est introuvable.'],
            ]);
        }
        InventoryRecord::table('inventory_stock_balances')->where('id', '=', $balanceId)->update([
            'reserved_qty' => (new InventoryQuantity((string) $balance->reserved_qty))
                ->add($reserved)
                ->toString(),
            'updated_at' => $now,
        ]);

        $reservation = InventoryRecord::table('inventory_stock_reservations')
            ->where('id', '=', $reservationId)
            ->lockForUpdate()
            ->first();
        if ($reservation === null) {
            throw new UnprocessableEntityException([
                'reservation_id' => ['La réservation sélectionnée est introuvable.'],
            ]);
        }
        InventoryRecord::table('inventory_stock_reservations')->where('id', '=', $reservationId)->update([
            'quantity_reserved' => (new InventoryQuantity((string) $reservation->quantity_reserved))
                ->add($reserved)
                ->toString(),
            'updated_at' => $now,
        ]);
    }

    private function assertTransactionOpen(): void
    {
        if (!$this->connection->inTransaction()) {
            throw new UnprocessableEntityException([
                'transaction' => ['Les réservations de stock doivent être exécutées dans une transaction active.'],
            ]);
        }
    }
}
