<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Services;

use NM\Services\InventoryService\Catalog\CatalogService;
use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountRepositoryInterface;
use NM\Services\InventoryService\Inventory\Discount\DiscountTargetRegistry;
use NM\Services\InventoryService\Inventory\Discount\DTO\DiscountResult;
use NM\Services\InventoryService\Inventory\Discount\Models\Discount;
use NM\Services\InventoryService\Inventory\Discount\Validators\DiscountValidator;
use NM\Services\InventoryService\Inventory\InventoryTransactionBoundary;
use Core\Support\Date;
use Core\Exceptions\Http\ConflictHttpException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;
use DateTimeImmutable;

final class DiscountService
{
    public function __construct(
        private readonly DiscountRepositoryInterface $repository,
        private readonly DiscountTargetRegistry $targets,
        private readonly DiscountValidator $validator,
        private readonly DiscountResolver $resolver,
        private readonly DiscountCalculator $calculator,
        private readonly InventoryTransactionBoundary $transactions,
        private readonly CatalogService $catalog,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Discount
    {
        return $this->transactions->transaction(function () use ($data): Discount {
            $normalized = $this->validator->validate($data, $this->targets);
            $this->lockTarget($normalized['target_type'], $normalized['target_id']);
            $this->assertNoOverlap($normalized);

            return $this->repository->create($normalized);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): Discount
    {
        $this->assertPositiveId('discount_id', $id);

        return $this->transactions->transaction(function () use ($id, $data): Discount {
            $current = $this->repository->find($id);
            if ($current === null) {
                throw new NotFoundHttpException('La réduction est introuvable.');
            }
            $normalized = $this->validator->validate(array_replace($current->toArray(), $data), $this->targets);
            $this->lockTargets([
                [$current->targetType, $current->targetId],
                [$normalized['target_type'], $normalized['target_id']],
            ]);
            $lockedCurrent = $this->repository->find($id, true);
            if ($lockedCurrent === null) {
                throw new NotFoundHttpException('La réduction est introuvable.');
            }
            if ($lockedCurrent->toArray() !== $current->toArray()) {
                throw new ConflictHttpException('La réduction a été modifiée simultanément. Rechargez-la puis réessayez.');
            }
            $this->assertNoOverlap($normalized, $id);

            return $this->repository->update($id, $normalized);
        });
    }

    public function activate(int $id): Discount
    {
        return $this->setActive($id, true);
    }

    public function deactivate(int $id): Discount
    {
        return $this->setActive($id, false);
    }

    /**
     * Historical discounts are retained; delete is a compatibility-friendly deactivation.
     */
    public function delete(int $id): void
    {
        $this->deactivate($id);
    }

    public function find(int $id): ?Discount
    {
        return $this->repository->find($id);
    }

    /** @return list<Discount> */
    public function forTarget(string $targetType, int $targetId): array
    {
        $errors = Data::create(['target_type' => $targetType, 'target_id' => $targetId])
            ->validate(['target_type' => 'required|string', 'target_id' => 'required|integer|min:1']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $target = $this->targets->get($targetType);
        if ($target === null || !$target->exists($targetId)) {
            throw new UnprocessableEntityException([
                'target_id' => ['La cible de réduction n’existe pas ou n’est pas enregistrée.'],
            ]);
        }

        return $this->repository->forTarget($targetType, $targetId);
    }

    /**
     * Calculate a product's current default variant price without changing Catalog's stored price.
     */
    public function calculateForProduct(int $productId, ?DateTimeImmutable $at = null): DiscountResult
    {
        $this->assertPositiveId('product_id', $productId);
        $product = $this->catalog->product($productId);
        if ($product === null || (string) ($product['status'] ?? '') !== 'active'
            || !isset($product['variant_id'])) {
            throw new UnprocessableEntityException([
                'product_id' => ['Un produit Catalog actif avec une déclinaison principale est requis.'],
            ]);
        }

        $variant = $this->catalog->variant((int) $product['variant_id']);
        if ($variant === null || (string) ($variant['product_status'] ?? '') !== 'active'
            || (string) ($variant['status'] ?? '') !== 'active'
            || $variant['price'] === null) {
            throw new UnprocessableEntityException([
                'variant_id' => ['Une déclinaison Catalog active avec un prix est requise.'],
            ]);
        }

        return $this->calculateForPrice(
            productId: $productId,
            price: (string) $variant['price'],
            currency: strtoupper((string) $variant['currency']),
            at: $at,
        );
    }

    public function calculateForVariant(int $variantId, ?DateTimeImmutable $at = null): DiscountResult
    {
        $this->assertPositiveId('variant_id', $variantId);
        $variant = $this->catalog->variant($variantId);
        if ($variant === null || (string) ($variant['product_status'] ?? '') !== 'active'
            || (string) ($variant['status'] ?? '') !== 'active' || $variant['price'] === null) {
            throw new UnprocessableEntityException([
                'variant_id' => ['Une déclinaison Catalog active avec un prix est requise.'],
            ]);
        }

        return $this->calculateForPrice(
            productId: (int) $variant['product_id'],
            price: (string) $variant['price'],
            currency: strtoupper((string) $variant['currency']),
            at: $at,
        );
    }

    public function calculateForPrice(
        int $productId,
        string $price,
        string $currency,
        ?DateTimeImmutable $at = null,
    ): DiscountResult {
        $this->assertPositiveId('product_id', $productId);
        $at ??= Date::now('UTC');

        return $this->calculator->calculate(
            $price,
            $currency,
            $this->resolver->resolveForProduct($productId, $at),
            $at,
        );
    }

    private function setActive(int $id, bool $active): Discount
    {
        $this->assertPositiveId('discount_id', $id);

        return $this->transactions->transaction(function () use ($id, $active): Discount {
            $current = $this->repository->find($id);
            if ($current === null) {
                throw new NotFoundHttpException('La réduction est introuvable.');
            }
            $this->lockTarget($current->targetType, $current->targetId);
            $lockedCurrent = $this->repository->find($id, true);
            if ($lockedCurrent === null) {
                throw new NotFoundHttpException('La réduction est introuvable.');
            }
            if ($lockedCurrent->toArray() !== $current->toArray()) {
                throw new ConflictHttpException('La réduction a été modifiée simultanément. Rechargez-la puis réessayez.');
            }
            $current = $lockedCurrent;
            $data = $current->toArray();
            $data['is_active'] = $active;
            $normalized = $this->validator->validate($data, $this->targets);
            $this->assertNoOverlap($normalized, $id);

            return $this->repository->update($id, $normalized);
        });
    }

    /** @param array{target_type: string, target_id: int, starts_at: string, ends_at: string, is_active: bool} $discount */
    private function assertNoOverlap(array $discount, ?int $exceptId = null): void
    {
        if ($discount['is_active'] && $this->repository->hasOverlap(
            $discount['target_type'],
            $discount['target_id'],
            $discount['starts_at'],
            $discount['ends_at'],
            $exceptId,
        )) {
            throw new UnprocessableEntityException([
                'starts_at' => ['Une réduction active chevauche déjà cette période pour la cible sélectionnée.'],
                'ends_at' => ['Une réduction active chevauche déjà cette période pour la cible sélectionnée.'],
            ]);
        }
    }

    private function lockTarget(string $type, int $id): void
    {
        $target = $this->targets->get($type);
        if ($target === null || !$target->lock($id)) {
            throw new UnprocessableEntityException([
                'target_id' => ['La cible de réduction n’existe pas ou ne peut pas être verrouillée.'],
            ]);
        }
    }

    /** @param list<array{0: string, 1: int}> $targets */
    private function lockTargets(array $targets): void
    {
        $unique = [];
        foreach ($targets as [$type, $id]) {
            $unique[$type . ':' . $id] = [$type, $id];
        }
        ksort($unique, SORT_STRING);
        foreach ($unique as [$type, $id]) {
            $this->lockTarget($type, $id);
        }
    }

    private function assertPositiveId(string $field, int $id): void
    {
        $errors = Data::create([$field => $id])->validate([$field => 'required|integer|min:1']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }
}
