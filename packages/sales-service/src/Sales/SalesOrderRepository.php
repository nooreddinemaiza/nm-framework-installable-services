<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Sales\Domain\Cart;
use NM\Services\SalesService\Sales\Domain\PriceQuote;
use NM\Services\SalesService\Sales\Domain\SalesOrder;
use NM\Services\SalesService\Sales\Domain\SalesOrderState;
use NM\Services\SalesService\Sales\Domain\Money;
use NM\Services\SalesService\Models\SalesApplicationModuleRecord;
use NM\Services\SalesService\Models\SalesApplicationRecord;
use NM\Services\SalesService\Models\SalesOrderItemRecord;
use NM\Services\SalesService\Models\SalesOrderRecord;
use Core\Database\Connection;
use Core\Database\Paginator;
use Core\Database\QueryBuilder;
use DateTimeImmutable;
use RuntimeException;

final class SalesOrderRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SalesCartRepository $carts,
    ) {
    }

    public function countForDashboard(string $status = 'all', array $filters = []): int
    {
        return $this->dashboardQuery($status, $filters)->count();
    }

    /**
     * @return list<array{reference: string, status: string, total: string, created_at: string}>
     */
    public function dashboardRecentOrders(int $limit = 5): array
    {
        if ($limit < 1 || $limit > 10) {
            throw new \InvalidArgumentException('Sales dashboard order limit is invalid.');
        }

        return array_map(
            static function (SalesOrderRecord $order): array {
                $id = (int) $order->getAttribute('id');
                $reference = trim((string) $order->getAttribute('public_reference'));

                return [
                    'reference' => $reference !== '' ? $reference : '#' . $id,
                    'status' => (string) $order->getAttribute('status'),
                    'total' => (new Money(
                        (int) $order->getAttribute('total_minor'),
                        (string) $order->getAttribute('currency'),
                    ))->format(),
                    'created_at' => (string) $order->getAttribute('created_at'),
                ];
            },
            $this->dashboardQuery('all', [])
                ->select('o.id', 'o.public_reference', 'o.status', 'o.total_minor', 'o.currency', 'o.created_at')
                ->orderBy('o.created_at', 'DESC')
                ->orderBy('o.id', 'DESC')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * @return list<array{date: string, label: string, orders: int, revenue: list<string>}>
     */
    public function dashboardDailySales(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to || $from->diff($to)->days > 31) {
            throw new \InvalidArgumentException('Sales dashboard date range is invalid.');
        }

        $days = [];
        for ($date = $from->setTime(0, 0); $date <= $to->setTime(0, 0); $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            $rows = $this->dashboardQuery('all', ['date_from' => $day, 'date_to' => $day])
                ->select('o.currency')
                ->selectRaw('COUNT(*) AS order_count, COALESCE(SUM(o.total_minor), 0) AS revenue_minor')
                ->groupBy('o.currency')
                ->get();
            $orders = 0;
            $revenue = [];
            foreach ($rows as $row) {
                $orders += (int) $row->getAttribute('order_count');
                $revenue[] = (new Money(
                    (int) $row->getAttribute('revenue_minor'),
                    (string) $row->getAttribute('currency'),
                ))->format();
            }
            $days[] = [
                'date' => $day,
                'label' => $date->format('d/m'),
                'orders' => $orders,
                'revenue' => $revenue,
            ];
        }

        return $days;
    }

    /**
     * Most requested catalog item based on items in dashboard-visible sales.
     *
     * @return array{product_name: string, quantity: string}|null
     */
    public function mostRequestedProductForDashboard(): ?array
    {
        $item = SalesOrderRecord::table(SalesOrderRecord::getTable() . ' as o')
            ->join(SalesApplicationRecord::getTable() . ' as a', 'a.id', '=', 'o.application_id')
            ->join(SalesOrderItemRecord::getTable() . ' as i', function ($join): void {
                $join->on('i.order_id', '=', 'o.id')
                    ->on('i.application_id', '=', 'o.application_id');
            })
            ->whereNull('o.tenant_id')
            ->whereNull('a.tenant_id')
            ->whereNull('i.tenant_id')
            ->where('a.status', '=', 'active')
            ->select('i.label_snapshot as product_name')
            ->selectRaw('COALESCE(SUM(i.quantity), 0) AS requested_quantity')
            ->groupBy('i.label_snapshot')
            ->orderBy('requested_quantity', 'DESC')
            ->limit(1)
            ->first();

        if ($item === null) {
            return null;
        }

        return [
            'product_name' => (string) $item->getAttribute('product_name'),
            'quantity' => (string) $item->getAttribute('requested_quantity'),
        ];
    }

    /** @return list<array{id: int, name: string, currency: string}> */
    public function activeDashboardApplications(): array
    {
        $applicationIds = $this->activeOrderApplicationIds();
        if ($applicationIds === []) {
            return [];
        }

        return array_map(
            static fn(SalesApplicationRecord $application): array => [
                'id' => (int) $application->getAttribute('id'),
                'name' => (string) $application->getAttribute('name'),
                'currency' => (string) $application->getAttribute('currency'),
            ],
            SalesApplicationRecord::query()
                ->where('status', '=', 'active')
                ->whereNull('tenant_id')
                ->whereIn('id', $applicationIds)
                ->select('id', 'name', 'currency')
                ->orderBy('name')
                ->get()
        );
    }

    public function applicationIdForDashboardOrder(string $orderId): ?int
    {
        $order = SalesOrderRecord::table(SalesOrderRecord::getTable() . ' as o')
            ->join(SalesApplicationRecord::getTable() . ' as a', 'a.id', '=', 'o.application_id')
            ->where('o.id', '=', (int) $orderId)
            ->whereNull('o.tenant_id')
            ->whereNull('a.tenant_id')
            ->where('a.status', '=', 'active')
            ->select('o.application_id')
            ->first();

        return $order === null ? null : (int) $order->getAttribute('application_id');
    }

    /**
     * Dashboard-only listing, deliberately restricted to applications and
     * orders without a tenant until authenticated tenant scoping is available.
     *
     * @return Paginator
     */
    public function pageForDashboard(
        string $status = 'all',
        int $page = 1,
        int $perPage = 50,
        array $filters = [],
    ): Paginator
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Sales dashboard pagination is invalid.');
        }
        $paginated = $this->dashboardQuery($status, $filters)
            ->select(
                'o.id',
                'o.application_id',
                'a.name as application_name',
                'a.code as application_code',
                'o.profile',
                'o.status',
                'o.currency',
                'o.subtotal_minor',
                'o.tax_minor',
                'o.total_minor',
                'o.version',
                'o.created_at',
                'o.details_json',
                'o.public_reference',
            )
            ->orderBy('o.id', 'desc')
            ->paginate($page, $perPage);
        $orders = array_map(
            static fn(SalesOrderRecord $order): array => $order->toArray(),
            $paginated->items()
        );
        if ($orders === []) {
            return $paginated->map(static fn(SalesOrderRecord $order): array => $order->toArray());
        }

        $orderIds = array_column($orders, 'id');
        $applicationIds = $this->activeOrderApplicationIds();
        $enabledApplications = array_fill_keys($applicationIds, true);
        $items = SalesOrderItemRecord::query()
            ->whereNull('tenant_id')
            ->whereIn('order_id', $orderIds)
            ->select(
                'order_id',
                'application_id',
                'item_reference',
                'label_snapshot',
                'quantity',
                'unit',
                'unit_amount_minor',
                'line_amount_minor',
                'currency',
            )
            ->orderBy('id')
            ->get();
        $itemsByOrder = [];
        foreach ($items as $item) {
            $itemsByOrder[(int) $item->getAttribute('order_id')][] = $item->toArray();
        }

        foreach ($orders as &$order) {
            $order['id'] = (int) $order['id'];
            $order['application_id'] = (int) $order['application_id'];
            $order['version'] = (int) $order['version'];
            $order['subtotal_minor'] = (int) $order['subtotal_minor'];
            $order['tax_minor'] = (int) $order['tax_minor'];
            $order['total_minor'] = (int) $order['total_minor'];
            $order['orders_enabled'] = isset($enabledApplications[$order['application_id']]);
            $order['items'] = $itemsByOrder[$order['id']] ?? [];
            $details = json_decode((string) $order['details_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($details)) {
                throw new RuntimeException('Stored sales order details are invalid.');
            }
            $order['checkout_details'] = is_array($details['checkout'] ?? null)
                ? $details['checkout']
                : [];
            $order['dashboard_manual'] = ($details['dashboard_manual'] ?? false) === true;
            unset($order['details_json']);
        }
        unset($order);

        return new Paginator(
            $orders,
            $paginated->total(),
            $paginated->perPage(),
            $paginated->currentPage(),
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array{orders: int, pieces: float, revenue_by_currency: array<string, int>, average_by_currency: array<string, int>, statuses: array<string, int>}
     */
    public function dashboardStatistics(string $status = 'all', array $filters = []): array
    {
        $summaryRows = $this->dashboardQuery($status, $filters)
            ->select('o.currency')
            ->selectRaw(
                'COUNT(*) AS orders, COALESCE(SUM(o.total_minor), 0) AS revenue_minor,
                 COALESCE(AVG(o.total_minor), 0) AS average_minor'
            )
            ->groupBy('o.currency')
            ->get();
        $revenueByCurrency = [];
        $averageByCurrency = [];
        $totalOrders = 0;
        foreach ($summaryRows as $row) {
            $currency = (string) $row->getAttribute('currency');
            $revenueByCurrency[$currency] = (int) $row->getAttribute('revenue_minor');
            $averageByCurrency[$currency] = (int) round((float) $row->getAttribute('average_minor'));
            $totalOrders += (int) $row->getAttribute('orders');
        }
        $pieces = $this->dashboardQuery($status, $filters)
            ->leftJoin(SalesOrderItemRecord::getTable() . ' as i', function ($join): void {
                $join->on('i.order_id', '=', 'o.id')
                    ->on('i.application_id', '=', 'o.application_id')
                    ->whereRaw('i.tenant_id IS NULL');
            })
            ->sum('i.quantity');
        $statusRows = $this->dashboardQuery($status, $filters)
            ->select('o.status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('o.status')
            ->get();
        $statuses = [];
        foreach ($statusRows as $row) {
            $statuses[(string) $row->getAttribute('status')] = (int) $row->getAttribute('total');
        }

        return [
            'orders' => $totalOrders,
            'pieces' => (float) ($pieces ?? 0),
            'revenue_by_currency' => $revenueByCurrency,
            'average_by_currency' => $averageByCurrency,
            'statuses' => $statuses,
        ];
    }

    /**
     * @param list<array{item_reference: string, label: string, quantity: string, unit: string, unit_amount_minor: int, line_amount_minor: int}> $items
     * @param array{name: string, phone: string, email: string} $customer
     * @return array{id: string, public_reference: string}
     */
    public function createManualDashboardOrder(
        int $applicationId,
        string $currency,
        array $customer,
        array $items,
        string $note,
        ?string $actorReference,
    ): array {
        $now = gmdate('Y-m-d H:i:s');
        $publicReference = $this->newPublicReference();
        $subtotal = array_sum(array_column($items, 'line_amount_minor'));
        $details = [
            'dashboard_manual' => true,
            'checkout' => [
                'customer' => $customer,
                'note' => $note,
            ],
        ];
        $this->connection->insert(
            'INSERT INTO sales_orders (application_id, tenant_id, cart_id, customer_id, profile, status, currency, subtotal_minor, tax_minor, discount_minor, fee_minor, total_minor, details_json, public_reference, public_reference_hash, version, created_at, updated_at) VALUES (?, NULL, NULL, NULL, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, 1, ?, ?)',
            [$applicationId, 'retail', SalesOrderState::PENDING, $currency, $subtotal, $subtotal, json_encode($details, JSON_THROW_ON_ERROR), $publicReference, hash('sha256', $publicReference), $now, $now]
        );
        $orderId = (string) $this->connection->lastInsertId();
        foreach ($items as $item) {
            $this->connection->insert(
                'INSERT INTO sales_order_items (application_id, tenant_id, order_id, item_reference, label_snapshot, quantity, unit, unit_amount_minor, line_amount_minor, currency, modifiers_json, tax_snapshot_json, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$applicationId, (int) $orderId, $item['item_reference'], $item['label'], $item['quantity'], $item['unit'], $item['unit_amount_minor'], $item['line_amount_minor'], $currency, '[]', '[]', $now, $now]
            );
        }
        $this->connection->insert(
            'INSERT INTO sales_order_transitions (application_id, tenant_id, order_id, from_status, to_status, actor_ref, reason, created_at) VALUES (?, NULL, ?, NULL, ?, ?, ?, ?)',
            [$applicationId, (int) $orderId, SalesOrderState::PENDING, $actorReference, 'Commande créée depuis le tableau de bord.', $now]
        );
        $this->carts->appendOutboxEvent(
            new SalesContext($applicationId, scopes: ['*'], actorReference: $actorReference),
            'OrderPlaced',
            $orderId,
            ['order_id' => $orderId, 'status' => SalesOrderState::PENDING, 'total_minor' => $subtotal, 'currency' => $currency],
            'order',
        );

        return ['id' => $orderId, 'public_reference' => $publicReference];
    }

    /**
     * @param array{name: string, phone: string, email: string} $customer
     * @param list<array{item_reference: string, label: string, quantity: string, unit: string, unit_amount_minor: int, line_amount_minor: int}>|null $items
     */
    public function updateDashboardOrder(
        int $applicationId,
        string $orderId,
        int $expectedVersion,
        array $customer,
        string $note,
        ?array $items,
    ): void {
        $order = $this->connection->selectOne(
            'SELECT id, currency, details_json FROM sales_orders WHERE id = ? AND application_id = ? AND tenant_id IS NULL',
            [(int) $orderId, $applicationId]
        );
        if ($order === null) {
            throw new RuntimeException('Sales order was not found.');
        }
        $details = json_decode((string) $order['details_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new RuntimeException('Stored sales order details are invalid.');
        }
        if ($items !== null && ($details['dashboard_manual'] ?? false) !== true) {
            throw new \InvalidArgumentException('Les lignes d’une commande issue du checkout ne peuvent pas être modifiées ici.');
        }
        $checkout = is_array($details['checkout'] ?? null) ? $details['checkout'] : [];
        $checkout['customer'] = $customer;
        $checkout['note'] = $note;
        $details['checkout'] = $checkout;
        $bindings = [json_encode($details, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s'), (int) $orderId, $applicationId, $expectedVersion];
        $changed = $this->connection->update(
            'UPDATE sales_orders SET details_json = ?, version = version + 1, updated_at = ? WHERE id = ? AND application_id = ? AND tenant_id IS NULL AND version = ?',
            $bindings
        );
        if ($changed !== 1) {
            throw new SalesCartConflictException('Sales order changed; reload it and retry.');
        }
        $this->carts->appendOutboxEvent(
            new SalesContext($applicationId, scopes: ['*']),
            'OrderUpdated',
            $orderId,
            ['order_id' => $orderId, 'version' => $expectedVersion + 1],
            'order',
        );
        if ($items === null) {
            return;
        }
        $subtotal = array_sum(array_column($items, 'line_amount_minor'));
        $this->connection->update(
            'UPDATE sales_orders SET subtotal_minor = ?, total_minor = ? WHERE id = ? AND application_id = ? AND tenant_id IS NULL',
            [$subtotal, $subtotal, (int) $orderId, $applicationId]
        );
        $this->connection->delete(
            'DELETE FROM sales_order_items WHERE order_id = ? AND application_id = ? AND tenant_id IS NULL',
            [(int) $orderId, $applicationId]
        );
        $now = gmdate('Y-m-d H:i:s');
        $publicReference = $this->newPublicReference();
        foreach ($items as $item) {
            $this->connection->insert(
                'INSERT INTO sales_order_items (application_id, tenant_id, order_id, item_reference, label_snapshot, quantity, unit, unit_amount_minor, line_amount_minor, currency, modifiers_json, tax_snapshot_json, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$applicationId, (int) $orderId, $item['item_reference'], $item['label'], $item['quantity'], $item['unit'], $item['unit_amount_minor'], $item['line_amount_minor'], (string) $order['currency'], '[]', '[]', $now, $now]
            );
        }
    }

    public function deleteDashboardOrder(int $applicationId, string $orderId): void
    {
        $order = $this->connection->selectOne(
            'SELECT id, cart_id FROM sales_orders WHERE id = ? AND application_id = ? AND tenant_id IS NULL',
            [(int) $orderId, $applicationId]
        );
        if ($order === null) {
            throw new RuntimeException('Sales order was not found.');
        }
        if ($order['cart_id'] !== null) {
            $this->connection->update(
                'UPDATE sales_carts SET order_id = NULL WHERE id = ? AND application_id = ? AND tenant_id IS NULL AND order_id = ?',
                [(int) $order['cart_id'], $applicationId, (int) $orderId]
            );
            $this->connection->delete(
                'DELETE FROM sales_idempotency WHERE application_id = ? AND tenant_id IS NULL AND operation = ?',
                [$applicationId, 'cart.checkout:' . $order['cart_id']]
            );
        }
        $this->connection->delete(
            'DELETE FROM sales_outbox WHERE application_id = ? AND tenant_id IS NULL AND aggregate_type = ? AND aggregate_id = ?',
            [$applicationId, 'order', $orderId]
        );
        $this->connection->delete('DELETE FROM sales_order_transitions WHERE order_id = ? AND application_id = ?', [(int) $orderId, $applicationId]);
        $this->connection->delete('DELETE FROM sales_order_items WHERE order_id = ? AND application_id = ?', [(int) $orderId, $applicationId]);
        $this->connection->delete('DELETE FROM sales_orders WHERE id = ? AND application_id = ? AND tenant_id IS NULL', [(int) $orderId, $applicationId]);
        $this->carts->appendOutboxEvent(
            new SalesContext($applicationId, scopes: ['*']),
            'OrderDeleted',
            $orderId,
            ['order_id' => $orderId],
            'order',
        );
    }

    public function createFromCart(
        SalesContext $context,
        Cart $cart,
        PriceQuote $quote,
        string $idempotencyKey,
        string $requestHash,
        array $orderDetails = [],
    ): SalesOrder {
        if (count($cart->lines) !== count($quote->lineTaxes)) {
            throw new RuntimeException('Pricing snapshot does not match the cart lines.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $publicReference = $this->newPublicReference();
        $scopeSql = $context->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $claimBindings = ['converting', $now, (int) $cart->id, $context->applicationId];
        if ($context->tenantId !== null) {
            $claimBindings[] = $context->tenantId;
        }
        array_push($claimBindings, $cart->version, 'open', $now);
        $claimed = $this->connection->update(
            "UPDATE sales_carts SET status = ?, version = version + 1, updated_at = ? WHERE id = ? AND application_id = ? AND {$scopeSql} AND version = ? AND status = ? AND (expires_at IS NULL OR expires_at > ?)",
            $claimBindings
        );
        if ($claimed !== 1) {
            throw new SalesCartConflictException('Cart changed or expired during checkout.');
        }

        $this->connection->insert(
            'INSERT INTO sales_orders (application_id, tenant_id, cart_id, customer_id, profile, status, currency, subtotal_minor, tax_minor, discount_minor, fee_minor, total_minor, details_json, public_reference, public_reference_hash, version, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, 1, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                (int) $cart->id,
                $cart->profile,
                SalesOrderState::PENDING,
                $cart->currency,
                $quote->subtotal->minorAmount,
                $quote->tax->minorAmount,
                $quote->total->minorAmount,
                json_encode([
                    'source_cart_id' => $cart->id,
                    'source_cart_version' => $cart->version,
                    'pricing' => $quote->snapshot(),
                    'checkout' => $orderDetails,
                ], JSON_THROW_ON_ERROR),
                $publicReference,
                hash('sha256', $publicReference),
                $now,
                $now,
            ]
        );
        $orderId = (string) $this->connection->lastInsertId();

        foreach ($cart->lines as $index => $line) {
            $this->connection->insert(
                'INSERT INTO sales_order_items (application_id, tenant_id, order_id, item_reference, label_snapshot, quantity, unit, unit_amount_minor, line_amount_minor, currency, modifiers_json, tax_snapshot_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $context->applicationId,
                    $context->tenantId,
                    (int) $orderId,
                    $line->itemReference,
                    $line->label,
                    $line->quantity,
                    $line->unit,
                    $line->unitPrice->minorAmount,
                    $line->total()->minorAmount,
                    $cart->currency,
                    json_encode($line->modifiers, JSON_THROW_ON_ERROR),
                    json_encode($quote->lineTaxes[$index], JSON_THROW_ON_ERROR),
                    $now,
                    $now,
                ]
            );
        }

        $this->connection->insert(
            'INSERT INTO sales_order_transitions (application_id, tenant_id, order_id, from_status, to_status, actor_ref, reason, created_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                (int) $orderId,
                SalesOrderState::PENDING,
                $context->actorReference,
                'Order created from cart.',
                $now,
            ]
        );

        $updated = $this->connection->update(
            "UPDATE sales_carts SET status = ?, order_id = ?, updated_at = ? WHERE id = ? AND application_id = ? AND {$scopeSql} AND status = ? AND version = ?",
            $context->tenantId === null
                ? ['converted', (int) $orderId, $now, (int) $cart->id, $context->applicationId, 'converting', $cart->version + 1]
                : ['converted', (int) $orderId, $now, (int) $cart->id, $context->applicationId, $context->tenantId, 'converting', $cart->version + 1]
        );
        if ($updated !== 1) {
            throw new SalesCartConflictException('Cart could not be converted into an order.');
        }

        $this->carts->appendOutboxEvent($context, 'CartConverted', $cart->id, [
            'cart_id' => $cart->id,
            'order_id' => $orderId,
        ]);
        $this->carts->appendOutboxEvent($context, 'OrderPlaced', $orderId, [
            'order_id' => $orderId,
            'cart_id' => $cart->id,
            'status' => SalesOrderState::PENDING,
            'total_minor' => $quote->total->minorAmount,
            'currency' => $cart->currency,
        ], 'order');
        $this->carts->recordIdempotency(
            $context,
            'cart.checkout:' . $cart->id,
            $idempotencyKey,
            $requestHash,
            ['order_id' => $orderId],
        );

        $order = $this->find($context, $orderId)
            ?? throw new RuntimeException('New sales order could not be loaded.');
        return new SalesOrder(
            id: $order->id,
            applicationId: $order->applicationId,
            tenantId: $order->tenantId,
            profile: $order->profile,
            status: $order->status,
            version: $order->version,
            subtotal: $order->subtotal,
            tax: $order->tax,
            total: $order->total,
            lines: $order->lines,
            details: $order->details,
            publicReference: $publicReference,
        );
    }

    public function find(SalesContext $context, string $orderId): ?SalesOrder
    {
        $scopeSql = $context->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $bindings = [$orderId, $context->applicationId];
        if ($context->tenantId !== null) {
            $bindings[] = $context->tenantId;
        }

        $row = $this->connection->selectOne(
            "SELECT id, application_id, tenant_id, profile, status, currency, subtotal_minor, tax_minor, total_minor, version, details_json, public_reference FROM sales_orders WHERE id = ? AND application_id = ? AND {$scopeSql}",
            $bindings
        );
        if ($row === null) {
            return null;
        }

        $items = $this->connection->select(
            "SELECT item_reference, label_snapshot, quantity, unit, unit_amount_minor, line_amount_minor, currency, modifiers_json, tax_snapshot_json FROM sales_order_items WHERE order_id = ? AND application_id = ? AND {$scopeSql} ORDER BY id ASC",
            $bindings
        );
        $lines = [];
        foreach ($items as $item) {
            $modifiers = json_decode((string) $item['modifiers_json'], true, 64, JSON_THROW_ON_ERROR);
            $tax = json_decode((string) $item['tax_snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($modifiers) || !is_array($tax)) {
                throw new RuntimeException('Stored sales order line is invalid.');
            }
            $lines[] = [
                'item_reference' => (string) $item['item_reference'],
                'label' => (string) $item['label_snapshot'],
                'quantity' => (string) $item['quantity'],
                'unit' => (string) $item['unit'],
                'unit_amount_minor' => (int) $item['unit_amount_minor'],
                'line_amount_minor' => (int) $item['line_amount_minor'],
                'currency' => (string) $item['currency'],
                'modifiers' => $modifiers,
                'tax' => $tax,
            ];
        }

        $details = json_decode((string) $row['details_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new RuntimeException('Stored sales order details are invalid.');
        }

        return new SalesOrder(
            id: (string) $row['id'],
            applicationId: (int) $row['application_id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            profile: (string) $row['profile'],
            status: (string) $row['status'],
            version: (int) $row['version'],
            subtotal: new Money((int) $row['subtotal_minor'], (string) $row['currency']),
            tax: new Money((int) $row['tax_minor'], (string) $row['currency']),
            total: new Money((int) $row['total_minor'], (string) $row['currency']),
            lines: $lines,
            details: $details,
            publicReference: $row['public_reference'] === null ? null : (string) $row['public_reference'],
        );
    }

    public function findByPublicReference(SalesContext $context, string $reference): ?SalesOrder
    {
        $query = SalesOrderRecord::query()
            ->where('application_id', '=', $context->applicationId)
            ->where('public_reference', '=', $reference);
        if ($context->tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', '=', $context->tenantId);
        }
        $row = $query->first();

        return $row === null ? null : $this->find($context, (string) $row->id);
    }

    public function bumpFinancialVersion(SalesContext $context, SalesOrder $order): void
    {
        $query = SalesOrderRecord::query()
            ->where('id', '=', (int) $order->id)
            ->where('application_id', '=', $context->applicationId)
            ->where('version', '=', $order->version);
        if ($context->tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', '=', $context->tenantId);
        }
        $changed = $query->update([
            'version' => $order->version + 1,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($changed !== 1) {
            throw new SalesCartConflictException('Sales order changed; reload it and retry.');
        }
    }

    public function findPublicOrder(
        string $applicationCode,
        string $referenceHash,
        string $email,
        string $phone,
    ): ?SalesOrder {
        $row = $this->connection->selectOne(
            'SELECT o.id, o.application_id, o.details_json FROM sales_orders o
             INNER JOIN sales_applications a ON a.id = o.application_id
             WHERE o.public_reference_hash = ? AND a.code = ? AND a.status = ?
               AND a.tenant_id IS NULL AND o.tenant_id IS NULL',
            [$referenceHash, $applicationCode, 'active']
        );
        if ($row === null) {
            return null;
        }

        $details = json_decode((string) $row['details_json'], true, 64, JSON_THROW_ON_ERROR);
        $checkout = is_array($details['checkout'] ?? null) ? $details['checkout'] : [];
        $customer = is_array($checkout['customer'] ?? null) ? $checkout['customer'] : [];
        $storedEmail = strtolower(trim((string) ($customer['email'] ?? '')));
        $storedPhone = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? '')) ?? '';
        $submittedPhone = preg_replace('/\D+/', '', $phone) ?? '';
        if ($storedEmail === '' || !hash_equals($storedEmail, strtolower(trim($email)))
            || $storedPhone === '' || !hash_equals($storedPhone, $submittedPhone)) {
            return null;
        }

        return $this->find(
            new SalesContext((int) $row['application_id'], scopes: ['orders:read']),
            (string) $row['id'],
        );
    }

    private function newPublicReference(): string
    {
        return 'CMD-' . strtoupper(bin2hex(random_bytes(8)));
    }

    /**
     * @param array<string, string> $filters
     */
    private function dashboardQuery(string $status, array $filters): QueryBuilder
    {
        $query = SalesOrderRecord::table(SalesOrderRecord::getTable() . ' as o')
            ->join(SalesApplicationRecord::getTable() . ' as a', 'a.id', '=', 'o.application_id')
            ->whereNull('o.tenant_id')
            ->whereNull('a.tenant_id')
            ->where('a.status', '=', 'active');

        if ($status !== 'all') {
            if (!SalesOrderState::isKnown($status)) {
                throw new \InvalidArgumentException('Sales dashboard status filter is invalid.');
            }
            $query->where('o.status', '=', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (QueryBuilder $searchQuery) use ($search): void {
                $searchQuery->whereLike('o.public_reference', $search)
                    ->orWhereLike('a.name', $search)
                    ->orWhereLike('o.details_json', $search)
                    ->orWhereExists(static function (QueryBuilder $itemQuery) use ($search): void {
                        $itemQuery->table(SalesOrderItemRecord::getTable() . ' as si')
                            ->whereColumn('si.order_id', '=', 'o.id')
                            ->whereColumn('si.application_id', '=', 'o.application_id')
                            ->whereNull('si.tenant_id')
                            ->whereLike('si.label_snapshot', $search);
                    });
                if (ctype_digit($search)) {
                    $searchQuery->orWhereLike('o.id', $search);
                }
            });
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') {
                $query->where(
                    'o.created_at',
                    $operator,
                    $filters[$key] . ($key === 'date_to' ? ' 23:59:59' : ' 00:00:00'),
                );
            }
        }

        foreach (['min_total_minor' => '>=', 'max_total_minor' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') {
                $query->where('o.total_minor', $operator, (int) $filters[$key]);
            }
        }
        foreach (['min_pieces' => '>=', 'max_pieces' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') {
                $query->whereRaw(
                    '(SELECT COALESCE(SUM(si.quantity), 0) FROM sales_order_items si
                     WHERE si.order_id = o.id AND si.application_id = o.application_id
                       AND si.tenant_id IS NULL) ' . $operator . ' ?',
                    [(float) $filters[$key]],
                );
            }
        }

        return $query;
    }

    /** @return list<int> */
    private function activeOrderApplicationIds(): array
    {
        $modules = SalesApplicationModuleRecord::query()
            ->where('module_key', '=', 'orders')
            ->where('status', '=', 'enabled')
            ->whereNull('tenant_id')
            ->select('id', 'application_id')
            ->get();

        return array_values(array_unique(array_map(
            static fn(SalesApplicationModuleRecord $module): int => (int) $module->getAttribute('application_id'),
            $modules
        )));
    }

    public function transition(
        SalesContext $context,
        SalesOrder $order,
        string $newStatus,
        string $reason,
    ): SalesOrder {
        $now = gmdate('Y-m-d H:i:s');
        $scopeSql = $context->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $bindings = [$newStatus, $order->version + 1, $now, $order->id, $context->applicationId];
        if ($context->tenantId !== null) {
            $bindings[] = $context->tenantId;
        }
        $bindings[] = $order->version;
        $bindings[] = $order->status;
        $changed = $this->connection->update(
            "UPDATE sales_orders SET status = ?, version = ?, updated_at = ? WHERE id = ? AND application_id = ? AND {$scopeSql} AND version = ? AND status = ?",
            $bindings
        );
        if ($changed !== 1) {
            throw new SalesCartConflictException('Sales order changed; reload it and retry.');
        }

        $this->connection->insert(
            'INSERT INTO sales_order_transitions (application_id, tenant_id, order_id, from_status, to_status, actor_ref, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                (int) $order->id,
                $order->status,
                $newStatus,
                $context->actorReference,
                $reason === '' ? null : $reason,
                $now,
            ]
        );
        $this->carts->appendOutboxEvent($context, 'OrderTransitioned', $order->id, [
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $newStatus,
            'version' => $order->version + 1,
        ], 'order');

        return $this->find($context, $order->id)
            ?? throw new RuntimeException('Sales order could not be reloaded after transition.');
    }

}
