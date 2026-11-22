<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Repositories;

use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountRepositoryInterface;
use NM\Services\InventoryService\Inventory\Discount\Models\Discount;
use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\InternalServerException;
use Core\Support\Date;

final class DiscountRepository implements DiscountRepositoryInterface
{
    public function create(array $data): Discount
    {
        $now = Date::now('UTC')->format('Y-m-d H:i:s');
        $id = InventoryRecord::table('discounts')->insertGetId([
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'discount_type' => $data['discount_type'],
            'value' => $data['value'],
            'currency' => $data['currency'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id)
            ?? throw new InternalServerException('La réduction créée n’a pas pu être relue.');
    }

    public function find(int $id, bool $forUpdate = false): ?Discount
    {
        $query = InventoryRecord::table('discounts')->where('id', '=', $id);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row === null ? null : Discount::fromRow($row->toArray());
    }

    public function update(int $id, array $data): Discount
    {
        $now = Date::now('UTC')->format('Y-m-d H:i:s');
        InventoryRecord::table('discounts')
            ->where('id', '=', $id)
            ->update([
                'target_type' => $data['target_type'],
                'target_id' => $data['target_id'],
                'discount_type' => $data['discount_type'],
                'value' => $data['value'],
                'currency' => $data['currency'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'is_active' => $data['is_active'] ? 1 : 0,
                'updated_at' => $now,
            ]);

        return $this->find($id) ?? throw new InternalServerException('La réduction modifiée n’a pas pu être relue.');
    }

    public function forTarget(string $targetType, int $targetId): array
    {
        $rows = InventoryRecord::table('discounts')
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        return array_map(static fn(InventoryRecord $row): Discount => Discount::fromRow($row->toArray()), $rows);
    }

    public function hasOverlap(
        string $targetType,
        int $targetId,
        string $startsAt,
        string $endsAt,
        ?int $exceptId = null
    ): bool {
        $query = InventoryRecord::table('discounts')
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->where('is_active', '=', 1)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->lockForUpdate()->first() !== null;
    }

    public function findApplicable(string $targetType, int $targetId, string $at): array
    {
        $rows = InventoryRecord::table('discounts')
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->where('is_active', '=', 1)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderBy('id')
            ->get();

        return array_map(static fn(InventoryRecord $row): Discount => Discount::fromRow($row->toArray()), $rows);
    }
}
