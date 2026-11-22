<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Controllers;

use NM\Services\InventoryService\Inventory\InventoryFacade;
use NM\Services\InventoryService\Inventory\InventoryCatalogService;
use NM\Services\InventoryService\Inventory\InventoryConfigurationStore;
use NM\Services\InventoryService\Inventory\InventoryConfig;
use NM\Services\InventoryService\Inventory\InventoryMovement;
use NM\Services\InventoryService\Inventory\InventoryQuantity;
use App\Helpers\NavbarFactory;
use App\Models\User;
use Core\Database\Connection;
use Core\Exceptions\InputErrorException;
use Core\Exceptions\Http\GateException;
use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use InvalidArgumentException;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryFacade $facade,
        private readonly InventoryConfig $config,
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
        private readonly Connection $connection,
        private readonly InventoryCatalogService $inventoryCatalog,
        private readonly InventoryConfigurationStore $configurationStore,
    ) {
    }

    public function view(): Response
    {
        $user = $this->requireUser();
        $canViewStock = $user->isSuper() || $user->hasPermission('inventory.stock.view');
        if (!$user->isSuper() && !array_filter([
            'inventory.stock.view',
            'inventory.catalog.view',
            'inventory.catalog.manage',
            'inventory.warehouse.view',
            'inventory.warehouse.manage',
            'inventory.stock.receive',
            'inventory.stock.issue',
            'inventory.stock.transfer',
            'inventory.stock.adjust',
            'inventory.stock.reserve',
            'inventory.stock.count',
            'inventory.discount.view',
            'inventory.discount.manage',
        ], static fn(string $permission): bool => $user->hasPermission($permission))) {
            throw new GateException();
        }
        $csrf = new CsrfTokenManager($this->session, self::TOKEN_NAME);

        $items = $this->connection->select(
            'SELECT i.id, v.sku, i.reference_unit_id AS unit_id, i.tracking_mode, p.name AS product_name, u.code AS unit_code
             FROM inventory_items i
             INNER JOIN catalog_variants v ON v.id = i.variant_id
             INNER JOIN catalog_products p ON p.id = v.product_id
             INNER JOIN inventory_units u ON u.id = i.reference_unit_id
             WHERE i.status = ? AND i.archived_at IS NULL
               AND p.status = ? AND p.kind = ? AND v.status = ?
             ORDER BY p.name, v.sku',
            ['active', 'active', 'goods', 'active']
        );
        $availableVariants = $this->connection->select(
            'SELECT v.id, v.sku, v.label, p.name AS product_name
             FROM catalog_variants v
             INNER JOIN catalog_products p ON p.id = v.product_id
             LEFT JOIN inventory_items i ON i.variant_id = v.id
             WHERE i.id IS NULL AND v.status = ? AND p.status = ? AND p.kind = ?
             ORDER BY p.name, v.sku',
            ['active', 'active', 'goods']
        );
        $units = $this->connection->select(
            'SELECT id, code, name FROM inventory_units ORDER BY name, code'
        );
        $locations = $this->connection->select(
            'SELECT l.id, l.code, l.name, w.name AS warehouse_name
             FROM inventory_locations l
             INNER JOIN inventory_warehouses w ON w.id = l.warehouse_id
             WHERE l.is_active = 1 AND l.is_available = 1 AND w.is_active = 1
             ORDER BY w.name, l.code'
        );
        $warehouses = $this->connection->select(
            'SELECT w.code, w.name, l.code AS default_location_code, l.name AS default_location_name
             FROM inventory_warehouses w
             LEFT JOIN inventory_locations l ON l.id = w.default_location_id
             WHERE w.is_active = 1
             ORDER BY w.name'
        );
        $lots = $this->connection->select(
            'SELECT id, item_id, lot_code, is_sentinel FROM inventory_item_lots WHERE status = ? ORDER BY lot_code',
            ['available']
        );
        $availableLotsByItem = [];
        foreach ($lots as $lot) {
            if (!(bool) $lot['is_sentinel']) {
                $availableLotsByItem[(int) $lot['item_id']] = true;
            }
        }
        $defaultLotsByItem = [];
        foreach ($this->connection->select(
            'SELECT item_id FROM inventory_item_lots WHERE is_sentinel = 1'
        ) as $sentinelLot) {
            $defaultLotsByItem[(int) $sentinelLot['item_id']] = true;
        }
        $setupSteps = [];
        $itemsMissingDefaultLot = [];
        if ($units === []) {
            $setupSteps[] = [
                'message' => 'Ajoutez une unité de mesure (par exemple PCS / Pièce).',
                'target' => 'inventory-unit-setup',
                'permission' => 'catalog',
            ];
        }
        if ($items === []) {
            $setupSteps[] = [
                'message' => $availableVariants === []
                    ? 'Ajoutez d’abord un produit physique actif dans le catalogue.'
                    : 'Reliez ce produit physique du catalogue à l’inventaire.',
                'target' => $availableVariants === [] ? 'catalog' : 'inventory-item-setup',
                'permission' => 'catalog',
            ];
        }
        if ($locations === []) {
            $setupSteps[] = [
                'message' => 'Créez un entrepôt et son premier emplacement de stockage.',
                'target' => 'inventory-warehouse-setup',
                'permission' => 'warehouse',
            ];
        }
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            if ($item['tracking_mode'] === 'lot' && !isset($availableLotsByItem[$itemId])) {
                $setupSteps[] = [
                    'message' => sprintf('Ajoutez un lot pour %s — %s.', $item['sku'], $item['product_name']),
                    'target' => 'inventory-lot-setup',
                    'permission' => 'catalog',
                ];
            } elseif ($item['tracking_mode'] === 'none' && !isset($defaultLotsByItem[$itemId])) {
                $setupSteps[] = [
                    'message' => sprintf('Le lot technique DEFAULT manque pour %s.', $item['sku']),
                    'target' => 'inventory-lot-setup',
                    'permission' => 'catalog',
                ];
                $itemsMissingDefaultLot[$itemId] = true;
            }
        }
        $lotFormItems = array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['tracking_mode'] === 'lot'
                || isset($itemsMissingDefaultLot[(int) $item['id']])
        ));
        $balances = $canViewStock ? $this->connection->select(
            'SELECT i.id AS item_id, i.reference_unit_id AS unit_id, b.location_id, b.lot_id,
                    v.sku, p.name AS product_name, l.code AS location_code, w.name AS warehouse_name,
                    lot.lot_code, b.physical_qty, b.reserved_qty
             FROM inventory_stock_balances b
             INNER JOIN inventory_items i ON i.id = b.item_id
             INNER JOIN catalog_variants v ON v.id = i.variant_id
             INNER JOIN catalog_products p ON p.id = v.product_id
             INNER JOIN inventory_locations l ON l.id = b.location_id
             INNER JOIN inventory_warehouses w ON w.id = l.warehouse_id
             INNER JOIN inventory_item_lots lot ON lot.id = b.lot_id
             ORDER BY p.name, v.sku, w.name, l.code, lot.lot_code'
        ) : [];
        $productStocks = $canViewStock ? $this->connection->select(
            'SELECT i.id AS item_id, i.reference_unit_id AS unit_id, i.min_qty, v.id AS variant_id,
                    p.id AS product_id, v.price, v.currency, v.sku, p.name AS product_name,
                    u.code AS unit_code, COALESCE(b.physical_qty, 0) AS physical_qty,
                    COALESCE(b.reserved_qty, 0) AS reserved_qty
             FROM inventory_items i
             INNER JOIN catalog_variants v ON v.id = i.variant_id
             INNER JOIN catalog_products p ON p.id = v.product_id
             INNER JOIN inventory_units u ON u.id = i.reference_unit_id
             LEFT JOIN (
                SELECT item_id, SUM(physical_qty) AS physical_qty, SUM(reserved_qty) AS reserved_qty
                FROM inventory_stock_balances
                GROUP BY item_id
             ) b ON b.item_id = i.id
             WHERE i.status = ? AND i.archived_at IS NULL
               AND p.status = ? AND p.kind = ? AND v.status = ?
             ORDER BY p.name, v.sku',
            ['active', 'active', 'goods', 'active']
        ) : [];
        $inventorySettings = $this->configurationStore->viewValues($this->config);
        $simpleDashboardEnabled = $inventorySettings['simple_dashboard_enabled'];
        $defaultMinQty = $inventorySettings['default_min_qty'];
        $defaultMovementQuantity = $inventorySettings['default_movement_quantity'];
        $entries = $canViewStock ? $this->connection->select(
            'SELECT e.movement_type, e.quantity, e.operational_at, e.reason, v.sku, p.name AS product_name,
                    l.code AS location_code, lot.lot_code
             FROM inventory_stock_ledger_entries e
             INNER JOIN inventory_items i ON i.id = e.item_id
             INNER JOIN catalog_variants v ON v.id = i.variant_id
             INNER JOIN catalog_products p ON p.id = v.product_id
             INNER JOIN inventory_locations l ON l.id = e.location_id
             INNER JOIN inventory_item_lots lot ON lot.id = e.lot_id
             ORDER BY e.id DESC LIMIT 20'
        ) : [];
        $canManageDiscounts = $user->isSuper() || $user->hasPermission('inventory.discount.manage');
        $canViewDiscounts = $user->isSuper()
            || $user->hasPermission('inventory.discount.view')
            || $canManageDiscounts;
        $discounts = $canViewDiscounts ? $this->connection->select(
            'SELECT id, target_type, target_id, discount_type, value, currency, starts_at, ends_at, is_active
             FROM discounts ORDER BY created_at DESC, id DESC'
        ) : [];
        $discountTargets = $canManageDiscounts ? [
            'brand' => $this->connection->select('SELECT id, name AS label FROM catalog_brands ORDER BY name'),
            'category' => $this->connection->select('SELECT id, name AS label FROM catalog_categories ORDER BY name'),
            'product' => $this->connection->select('SELECT id, name AS label FROM catalog_products ORDER BY name'),
        ] : [];
        $discountProducts = $canViewDiscounts ? $this->connection->select(
            'SELECT v.id AS variant_id, v.sku, v.label AS variant_label, v.price, v.currency,
                    p.name AS product_name
             FROM catalog_variants v
             INNER JOIN catalog_products p ON p.id = v.product_id
             WHERE v.status = ? AND p.status = ? AND v.price IS NOT NULL
             ORDER BY p.name, v.sku',
            ['active', 'active']
        ) : [];

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Stocks et mouvements',
            'csrf_token' => $csrf->generateToken(),
            'user' => $user->toArray(),
            'is_super' => $user->isSuper(),
            'navelements' => NavbarFactory::navbar($user, 'inventory'),
            'admin_permissions' => $user->effectivePermissions('string'),
            'errors' => [],
            'content_name' => 'dashboard.inventory',
            'activeNavTab' => 'inventory',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Inventory']],
            'items' => $items,
            'available_variants' => $availableVariants,
            'units' => $units,
            'locations' => $locations,
            'warehouses' => $warehouses,
            'lots' => $lots,
            'setup_steps' => $setupSteps,
            'lot_form_items' => $lotFormItems,
            'can_view_stock' => $canViewStock,
            'can_view_catalog' => $user->isSuper() || $user->hasPermission('inventory.catalog.view'),
            'can_view_warehouses' => $user->isSuper() || $user->hasPermission('inventory.warehouse.view'),
            'balances' => $balances,
            'productStocks' => $productStocks,
            'simple_dashboard_enabled' => $simpleDashboardEnabled,
            'default_min_qty' => $defaultMinQty,
            'default_movement_quantity' => $defaultMovementQuantity,
            'inventory_settings' => $inventorySettings,
            'entries' => $entries,
            'discounts' => $discounts,
            'discount_targets' => $discountTargets,
            'discount_products' => $discountProducts,
            'can_view_discounts' => $canViewDiscounts,
            'can_manage_discounts' => $canManageDiscounts,
        ]));
    }

    public function saveDiscount(Request $request): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $this->assertPermission($user, 'inventory.discount.manage');
            $payload = $this->normalizePayload($request);
            $id = (int) ($payload['discount_id'] ?? 0);
            unset($payload['discount_id']);
            if (isset($payload['is_active'])) {
                $payload['is_active'] = in_array($payload['is_active'], [true, 1, '1', 'on'], true);
            }
            $discount = $id > 0
                ? $this->facade->discounts()->update($id, $payload)
                : $this->facade->discounts()->create($payload);

            return $this->jsonResponse(['success' => true, 'data' => $discount->toArray(), 'message' => 'Réduction enregistrée.']);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function discountsForTarget(Request $request): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $this->assertPermission($user, 'inventory.discount.manage');
            $targetType = (string) $request->query('target_type', '');
            $targetId = filter_var($request->query('target_id'), FILTER_VALIDATE_INT);
            if ($targetId === false || $targetId < 1) {
                throw new InvalidArgumentException('Une cible valide est requise.');
            }
            $discounts = $this->facade->discounts()->forTarget($targetType, $targetId);

            return $this->jsonResponse([
                'success' => true,
                'data' => array_map(static fn($discount): array => $discount->toArray(), $discounts),
            ]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function setDiscountStatus(Request $request): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $this->assertPermission($user, 'inventory.discount.manage');
            $payload = $this->normalizePayload($request);
            $id = (int) ($payload['discount_id'] ?? 0);
            $action = $payload['action'] ?? null;
            if ($id < 1 || !in_array($action, ['activate', 'deactivate'], true)) {
                throw new InvalidArgumentException('Une réduction et une action valides sont requises.');
            }
            $discount = $action === 'activate'
                ? $this->facade->discounts()->activate($id)
                : $this->facade->discounts()->deactivate($id);

            return $this->jsonResponse([
                'success' => true,
                'data' => $discount->toArray(),
                'message' => $action === 'activate' ? 'Réduction activée.' : 'Réduction désactivée.',
            ]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function calculateDiscount(Request $request): JsonResponse
    {
        try {
            $user = $this->requireUser();
            if (!$user->isSuper()
                && !$user->hasPermission('inventory.discount.view')
                && !$user->hasPermission('inventory.discount.manage')) {
                throw new GateException();
            }
            $payload = $this->normalizePayload($request);
            $variantId = (int) ($payload['variant_id'] ?? 0);
            if ($variantId < 1) {
                throw new InvalidArgumentException('Une déclinaison valide est requise.');
            }
            $result = $this->facade->discounts()->calculateForVariant($variantId);

            return $this->jsonResponse(['success' => true, 'data' => $result->toArray()]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function data(): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $canViewStock = $user->isSuper() || $user->hasPermission('inventory.stock.view');
            $canManageMovements = $user->isSuper()
                || $user->hasPermission('inventory.stock.receive')
                || $user->hasPermission('inventory.stock.issue');
            if (!$canViewStock && !$canManageMovements) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => ['balances' => [], 'productStocks' => [], 'locations' => [], 'lots' => []],
                ]);
            }

            $balances = $canViewStock ? $this->connection->select(
                'SELECT i.id AS item_id, i.reference_unit_id AS unit_id, b.location_id, b.lot_id,
                        v.sku, p.name AS product_name, l.code AS location_code, w.name AS warehouse_name,
                        lot.lot_code, b.physical_qty, b.reserved_qty
                 FROM inventory_stock_balances b
                 INNER JOIN inventory_items i ON i.id = b.item_id
                 INNER JOIN catalog_variants v ON v.id = i.variant_id
                 INNER JOIN catalog_products p ON p.id = v.product_id
                 INNER JOIN inventory_locations l ON l.id = b.location_id
                 INNER JOIN inventory_warehouses w ON w.id = l.warehouse_id
                 INNER JOIN inventory_item_lots lot ON lot.id = b.lot_id
                 ORDER BY p.name, v.sku, w.name, l.code, lot.lot_code'
            ) : [];
            $productStocks = $canViewStock ? $this->connection->select(
                'SELECT i.id AS item_id, i.reference_unit_id AS unit_id, i.min_qty, v.id AS variant_id,
                        p.id AS product_id, v.price, v.currency, v.sku, p.name AS product_name,
                        u.code AS unit_code, COALESCE(b.physical_qty, 0) AS physical_qty,
                        COALESCE(b.reserved_qty, 0) AS reserved_qty
                 FROM inventory_items i
                 INNER JOIN catalog_variants v ON v.id = i.variant_id
                 INNER JOIN catalog_products p ON p.id = v.product_id
                 INNER JOIN inventory_units u ON u.id = i.reference_unit_id
                 LEFT JOIN (
                    SELECT item_id, SUM(physical_qty) AS physical_qty, SUM(reserved_qty) AS reserved_qty
                    FROM inventory_stock_balances
                    GROUP BY item_id
                 ) b ON b.item_id = i.id
                 WHERE i.status = ? AND i.archived_at IS NULL
                   AND p.status = ? AND p.kind = ? AND v.status = ?
                 ORDER BY p.name, v.sku',
                ['active', 'active', 'goods', 'active']
            ) : $this->connection->select(
                'SELECT i.id AS item_id, i.reference_unit_id AS unit_id, v.id AS variant_id,
                        u.code AS unit_code
                 FROM inventory_items i
                 INNER JOIN catalog_variants v ON v.id = i.variant_id
                 INNER JOIN catalog_products p ON p.id = v.product_id
                 INNER JOIN inventory_units u ON u.id = i.reference_unit_id
                 WHERE i.status = ? AND i.archived_at IS NULL
                   AND p.status = ? AND p.kind = ? AND v.status = ?
                 ORDER BY p.name, v.sku',
                ['active', 'active', 'goods', 'active']
            );
            $locations = $canManageMovements ? $this->connection->select(
                'SELECT l.id, l.code, l.name, w.name AS warehouse_name
                 FROM inventory_locations l
                 INNER JOIN inventory_warehouses w ON w.id = l.warehouse_id
                 WHERE l.is_active = 1 AND l.is_available = 1 AND w.is_active = 1
                 ORDER BY w.name, l.code'
            ) : [];
            $lots = $canManageMovements ? $this->connection->select(
                'SELECT id, item_id, lot_code FROM inventory_item_lots
                 WHERE status = ? ORDER BY lot_code',
                ['available']
            ) : [];
            $defaultMovementQuantity = '1';
            if ($canManageMovements) {
                $setting = $this->connection->selectOne(
                    'SELECT value FROM settings WHERE `key` = ? LIMIT 1',
                    ['inventory.default_movement_quantity']
                );
                $defaultMovementQuantityValue = InventoryQuantity::from(
                    (string) ($setting['value'] ?? $defaultMovementQuantity)
                );
                if (!$defaultMovementQuantityValue->isPositive()) {
                    throw new InvalidArgumentException('Le paramètre Inventory de quantité par défaut est invalide.');
                }
                $defaultMovementQuantity = $defaultMovementQuantityValue->toString();
            }
            foreach ($balances as &$balance) {
                $balance['available_qty'] = \NM\Services\InventoryService\Inventory\InventoryQuantity::from((string) $balance['physical_qty'])
                    ->subtract(\NM\Services\InventoryService\Inventory\InventoryQuantity::from((string) $balance['reserved_qty']))
                    ->toString();
            }
            unset($balance);
            if ($canViewStock) {
                foreach ($productStocks as &$productStock) {
                    $productStock['available_qty'] = \NM\Services\InventoryService\Inventory\InventoryQuantity::from((string) $productStock['physical_qty'])
                        ->subtract(\NM\Services\InventoryService\Inventory\InventoryQuantity::from((string) $productStock['reserved_qty']))
                        ->toString();
                }
                unset($productStock);
            }

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'balances' => $balances,
                    'productStocks' => $productStocks,
                    'locations' => $locations,
                    'lots' => $lots,
                    'defaultMovementQuantity' => $defaultMovementQuantity,
                ],
            ]);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function movement(Request $request): JsonResponse
    {
        try {
            $payload = $this->normalizePayload($request);
            $permission = match ((string) ($payload['movement_type'] ?? '')) {
                'receipt' => 'inventory.stock.receive',
                'issue' => 'inventory.stock.issue',
                'transfer_in', 'transfer_out' => 'inventory.stock.transfer',
                default => 'inventory.stock.adjust',
            };
            $user = $this->requireUser();
            $this->assertPermission($user, $permission);
            $actorId = (int) $user->getAuthIdentifier();

            $movement = new InventoryMovement(
                movementType: (string) ($payload['movement_type'] ?? ''),
                itemId: (int) ($payload['item_id'] ?? 0),
                locationId: (int) ($payload['location_id'] ?? 0),
                lotId: (int) ($payload['lot_id'] ?? 0),
                quantity: (string) ($payload['quantity'] ?? ''),
                context: [
                    'reference' => $payload['reference'] ?? null,
                    'reason' => $payload['reason'] ?? null,
                    'unit_id' => isset($payload['unit_id']) ? (int) $payload['unit_id'] : null,
                    'destination_location_id' => isset($payload['destination_location_id']) ? (int) $payload['destination_location_id'] : null,
                    'serial_id' => isset($payload['serial_id']) ? (int) $payload['serial_id'] : null,
                    'created_by' => $actorId,
                    'validated_by' => $actorId,
                    'idempotency_key' => $payload['idempotency_key'] ?? null,
                    'external_module' => $payload['external_module'] ?? null,
                    'external_document_type' => $payload['external_document_type'] ?? null,
                    'external_document_id' => $payload['external_document_id'] ?? null,
                    'actor_user_id' => $actorId,
                    'group_id' => $payload['group_id'] ?? null,
                ]
            );

            return $this->jsonResponse([
                'success' => true,
                'data' => $this->facade->applyMovement($movement),
            ]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function saveConfiguration(Request $request): Response
    {
        try {
            $user = $this->requireUser();
            if (!$user->isSuper()
                && !$user->hasPermission('inventory.catalog.manage')
                && !$user->hasPermission('inventory.warehouse.manage')) {
                throw new GateException();
            }

            $payload = $this->normalizePayload($request);
            $returnTo = ($payload['return_to'] ?? null) === 'services' ? 'services' : 'inventory';
            unset($payload['csrf_token'], $payload['return_to']);
            $this->configurationStore->save($payload, $this->config);

            if (!$request->isJson()) {
                return Response::redirect(
                    $returnTo === 'services' ? '/dashboard/settings/services' : '/dashboard/inventory'
                );
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => 'La configuration du service Produits et stocks a été enregistrée.',
            ]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function reserve(Request $request): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $this->assertPermission($user, 'inventory.stock.reserve');
            $payload = $this->normalizePayload($request);

            $reservationId = $this->facade->reserve(
                itemId: (int) ($payload['item_id'] ?? 0),
                locationId: (int) ($payload['location_id'] ?? 0),
                lotId: (int) ($payload['lot_id'] ?? 0),
                quantity: (string) ($payload['quantity'] ?? ''),
                reservationKey: (string) ($payload['reservation_key'] ?? 'inventory:' . uniqid('', true)),
                createdBy: (int) $user->getAuthIdentifier(),
            );

            return $this->jsonResponse([
                'success' => true,
                'data' => ['reservation_id' => $reservationId],
            ]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function startCount(Request $request): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $this->assertPermission($user, 'inventory.stock.count');
            $payload = $this->normalizePayload($request);

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'count_operation_id' => $this->facade->startCount(
                        (int) ($payload['item_id'] ?? 0),
                        (int) ($payload['location_id'] ?? 0),
                        (int) ($payload['lot_id'] ?? 0),
                        (string) ($payload['snapshot_qty'] ?? '0'),
                        (int) $user->getAuthIdentifier(),
                    ),
                ],
            ]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function reconcileCount(Request $request): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $this->assertPermission($user, 'inventory.stock.count');
            $payload = $this->normalizePayload($request);

            $this->facade->reconcileCount(
                (int) ($payload['count_operation_id'] ?? 0),
                (int) ($payload['item_id'] ?? 0),
                (int) ($payload['location_id'] ?? 0),
                (int) ($payload['lot_id'] ?? 0),
                (string) ($payload['final_counted_qty'] ?? '0'),
                (int) $user->getAuthIdentifier(),
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Inventory count reconciled successfully.',
            ]);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function releaseReservation(Request $request): JsonResponse
    {
        return $this->updateReservation($request, 'release');
    }

    public function consumeReservation(Request $request): JsonResponse
    {
        return $this->updateReservation($request, 'consume');
    }

    public function createUnit(Request $request): JsonResponse
    {
        return $this->catalogMutation($request, 'inventory.catalog.manage', function (array $payload): array {
            $code = strtoupper(trim((string) ($payload['code'] ?? '')));
            $name = trim((string) ($payload['name'] ?? ''));
            if (!preg_match('/^[A-Z0-9_-]{1,40}$/', $code) || $name === '' || strlen($name) > 100) {
                throw new InvalidArgumentException('Le code et le nom de l’unité sont obligatoires et doivent être valides.');
            }
            if ($this->connection->selectOne('SELECT id FROM inventory_units WHERE UPPER(code) = ? LIMIT 1', [$code]) !== null) {
                throw new InvalidArgumentException('Ce code unité existe déjà.');
            }

            $this->connection->statement(
                'INSERT INTO inventory_units (code, name, precision_scale, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$code, $name, 6]
            );

            return ['unit_id' => (int) $this->connection->lastInsertId()];
        });
    }

    public function createItem(Request $request): JsonResponse
    {
        return $this->catalogMutation($request, 'inventory.catalog.manage', function (array $payload): array {
            $variantId = (int) ($payload['variant_id'] ?? 0);
            $unitId = (int) ($payload['unit_id'] ?? 0);
            $trackingMode = (string) ($payload['tracking_mode'] ?? 'none');

            if ($variantId <= 0 || $unitId <= 0) {
                throw new InvalidArgumentException('Le produit ou l’unité sélectionnée est invalide.');
            }
            if ($this->connection->selectOne('SELECT id FROM inventory_items WHERE variant_id = ? LIMIT 1', [$variantId]) !== null) {
                throw new InvalidArgumentException('Le suivi du stock est déjà configuré pour ce produit.');
            }
            $itemId = $this->inventoryCatalog->createForVariant(
                $variantId,
                $unitId,
                false,
                $trackingMode,
                !empty($payload['allow_negative_stock'])
            );

            return ['item_id' => $itemId];
        });
    }

    public function createWarehouse(Request $request): JsonResponse
    {
        return $this->catalogMutation($request, 'inventory.warehouse.manage', function (array $payload): array {
            $code = strtoupper(trim((string) ($payload['code'] ?? '')));
            $name = trim((string) ($payload['name'] ?? ''));
            $locationCode = strtoupper(trim((string) ($payload['location_code'] ?? '')));
            $locationName = trim((string) ($payload['location_name'] ?? ''));
            if (!preg_match('/^[A-Z0-9_-]{1,80}$/', $code)
                || !preg_match('/^[A-Z0-9_-]{1,100}$/', $locationCode)
                || $name === '' || strlen($name) > 190
                || $locationName === '' || strlen($locationName) > 190) {
                throw new InvalidArgumentException('Les codes et noms de l’entrepôt et de son emplacement initial sont requis.');
            }
            if ($this->connection->selectOne('SELECT id FROM inventory_warehouses WHERE UPPER(code) = ? LIMIT 1', [$code]) !== null) {
                throw new InvalidArgumentException('Ce code entrepôt est déjà utilisé.');
            }
            if (!$this->config->multiWarehouse) {
                $warehouseCount = (int) ($this->connection->selectOne(
                    'SELECT COUNT(*) AS total FROM inventory_warehouses WHERE is_active = 1'
                )['total'] ?? 0);
                if ($warehouseCount > 0) {
                    throw new InvalidArgumentException('La création d’un entrepôt supplémentaire est désactivée.');
                }
            }

            $this->connection->statement(
                'INSERT INTO inventory_warehouses (code, name, default_location_id, is_active, created_at, updated_at) VALUES (?, ?, NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$code, $name]
            );
            $warehouseId = (int) $this->connection->lastInsertId();
            $this->connection->statement(
                'INSERT INTO inventory_locations (warehouse_id, parent_id, code, name, location_type, is_available, is_active, created_at, updated_at)
                 VALUES (?, NULL, ?, ?, ?, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$warehouseId, $locationCode, $locationName, 'storage']
            );
            $locationId = (int) $this->connection->lastInsertId();
            $this->connection->statement(
                'UPDATE inventory_warehouses SET default_location_id = ? WHERE id = ?',
                [$locationId, $warehouseId]
            );

            return ['warehouse_id' => $warehouseId, 'location_id' => $locationId];
        });
    }

    public function createLot(Request $request): JsonResponse
    {
        return $this->catalogMutation($request, 'inventory.catalog.manage', function (array $payload): array {
            $itemId = (int) ($payload['item_id'] ?? 0);
            $lotCode = trim((string) ($payload['lot_code'] ?? ''));
            if ($itemId <= 0 || $lotCode === '' || strlen($lotCode) > 120) {
                throw new InvalidArgumentException('Un article et un code de lot valides sont requis.');
            }
            $item = $this->connection->selectOne(
                'SELECT i.id, i.tracking_mode FROM inventory_items i
                 INNER JOIN catalog_variants v ON v.id = i.variant_id
                 INNER JOIN catalog_products p ON p.id = v.product_id
                 WHERE i.id = ? AND i.status = ? AND i.archived_at IS NULL
                   AND v.status = ? AND p.status = ? AND p.kind = ? LIMIT 1',
                [$itemId, 'active', 'active', 'active', 'goods']
            );
            if ($item === null || !in_array($item['tracking_mode'], ['lot', 'none'], true)) {
                throw new InvalidArgumentException('L’article sélectionné est introuvable ou ne peut pas être suivi par lot.');
            }
            $isSentinel = $item['tracking_mode'] === 'none';
            if ($isSentinel) {
                if (strtoupper($lotCode) !== 'DEFAULT') {
                    throw new InvalidArgumentException('Pour un article sans traçabilité, seul le lot technique DEFAULT peut être créé.');
                }
                $lotCode = 'DEFAULT';
            }
            if ($this->connection->selectOne(
                'SELECT id FROM inventory_item_lots WHERE item_id = ? AND UPPER(lot_code) = UPPER(?) LIMIT 1',
                [$itemId, $lotCode]
            ) !== null) {
                throw new InvalidArgumentException('Ce code de lot existe déjà pour cet article.');
            }

            $this->connection->statement(
                'INSERT INTO inventory_item_lots (item_id, lot_code, is_sentinel, status, manufactured_at, expires_at, source_reference, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NULL, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$itemId, $lotCode, $isSentinel ? 1 : 0, 'available']
            );

            return ['lot_id' => (int) $this->connection->lastInsertId()];
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $action
     */
    private function catalogMutation(Request $request, string $permission, callable $action): JsonResponse
    {
        try {
            $this->assertPermission($this->requireUser(), $permission);
            $payload = $this->normalizePayload($request);
            $data = $this->facade->runInTransaction(static fn() => $action($payload));

            return $this->jsonResponse(['success' => true, 'data' => $data]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    private function updateReservation(Request $request, string $action): JsonResponse
    {
        try {
            $user = $this->requireUser();
            $this->assertPermission($user, 'inventory.stock.reserve');
            $payload = $this->normalizePayload($request);
            $reservationId = (int) ($payload['reservation_id'] ?? 0);
            $quantity = (string) ($payload['quantity'] ?? '');

            if ($action === 'release') {
                $this->facade->releaseReservation($reservationId, $quantity);
            } else {
                $this->facade->consumeReservation($reservationId, $quantity, (int) $user->getAuthIdentifier());
            }

            return $this->jsonResponse(['success' => true, 'message' => 'Réservation mise à jour.']);
        } catch (InputErrorException | InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    private function requireUser(): User
    {
        if (!$this->config->enabled) {
            throw new GateException('Le module Inventory est désactivé.');
        }

        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if ($user === null) {
            throw new GateException();
        }

        return $user;
    }

    private function assertPermission(User $user, string $permission): void
    {
        if (!$user->isSuper() && !$user->hasPermission($permission)) {
            throw new GateException();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(Request $request): array
    {
        $json = $request->json();
        if (is_array($json)) {
            return $json;
        }

        $data = $request->all();
        if (is_array($data)) {
            return $data;
        }

        return [];
    }
}
