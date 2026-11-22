<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Adapters;

use NM\Services\InventoryService\Inventory\Discount\Services\DiscountService;
use NM\Services\SalesService\Sales\Contracts\CatalogItem;
use NM\Services\SalesService\Sales\Contracts\CatalogPort;
use NM\Services\SalesService\Sales\Domain\Money;
use NM\Services\SalesService\Sales\SalesContext;
use Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class LocalCatalogAdapter implements CatalogPort
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?DiscountService $discounts = null,
    ) {
    }

    public function findItem(SalesContext $context, string $reference): ?CatalogItem
    {
        unset($context);
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $reference)) {
            return null;
        }

        $row = $this->connection->selectOne(
            'SELECT p.id AS product_id, p.name, p.kind, v.id AS variant_id, v.price, v.currency,
                    COALESCE(u.code, ?) AS unit
             FROM catalog_products p
             INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
             LEFT JOIN inventory_items i ON i.variant_id = v.id AND i.status = ? AND i.archived_at IS NULL
             LEFT JOIN inventory_units u ON u.id = i.reference_unit_id
             WHERE p.id = ? AND p.status = ? AND v.status = ? AND v.price IS NOT NULL
             LIMIT 1',
            ['PCS', 'active', (int) $reference, 'active', 'active']
        );
        if ($row === null) {
            return null;
        }

        $currency = strtoupper((string) $row['currency']);
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new RuntimeException('The catalog item has an invalid currency.');
        }

        $unitPrice = new Money(self::toMinorAmount((string) $row['price']), $currency);
        $originalUnitPrice = $unitPrice;
        $priceSource = 'catalog.variant:' . (string) $row['variant_id'];
        $discountResult = null;
        if ($this->discounts !== null) {
            $discountResult = $this->discounts->calculateForProduct((int) $row['product_id']);
            if ($discountResult->appliedDiscounts !== []) {
                $unitPrice = new Money(self::toMinorAmount($discountResult->finalPrice), $currency);
                $priceSource .= ':discount:' . substr(
                    hash('sha256', json_encode($discountResult->appliedDiscounts, JSON_THROW_ON_ERROR)),
                    0,
                    12
                );
            }
        }

        return new CatalogItem(
            reference: (string) $row['product_id'],
            label: (string) $row['name'],
            unit: (string) $row['unit'],
            unitPrice: $unitPrice,
            priceSource: $priceSource,
            originalUnitPrice: $originalUnitPrice,
            discount: $discountResult,
        );
    }

    public static function toMinorAmount(string $price): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,14})(?:\.([0-9]{1,4}))?$/D', $price, $matches)) {
            throw new InvalidArgumentException('Catalog price must be a non-negative decimal amount.');
        }

        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new RuntimeException('Catalog prices must use no more than two decimal places.');
        }
        $minor = ltrim($matches[1] . str_pad(substr($fraction, 0, 2), 2, '0'), '0');
        $minor = $minor === '' ? '0' : $minor;
        if (strlen($minor) > strlen((string) PHP_INT_MAX)
            || (strlen($minor) === strlen((string) PHP_INT_MAX) && strcmp($minor, (string) PHP_INT_MAX) > 0)) {
            throw new RuntimeException('Catalog price exceeds the supported order amount.');
        }

        return (int) $minor;
    }
}
