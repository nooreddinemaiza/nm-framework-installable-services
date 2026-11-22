<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount;

use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountTargetInterface;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final class DiscountTargetRegistry
{
    /** @var array<string, DiscountTargetInterface> */
    private array $targets = [];

    public function register(DiscountTargetInterface $target): void
    {
        $type = $target->targetType();
        $errors = Data::create(['target_type' => $type])->validate([
            'target_type' => 'required|string|regex:/^[a-z][a-z0-9_]{0,99}$/',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        if (isset($this->targets[$type])) {
            throw new UnprocessableEntityException([
                'target_type' => [sprintf('Le type de cible [%s] est déjà enregistré.', $type)],
            ]);
        }

        $this->targets[$type] = $target;
    }

    public function get(string $type): ?DiscountTargetInterface
    {
        return $this->targets[$type] ?? null;
    }

    /** @return list<DiscountTargetInterface> */
    public function all(): array
    {
        return array_values($this->targets);
    }
}
