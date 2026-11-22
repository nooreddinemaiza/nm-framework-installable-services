<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use Core\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;

final class SalesStatisticsService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{
     *   product: array<string, mixed>,
     *   metrics: array<string, mixed>,
     *   sales: list<array<string, mixed>>,
     *   movements: list<array<string, mixed>>,
     *   period: array{from: string, to: string}
     * }|null
     */
    public function productReport(int $productId, ?string $from = null, ?string $to = null): ?array
    {
        $period = $this->period($from, $to);
        $product = $this->connection->selectOne(
            'SELECT p.id, p.name, p.slug, p.kind, p.status, p.description, p.manufacturer,
                    c.name AS category_name, b.name AS brand_name,
                    v.id AS variant_id, v.sku, v.price, v.cost_price, v.currency,
                    COALESCE(SUM(balance.physical_qty), 0) AS physical_qty,
                    COALESCE(SUM(balance.reserved_qty), 0) AS reserved_qty,
                    MAX(u.code) AS unit_code
             FROM catalog_products p
             LEFT JOIN catalog_categories c ON c.id = p.category_id
             LEFT JOIN catalog_brands b ON b.id = p.brand_id
             LEFT JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
             LEFT JOIN inventory_items item ON item.variant_id = v.id
             LEFT JOIN inventory_stock_balances balance ON balance.item_id = item.id
             LEFT JOIN inventory_units u ON u.id = item.reference_unit_id
             WHERE p.id = ?
             GROUP BY p.id, p.name, p.slug, p.kind, p.status, p.description, p.manufacturer,
                      c.name, b.name, v.id, v.sku, v.price, v.cost_price, v.currency
             LIMIT 1',
            [$productId],
        );
        if ($product === null) {
            return null;
        }

        $sales = $this->connection->select(
            'SELECT DATE(o.created_at) AS sale_date, i.currency,
                    SUM(i.quantity) AS quantity, SUM(i.line_amount_minor) AS revenue_minor,
                    CASE WHEN v.cost_price IS NULL OR i.currency <> v.currency THEN NULL
                         ELSE SUM((i.unit_amount_minor / 100.0 - v.cost_price) * i.quantity * 100)
                    END AS estimated_profit_minor
             FROM sales_order_items i
             INNER JOIN sales_orders o ON o.id = i.order_id AND o.application_id = i.application_id
             INNER JOIN catalog_products p ON p.id = i.item_reference
             INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
             WHERE p.id = ? AND i.currency = v.currency AND o.status = ? AND o.tenant_id IS NULL
               AND o.created_at >= ? AND o.created_at < ?
             GROUP BY DATE(o.created_at), i.currency, v.cost_price, v.currency
             ORDER BY DATE(o.created_at) ASC',
            [$productId, 'completed', $period['from'] . ' 00:00:00', $this->nextDay($period['to'])],
        );

        $movements = $this->connection->select(
            'SELECT entry.movement_type, entry.quantity, entry.operational_at,
                    operation.reference, entry.reason
             FROM inventory_stock_ledger_entries entry
             INNER JOIN inventory_stock_operations operation ON operation.id = entry.operation_id
             INNER JOIN inventory_items item ON item.id = entry.item_id
             INNER JOIN catalog_variants v ON v.id = item.variant_id
             WHERE v.product_id = ? AND operation.status = ?
               AND entry.operational_at >= ? AND entry.operational_at < ?
             ORDER BY entry.operational_at DESC, entry.id DESC
             LIMIT 100',
            [$productId, 'posted', $period['from'] . ' 00:00:00', $this->nextDay($period['to'])],
        );

        $unitsSold = 0.0;
        $revenueMinor = 0;
        $profitMinor = 0.0;
        $profitAvailable = $product['cost_price'] !== null;
        foreach ($sales as $point) {
            $unitsSold += (float) $point['quantity'];
            $revenueMinor += (int) $point['revenue_minor'];
            if ($point['estimated_profit_minor'] === null) {
                $profitAvailable = false;
            } else {
                $profitMinor += (float) $point['estimated_profit_minor'];
            }
        }

        return [
            'product' => $product,
            'metrics' => [
                'units_sold' => $unitsSold,
                'revenue_minor' => $revenueMinor,
                'estimated_profit_minor' => $profitAvailable ? $profitMinor : null,
                'currency' => (string) ($product['currency'] ?? 'MAD'),
                'stock_available' => (float) $product['physical_qty'] - (float) $product['reserved_qty'],
            ],
            'sales' => $sales,
            'movements' => $movements,
            'period' => $period,
        ];
    }

    /**
     * @return array{
     *   ranking: list<array<string, mixed>>,
     *   out_of_stock: list<array<string, mixed>>,
     *   period: array{from: string, to: string}
     * }
     */
    public function catalogReport(?string $from = null, ?string $to = null): array
    {
        $period = $this->period($from, $to);
        $ranking = $this->connection->select(
            'SELECT p.id, p.name, p.slug, p.kind, v.currency,
                    COALESCE(sales.units_sold, 0) AS units_sold,
                    COALESCE(sales.revenue_minor, 0) AS revenue_minor,
                    CASE WHEN v.cost_price IS NULL THEN NULL
                         ELSE COALESCE(sales.revenue_minor, 0) - (v.cost_price * COALESCE(sales.units_sold, 0) * 100)
                    END AS estimated_profit_minor
             FROM catalog_products p
             INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
             LEFT JOIN (
                 SELECT i.item_reference, i.currency, SUM(i.quantity) AS units_sold,
                        SUM(i.line_amount_minor) AS revenue_minor
                 FROM sales_order_items i
                 INNER JOIN sales_orders o ON o.id = i.order_id AND o.application_id = i.application_id
                 WHERE o.status = ? AND o.tenant_id IS NULL
                   AND o.created_at >= ? AND o.created_at < ?
                 GROUP BY i.item_reference, i.currency
             ) sales ON sales.item_reference = p.id AND sales.currency = v.currency
             WHERE p.status = ?
             ORDER BY units_sold DESC, revenue_minor DESC, p.name ASC
             LIMIT 100',
            ['completed', $period['from'] . ' 00:00:00', $this->nextDay($period['to']), 'active'],
        );
        $outOfStock = $this->connection->select(
            'SELECT p.id, p.name, p.slug, p.kind, COALESCE(SUM(balance.physical_qty), 0) AS physical_qty,
                    COALESCE(SUM(balance.reserved_qty), 0) AS reserved_qty, MAX(u.code) AS unit_code
             FROM catalog_products p
             INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
             LEFT JOIN inventory_items item ON item.variant_id = v.id AND item.track_inventory = 1
             LEFT JOIN inventory_stock_balances balance ON balance.item_id = item.id
             LEFT JOIN inventory_units u ON u.id = item.reference_unit_id
             WHERE p.status = ? AND p.kind = ?
             GROUP BY p.id, p.name, p.slug, p.kind
             HAVING COALESCE(SUM(balance.physical_qty), 0) - COALESCE(SUM(balance.reserved_qty), 0) <= 0
             ORDER BY p.name ASC
             LIMIT 100',
            ['active', 'goods'],
        );

        return ['ranking' => $ranking, 'out_of_stock' => $outOfStock, 'period' => $period];
    }

    /** @return array{from: string, to: string} */
    private function period(?string $from, ?string $to): array
    {
        $today = new DateTimeImmutable('today');
        $from = $from ?: $today->modify('-29 days')->format('Y-m-d');
        $to = $to ?: $today->format('Y-m-d');
        foreach ([$from, $to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('La période statistique est invalide.');
            }
        }
        if ($from > $to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 365) {
            throw new InvalidArgumentException('La période statistique doit être comprise entre un et 366 jours.');
        }

        return ['from' => $from, 'to' => $to];
    }

    private function nextDay(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
}
