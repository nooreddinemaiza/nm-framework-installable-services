<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Catalog;

use NM\Services\InventoryService\Catalog\Contracts\ProductReferenceInterface;
use NM\Services\InventoryService\Inventory\InventoryCatalogService;
use NM\Services\InventoryService\Models\CatalogBrand;
use NM\Services\InventoryService\Models\CatalogCategory;
use NM\Services\InventoryService\Models\CatalogProduct;
use NM\Services\InventoryService\Models\CatalogVariant;
use App\Models\User;
use NM\Services\InventoryService\Support\RichTextSanitizer;
use Core\Database\Connection;
use Core\Database\Paginator;
use Core\Database\QueryBuilder;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Exceptions\InternalServerException;
use Core\Support\Data\Data;
use Core\Support\Str;

final class CatalogService implements ProductReferenceInterface
{
    private const PUBLIC_TRENDING_LIMIT = 8;

    public function __construct(
        private readonly Connection $connection,
        private readonly ProductUsageRegistry $usageRegistry,
        private readonly InventoryCatalogService $inventoryCatalog,
        private readonly CatalogMediaService $catalogMedia,
        private readonly string $defaultStatus = 'active',
        private readonly int $categoryMaxDepth = 10,
        private readonly string $skuPrefix = 'CAT',
        private readonly int $skuPadding = 8,
        private readonly string $defaultCurrency = 'MAD',
    ) {
        if (!in_array($defaultStatus, ['draft', 'active'], true)) {
            throw new InternalServerException('Le statut par défaut du catalogue est invalide.');
        }
        if ($categoryMaxDepth < 1 || $categoryMaxDepth > 100) {
            throw new InternalServerException('La profondeur maximale des catégories doit être comprise entre 1 et 100.');
        }
        if (preg_match('/^[A-Z0-9_-]{1,20}$/', $skuPrefix) !== 1 || $skuPadding < 1 || $skuPadding > 20) {
            throw new InternalServerException('Le format des références automatiques est invalide.');
        }
        if (preg_match('/^[A-Z]{3}$/D', $defaultCurrency) !== 1) {
            throw new InternalServerException('La devise par défaut du catalogue est invalide.');
        }
    }

    public function defaultStatus(): string
    {
        return $this->defaultStatus;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createProduct(array $data, User $actor): int
    {
        $this->authorize($actor, 'catalog.product.create');
        $product = $this->normalizeProduct($data, creating: true);
        $stockUnitId = $this->stockUnitId($data['stock_unit_id'] ?? null);
        if ($product['status'] === 'archived') {
            throw $this->invalidInput('status', 'Un nouveau produit ne peut pas être créé directement comme retiré du catalogue.');
        }
        if ($product['creation_token'] !== null) {
            $existing = CatalogProduct::query()
                ->where('creation_token', '=', $product['creation_token'])
                ->select('id')
                ->first();
            if ($existing !== null) {
                if ($product['kind'] === 'goods') {
                    $this->connection->transaction(function () use ($existing, $stockUnitId): void {
                        $this->ensureStockItem((int) $existing->id, $stockUnitId);
                    });
                }
                return (int) $existing->id;
            }
        }

        $this->assertSlugAvailable('catalog_products', $product['slug']);
        return $this->connection->transaction(function () use ($product, $stockUnitId): int {
            $now = date('Y-m-d H:i:s');
            $productId = CatalogProduct::query()->insertGetId([
                'category_id' => $product['category_id'],
                'brand_id' => $product['brand_id'],
                'name' => $product['name'],
                'description' => $product['description'],
                'seo_tags' => $product['seo_tags'],
                'kind' => $product['kind'],
                'status' => $product['status'],
                'manufacturer' => $product['manufacturer'],
                'is_trending' => $product['is_trending'],
                'slug' => $product['slug'],
                'creation_token' => $product['creation_token'],
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sku = $product['sku'] !== ''
                ? $product['sku']
                : $this->skuPrefix . '-' . str_pad((string) $productId, $this->skuPadding, '0', STR_PAD_LEFT);
            $this->assertSkuAvailable($sku);
            $variantId = CatalogVariant::query()->insertGetId([
                'product_id' => $productId,
                'sku' => $sku,
                'price' => $product['price'],
                'cost_price' => $product['cost_price'],
                'currency' => $product['currency'],
                'is_default' => 1,
                'combination_hash' => hash('sha256', 'default'),
                'label' => 'Principal',
                'status' => $product['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($product['kind'] === 'goods') {
                $this->inventoryCatalog->createForVariant($variantId, $stockUnitId, true);
            }

            return $productId;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProduct(int $productId, array $data, User $actor): void
    {
        $this->authorize($actor, 'catalog.product.update');
        $product = $this->normalizeProduct($data, creating: false);
        $stockUnitId = $this->stockUnitId($data['stock_unit_id'] ?? null);
        $current = $this->product($productId);
        if ($current === null) {
            throw new NotFoundHttpException('Ce produit est introuvable.');
        }
        if (!array_key_exists('cost_price', $data)) {
            $product['cost_price'] = $current['cost_price'] ?? null;
        }
        if (!array_key_exists('seo_tags', $data)) {
            $product['seo_tags'] = $current['seo_tags'] ?? null;
        }
        if (!array_key_exists('is_trending', $data)) {
            $product['is_trending'] = (int) ($current['is_trending'] ?? 0);
        }
        $this->assertSlugAvailable('catalog_products', $product['slug'], $productId);
        $this->assertStatusTransition((string) $current['status'], $product['status']);
        if ($current['status'] !== $product['status']) {
            $this->authorize(
                $actor,
                $product['status'] === 'archived' || $current['status'] === 'archived'
                    ? 'catalog.product.archive'
                    : 'catalog.product.update'
            );
        }
        if ($current['kind'] !== $product['kind'] && $this->usageRegistry->isUsed($productId)) {
            throw $this->invalidInput('kind', 'La nature de ce produit ne peut pas être modifiée car il est déjà utilisé.');
        }

        $variant = CatalogVariant::query()
            ->where('product_id', '=', $productId)
            ->where('is_default', '=', 1)
            ->select('id', 'sku')
            ->first();
        if ($variant === null) {
            throw new InternalServerException('Le produit ne possède pas sa déclinaison principale.');
        }
        $sku = $product['sku'] !== '' ? $product['sku'] : (string) $variant->sku;
        $this->assertSkuAvailable($sku, (int) $variant->id);
        $now = date('Y-m-d H:i:s');

        $this->connection->transaction(function () use ($productId, $product, $sku, $now, $stockUnitId): void {
            CatalogProduct::query()->where('id', '=', $productId)->update([
                'category_id' => $product['category_id'],
                'brand_id' => $product['brand_id'],
                'name' => $product['name'],
                'slug' => $product['slug'],
                'description' => $product['description'],
                'seo_tags' => $product['seo_tags'],
                'kind' => $product['kind'],
                'status' => $product['status'],
                'manufacturer' => $product['manufacturer'],
                'is_trending' => $product['is_trending'],
                'archived_at' => $product['status'] === 'archived' ? $now : null,
                'updated_at' => $now,
            ]);
            CatalogVariant::query()
                ->where('product_id', '=', $productId)
                ->where('is_default', '=', 1)
                ->update([
                    'sku' => $sku,
                    'price' => $product['price'],
                    'cost_price' => $product['cost_price'],
                    'currency' => $product['currency'],
                    'updated_at' => $now,
                ]);
            CatalogVariant::query()->where('product_id', '=', $productId)->update([
                'status' => $product['status'],
                'updated_at' => $now,
            ]);
            if ($product['kind'] === 'goods') {
                $this->ensureStockItem($productId, $stockUnitId);
            }
        });
    }

    public function setProductStatus(int $productId, string $status, User $actor): void
    {
        $current = $this->product($productId);
        if ($current === null) {
            throw new NotFoundHttpException('Ce produit est introuvable.');
        }
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw $this->invalidInput('status', 'Le statut demandé est invalide.');
        }
        $permission = $status === 'archived' || $current['status'] === 'archived'
            ? 'catalog.product.archive'
            : 'catalog.product.update';
        $this->authorize($actor, $permission);
        $this->assertStatusTransition((string) $current['status'], $status);
        $now = date('Y-m-d H:i:s');

        $this->connection->transaction(function () use ($productId, $status, $now, $current): void {
            CatalogProduct::query()->where('id', '=', $productId)->update([
                'status' => $status,
                'archived_at' => $status === 'archived' ? $now : null,
                'updated_at' => $now,
            ]);
            CatalogVariant::query()->where('product_id', '=', $productId)->update([
                'status' => $status,
                'updated_at' => $now,
            ]);
            if ($current['kind'] === 'goods') {
                $this->ensureStockItem($productId, null);
            }
        });
    }

    public function setTrending(string $kind, int $entityId, mixed $isTrending, User $actor): bool
    {
        $permission = match ($kind) {
            'goods', 'service' => 'catalog.product.update',
            'category' => 'catalog.category.manage',
            'brand' => 'catalog.brand.manage',
            default => throw $this->invalidInput('kind', 'Le type de l’élément tendance est invalide.'),
        };
        $this->authorize($actor, $permission);
        if ($entityId <= 0) {
            throw $this->invalidInput('entity_id', 'L’élément à mettre en tendance est invalide.');
        }
        $trending = $this->normalizeTrending($isTrending);

        $exists = match ($kind) {
            'goods', 'service' => CatalogProduct::query()
                ->where('id', '=', $entityId)
                ->where('kind', '=', $kind)
                ->exists(),
            'category' => CatalogCategory::query()->where('id', '=', $entityId)->exists(),
            'brand' => CatalogBrand::query()->where('id', '=', $entityId)->exists(),
        };
        if (!$exists) {
            throw new NotFoundHttpException('L’élément du catalogue est introuvable.');
        }

        match ($kind) {
            'goods', 'service' => CatalogProduct::query()
                ->where('id', '=', $entityId)
                ->where('kind', '=', $kind)
                ->update(['is_trending' => $trending, 'updated_at' => date('Y-m-d H:i:s')]),
            'category' => CatalogCategory::query()
                ->where('id', '=', $entityId)
                ->update(['is_trending' => $trending, 'updated_at' => date('Y-m-d H:i:s')]),
            'brand' => CatalogBrand::query()
                ->where('id', '=', $entityId)
                ->update(['is_trending' => $trending, 'updated_at' => date('Y-m-d H:i:s')]),
        };

        return $trending === 1;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int, meta: array{total: int, per_page: int, current_page: int, last_page: int}}
     */
    public function search(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 5)));
        $searchValue = $filters['search'] ?? '';
        if (!is_scalar($searchValue)) {
            throw $this->invalidInput('search', 'Le filtre de recherche est invalide.');
        }
        $search = trim((string) $searchValue);

        $query = CatalogProduct::table(CatalogProduct::getTable() . ' as p')
            ->leftJoin(CatalogCategory::getTable() . ' as c', 'c.id', '=', 'p.category_id')
            ->leftJoin(CatalogBrand::getTable() . ' as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('catalog_variants as v', function ($join): void {
                $join->on('v.product_id', '=', 'p.id')
                    ->where('v.is_default', '=', 1);
            })
            ->select(
                'p.id',
                'p.name',
                'p.description',
                'p.seo_tags',
                'p.kind',
                'p.status',
                'p.is_trending',
                'p.views_count',
                'p.slug',
                'p.manufacturer',
                'p.updated_at',
                'p.category_id',
                'p.brand_id',
                'c.name as category_name',
                'b.name as brand_name',
                'v.id as variant_id',
                'v.sku',
                'v.price',
                'v.cost_price',
                'v.currency',
            )
            ->search(['p.name', 'p.slug', 'v.sku'], $search);

        foreach (['status', 'kind', 'category_id', 'brand_id'] as $filter) {
            if (!isset($filters[$filter]) || $filters[$filter] === '' || $filters[$filter] === 'all') {
                continue;
            }
            if ($filter === 'status' && !in_array($filters[$filter], ['draft', 'active', 'archived'], true)) {
                continue;
            }
            if ($filter === 'kind' && !in_array($filters[$filter], ['goods', 'service'], true)) {
                continue;
            }
            if (in_array($filter, ['category_id', 'brand_id'], true)) {
                $id = filter_var($filters[$filter], FILTER_VALIDATE_INT);
                if ($id === false || $id <= 0) {
                    continue;
                }
                $filters[$filter] = $id;
            }
            $query->where('p.' . $filter, '=', $filters[$filter]);
        }
        $paginated = $query->orderBy('p.updated_at', 'desc')
            ->orderBy('p.id', 'desc')
            ->paginate($page, $perPage);

        return [
            'items' => array_map(static fn(CatalogProduct $product): array => $product->toArray(), $paginated->items()),
            'total' => $paginated->total(),
            'page' => $paginated->currentPage(),
            'pages' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int, meta: array{total: int, per_page: int, current_page: int, last_page: int}}
     */
    public function searchCategories(string $search = '', int $page = 1, int $perPage = 10): array
    {
        $paginated = CatalogCategory::table(CatalogCategory::getTable() . ' as c')
            ->leftJoin(CatalogCategory::getTable() . ' as parent', 'parent.id', '=', 'c.parent_id')
            ->where('c.status', '=', 'active')
            ->search(['c.name', 'parent.name'], $search)
            ->select('c.id', 'c.parent_id', 'c.name', 'c.slug', 'c.seo_tags', 'c.is_trending', 'c.views_count', 'parent.name as parent_name')
            ->orderBy('c.sort_order')
            ->orderBy('c.name')
            ->paginate(max(1, $page), min(100, max(1, $perPage)));

        return $this->referencePage($paginated);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int, meta: array{total: int, per_page: int, current_page: int, last_page: int}}
     */
    public function searchBrands(string $search = '', int $page = 1, int $perPage = 10): array
    {
        $paginated = CatalogBrand::query()
            ->where('status', '=', 'active')
            ->search(['name'], $search)
            ->select('id', 'name', 'slug', 'seo_tags', 'is_trending', 'views_count')
            ->orderBy('name')
            ->paginate(max(1, $page), min(100, max(1, $perPage)));

        return $this->referencePage($paginated);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int, meta: array{total: int, per_page: int, current_page: int, last_page: int}}
     */
    private function referencePage(Paginator $paginated): array
    {
        $items = array_map(
            static fn(\Core\Database\Model $model): array => $model->toArray(),
            $paginated->items()
        );

        return [
            'items' => $items,
            'total' => $paginated->total(),
            'page' => $paginated->currentPage(),
            'pages' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array
    {
        $product = CatalogProduct::table(CatalogProduct::getTable() . ' as p')
            ->leftJoin(CatalogCategory::getTable() . ' as c', 'c.id', '=', 'p.category_id')
            ->leftJoin(CatalogBrand::getTable() . ' as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin(CatalogVariant::getTable() . ' as v', function ($join): void {
                $join->on('v.product_id', '=', 'p.id')
                    ->where('v.is_default', '=', 1);
            })
            ->where('p.id', '=', $productId)
            ->select(
                'p.*',
                'c.name as category_name',
                'b.name as brand_name',
                'v.id as variant_id',
                'v.sku',
                'v.price',
                'v.cost_price',
                'v.currency',
            )
            ->first();

        return $product?->toArray();
    }

    /** @return array<string, mixed>|null */
    public function publicProductBySlug(string $slug): ?array
    {
        $product = CatalogProduct::table(CatalogProduct::getTable() . ' as p')
            ->leftJoin(CatalogCategory::getTable() . ' as c', function ($join): void {
                $join->on('c.id', '=', 'p.category_id')->where('c.status', '=', 'active');
            })
            ->leftJoin(CatalogBrand::getTable() . ' as b', function ($join): void {
                $join->on('b.id', '=', 'p.brand_id')->where('b.status', '=', 'active');
            })
            ->leftJoin(CatalogVariant::getTable() . ' as v', function ($join): void {
                $join->on('v.product_id', '=', 'p.id')
                    ->where('v.is_default', '=', 1);
            })
            ->where('p.slug', '=', $slug)
            ->where('p.status', '=', 'active')
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('v.id')->orWhere('v.status', '=', 'active');
            })
            ->select(
                'p.id',
                'p.name',
                'p.description',
                'p.seo_tags',
                'p.category_id',
                'p.brand_id',
                'p.kind',
                'p.slug',
                'p.manufacturer',
                'p.updated_at',
                'c.name as category_name',
                'c.slug as category_slug',
                'c.seo_tags as category_seo_tags',
                'b.name as brand_name',
                'b.slug as brand_slug',
                'b.seo_tags as brand_seo_tags',
                'v.id as variant_id',
                'v.sku',
                'v.price',
                'v.currency',
            )
            ->first();

        return $product?->toArray();
    }

    /** @return array{category: list<array<string, mixed>>, brand: list<array<string, mixed>>} */
    public function relatedPublicProducts(int $productId, ?int $categoryId, ?int $brandId): array
    {
        return [
            'category' => $categoryId === null
                ? []
                : $this->publicProductCards('category_id', $categoryId, $productId, 4),
            'brand' => $brandId === null
                ? []
                : $this->publicProductCards('brand_id', $brandId, $productId, 4),
        ];
    }

    /**
     * @return list<array{name: string, slug: string, count: int}>
     */
    public function publicTags(): array
    {
        $tags = [];
        foreach ($this->publicTaggedContent() as $items) {
            foreach ($items as $item) {
                foreach (SeoTags::parse($item['seo_tags'] ?? '') as $tag) {
                    if (!isset($tags[$tag])) {
                        $tags[$tag] = ['name' => $tag, 'slug' => SeoTags::slug($tag), 'count' => 0];
                    }
                    ++$tags[$tag]['count'];
                }
            }
        }

        $tags = array_values($tags);
        usort($tags, static fn(array $left, array $right): int =>
            strnatcasecmp($left['name'], $right['name']) ?: strcmp($left['name'], $right['name'])
        );

        return $tags;
    }

    /**
     * @return array{tag: string, products: list<array<string, mixed>>, categories: list<array<string, mixed>>, brands: list<array<string, mixed>>}|null
     */
    public function publicTagBySlug(string $slug): ?array
    {
        $content = $this->publicTaggedContent();
        $tag = null;
        $matches = ['products' => [], 'categories' => [], 'brands' => []];

        foreach ($content as $kind => $items) {
            foreach ($items as $item) {
                foreach (SeoTags::parse($item['seo_tags'] ?? '') as $itemTag) {
                    if (SeoTags::slug($itemTag) !== $slug) {
                        continue;
                    }

                    $tag = $itemTag;
                    $matches[$kind][] = $item;
                    break;
                }
            }
        }

        return $tag === null ? null : ['tag' => $tag] + $matches;
    }

    /**
     * @return array{products: list<array<string, mixed>>, categories: list<array<string, mixed>>, brands: list<array<string, mixed>>}
     */
    private function publicTaggedContent(): array
    {
        $products = CatalogProduct::table('catalog_products as p')
            ->leftJoin(CatalogVariant::getTable() . ' as v', function ($join): void {
                $join->on('v.product_id', '=', 'p.id')
                    ->where('v.is_default', '=', 1);
            })
            ->leftJoin(CatalogBrand::getTable() . ' as b', function ($join): void {
                $join->on('b.id', '=', 'p.brand_id')->where('b.status', '=', 'active');
            })
            ->leftJoin(CatalogCategory::getTable() . ' as c', function ($join): void {
                $join->on('c.id', '=', 'p.category_id')->where('c.status', '=', 'active');
            })
            ->where('p.status', '=', 'active')
            ->whereNotNull('p.seo_tags')
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('v.id')->orWhere('v.status', '=', 'active');
            })
            ->select(
                'p.id',
                'p.name',
                'p.slug',
                'p.kind',
                'p.seo_tags',
                'v.id as variant_id',
                'v.price',
                'v.currency',
                'b.name as brand_name',
                'c.name as category_name',
            )
            ->orderBy('p.name')
            ->get();

        $categories = CatalogCategory::query()
            ->where('status', '=', 'active')
            ->whereNotNull('seo_tags')
            ->select('id', 'name', 'slug', 'seo_tags')
            ->orderBy('name')
            ->get();

        $brands = CatalogBrand::query()
            ->where('status', '=', 'active')
            ->whereNotNull('seo_tags')
            ->select('id', 'name', 'slug', 'seo_tags')
            ->orderBy('name')
            ->get();

        return [
            'products' => array_map(static fn(CatalogProduct $product): array => $product->toArray(), $products),
            'categories' => array_map(static fn(CatalogCategory $category): array => $category->toArray(), $categories),
            'brands' => array_map(static fn(CatalogBrand $brand): array => $brand->toArray(), $brands),
        ];
    }

    /** @return list<array{id: int, name: string, slug: string, product_count: int, products: list<array<string, mixed>>}> */
    public function publicReferences(string $kind): array
    {
        if ($kind === 'category') {
            $rows = CatalogCategory::table('catalog_categories as ref')
                ->leftJoin(CatalogProduct::getTable() . ' as p', function ($join): void {
                    $join->on('p.category_id', '=', 'ref.id')->where('p.status', '=', 'active');
                })
                ->where('ref.status', '=', 'active')
                ->select('ref.id', 'ref.name', 'ref.slug')
                ->selectRaw('COUNT(p.id) AS product_count')
                ->groupBy('ref.id', 'ref.name', 'ref.slug')
                ->orderBy('ref.sort_order')
                ->orderBy('ref.name')
                ->get();
        } elseif ($kind === 'brand') {
            $rows = CatalogBrand::table('catalog_brands as ref')
                ->leftJoin(CatalogProduct::getTable() . ' as p', function ($join): void {
                    $join->on('p.brand_id', '=', 'ref.id')->where('p.status', '=', 'active');
                })
                ->where('ref.status', '=', 'active')
                ->select('ref.id', 'ref.name', 'ref.slug')
                ->selectRaw('COUNT(p.id) AS product_count')
                ->groupBy('ref.id', 'ref.name', 'ref.slug')
                ->orderBy('ref.name')
                ->get();
        } else {
            throw $this->invalidInput('kind', 'Le type de référence publique est invalide.');
        }

        $filterColumn = $kind === 'category' ? 'category_id' : 'brand_id';

        return array_map(fn($row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'product_count' => (int) $row->product_count,
            'products' => $this->publicProductCards($filterColumn, (int) $row->id, null, 4),
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicTrendingProducts(): array
    {
        $manual = $this->eligiblePublicProductQuery()
            ->where('p.is_trending', '=', 1)
            ->orderByDesc('p.views_count')
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id')
            ->get();
        $products = array_map(static fn(CatalogProduct $product): array => $product->toArray(), $manual);
        $remaining = self::PUBLIC_TRENDING_LIMIT - count($products);
        if ($remaining <= 0) {
            return $products;
        }

        $automatic = $this->eligiblePublicProductQuery()
            ->where('p.is_trending', '=', 0)
            ->orderByDesc('p.views_count')
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id')
            ->limit($remaining)
            ->get();

        return array_merge(
            $products,
            array_map(static fn(CatalogProduct $product): array => $product->toArray(), $automatic)
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array{id: int, name: string, slug: string, product_count: int}>
     */
    public function publicCatalogReferences(string $kind, array $filters = []): array
    {
        if (!in_array($kind, ['category', 'brand'], true)) {
            throw $this->invalidInput('kind', 'Le type de référence publique est invalide.');
        }

        $filterColumn = $kind === 'category' ? 'category_id' : 'brand_id';
        $query = $this->eligiblePublicProductQuery();
        $searchValue = $filters['search'] ?? '';
        $search = is_scalar($searchValue) ? trim((string) $searchValue) : '';
        $query->search(['p.name', 'p.slug', 'p.description', 'p.manufacturer', 'v.sku'], $search);

        foreach (['category_id', 'brand_id'] as $activeFilter) {
            if ($activeFilter === $filterColumn) {
                continue;
            }
            $id = filter_var($filters[$activeFilter] ?? null, FILTER_VALIDATE_INT);
            if ($id !== false && $id !== null && $id > 0) {
                $query->where($activeFilter === 'category_id' ? 'c.id' : 'b.id', '=', $id);
            }
        }

        $referenceId = $kind === 'category' ? 'c.id' : 'b.id';
        $referenceName = $kind === 'category' ? 'c.name' : 'b.name';
        $referenceSlug = $kind === 'category' ? 'c.slug' : 'b.slug';
        $rows = $query
            ->select($referenceId, $referenceName, $referenceSlug)
            ->selectRaw('COUNT(DISTINCT p.id) AS product_count')
            ->groupBy($referenceId, $referenceName, $referenceSlug)
            ->orderBy($referenceName)
            ->get();

        $references = array_map(static fn($row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'product_count' => (int) $row->product_count,
        ], $rows);
        $selectedId = filter_var($filters[$filterColumn] ?? null, FILTER_VALIDATE_INT);
        if ($selectedId !== false && $selectedId !== null && $selectedId > 0) {
            $selectedIsListed = in_array($selectedId, array_column($references, 'id'), true);
            if (!$selectedIsListed) {
                $referenceModel = $kind === 'category' ? CatalogCategory::class : CatalogBrand::class;
                $selectedReference = $referenceModel::query()
                    ->where('id', '=', $selectedId)
                    ->where('status', '=', 'active')
                    ->select('id', 'name', 'slug')
                    ->first();
                if ($selectedReference !== null) {
                    $references[] = [
                        'id' => (int) $selectedReference->id,
                        'name' => (string) $selectedReference->name,
                        'slug' => (string) $selectedReference->slug,
                        'product_count' => 0,
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function publicProductListing(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));
        $searchValue = $filters['search'] ?? '';
        $search = is_scalar($searchValue) ? trim((string) $searchValue) : '';
        $categoryId = filter_var($filters['category_id'] ?? null, FILTER_VALIDATE_INT);
        $brandId = filter_var($filters['brand_id'] ?? null, FILTER_VALIDATE_INT);

        $query = $this->eligiblePublicProductQuery()
            ->search(['p.name', 'p.slug', 'p.description', 'p.manufacturer', 'v.sku'], $search);

        if ($categoryId !== false && $categoryId !== null && $categoryId > 0) {
            $query->where('c.id', '=', $categoryId);
        }
        if ($brandId !== false && $brandId !== null && $brandId > 0) {
            $query->where('b.id', '=', $brandId);
        }

        $sort = (string) ($filters['sort'] ?? 'newest');
        match ($sort) {
            'name_asc' => $query->orderBy('p.name')->orderByDesc('p.id'),
            'name_desc' => $query->orderByDesc('p.name')->orderByDesc('p.id'),
            default => $query->orderByDesc('p.created_at')->orderByDesc('p.id'),
        };
        $paginated = $query->paginate($page, $perPage);

        return [
            'items' => array_map(static fn(CatalogProduct $product): array => $product->toArray(), $paginated->items()),
            'total' => $paginated->total(),
            'page' => $paginated->currentPage(),
            'pages' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, slug: string, is_trending: int, views_count: int}>
     */
    public function publicTrendingReferences(string $kind): array
    {
        if (!in_array($kind, ['category', 'brand'], true)) {
            throw $this->invalidInput('kind', 'Le type de référence publique est invalide.');
        }

        $query = $kind === 'category'
            ? CatalogCategory::table('catalog_categories as ref')
            : CatalogBrand::table('catalog_brands as ref');
        $manual = $query
            ->where('ref.status', '=', 'active')
            ->where('ref.is_trending', '=', 1)
            ->select('ref.id', 'ref.name', 'ref.slug', 'ref.is_trending', 'ref.views_count')
            ->orderByDesc('ref.views_count')
            ->orderByDesc('ref.created_at')
            ->orderByDesc('ref.id')
            ->get();
        $references = array_map(static fn($reference): array => $reference->toArray(), $manual);
        $remaining = self::PUBLIC_TRENDING_LIMIT - count($references);
        if ($remaining <= 0) {
            return $references;
        }

        $query = $kind === 'category'
            ? CatalogCategory::table('catalog_categories as ref')
            : CatalogBrand::table('catalog_brands as ref');
        $automatic = $query
            ->where('ref.status', '=', 'active')
            ->where('ref.is_trending', '=', 0)
            ->select('ref.id', 'ref.name', 'ref.slug', 'ref.is_trending', 'ref.views_count')
            ->orderByDesc('ref.views_count')
            ->orderByDesc('ref.created_at')
            ->orderByDesc('ref.id')
            ->limit($remaining)
            ->get();

        return array_merge(
            $references,
            array_map(static fn($reference): array => $reference->toArray(), $automatic)
        );
    }

    /** @return array<string, mixed>|null */
    public function publicHeroProduct(string $kind, int $referenceId): ?array
    {
        $column = match ($kind) {
            'category' => 'category_id',
            'brand' => 'brand_id',
            default => throw $this->invalidInput('kind', 'Le type de référence publique est invalide.'),
        };
        if ($referenceId <= 0) {
            throw $this->invalidInput('reference_id', 'La référence publique est invalide.');
        }

        $product = $this->eligiblePublicProductQuery()
            ->where('p.' . $column, '=', $referenceId)
            ->orderByDesc('p.is_trending')
            ->orderByDesc('p.views_count')
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id')
            ->limit(1)
            ->first();

        return $product?->toArray();
    }

    public function incrementPublicViews(string $kind, int $entityId): int
    {
        if ($entityId <= 0) {
            throw $this->invalidInput('entity_id', 'L’élément consulté est invalide.');
        }

        return match ($kind) {
            'product', 'service' => CatalogProduct::query()
                ->where('id', '=', $entityId)
                ->where('kind', '=', $kind === 'service' ? 'service' : 'goods')
                ->where('status', '=', 'active')
                ->increment('views_count'),
            'category' => CatalogCategory::query()
                ->where('id', '=', $entityId)
                ->where('status', '=', 'active')
                ->increment('views_count'),
            'brand' => CatalogBrand::query()
                ->where('id', '=', $entityId)
                ->where('status', '=', 'active')
                ->increment('views_count'),
            default => throw $this->invalidInput('kind', 'Le type d’élément consulté est invalide.'),
        };
    }

    private function eligiblePublicProductQuery(): QueryBuilder
    {
        return CatalogProduct::table('catalog_products as p')
            ->leftJoin(CatalogVariant::getTable() . ' as v', function ($join): void {
                $join->on('v.product_id', '=', 'p.id')
                    ->where('v.is_default', '=', 1);
            })
            ->leftJoin(CatalogBrand::getTable() . ' as b', function ($join): void {
                $join->on('b.id', '=', 'p.brand_id')
                    ->where('b.status', '=', 'active');
            })
            ->leftJoin(CatalogCategory::getTable() . ' as c', function ($join): void {
                $join->on('c.id', '=', 'p.category_id')
                    ->where('c.status', '=', 'active');
            })
            ->where('p.status', '=', 'active')
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('v.id')->orWhere('v.status', '=', 'active');
            })
            ->select(
                'p.id',
                'p.name',
                'p.slug',
                'p.kind',
                'p.description',
                'p.seo_tags',
                'p.is_trending',
                'p.views_count',
                'p.category_id',
                'p.brand_id',
                'p.manufacturer',
                'p.updated_at',
                'p.created_at',
                'v.id as variant_id',
                'v.sku',
                'v.price',
                'v.currency',
                'b.name as brand_name',
                'b.slug as brand_slug',
                'c.name as category_name',
                'c.slug as category_slug',
            );
    }

    /**
     * @return list<array{path: string, lastmod: string|null}>
     */
    public function publicSitemapEntries(): array
    {
        $entries = [
            ['path' => '/categories', 'lastmod' => null],
            ['path' => '/brands', 'lastmod' => null],
            ['path' => '/tags', 'lastmod' => null],
        ];

        foreach ($this->publicTags() as $tag) {
            $entries[] = [
                'path' => '/tags/' . rawurlencode($tag['slug']),
                'lastmod' => null,
            ];
        }

        foreach (CatalogCategory::query()
            ->where('status', '=', 'active')
            ->select('slug', 'updated_at')
            ->orderBy('id')
            ->get() as $category) {
            $entries[] = [
                'path' => '/categories/' . rawurlencode((string) $category->slug),
                'lastmod' => $category->updated_at === null ? null : (string) $category->updated_at,
            ];
        }

        foreach (CatalogBrand::query()
            ->where('status', '=', 'active')
            ->select('slug', 'updated_at')
            ->orderBy('id')
            ->get() as $brand) {
            $entries[] = [
                'path' => '/brands/' . rawurlencode((string) $brand->slug),
                'lastmod' => $brand->updated_at === null ? null : (string) $brand->updated_at,
            ];
        }

        $products = CatalogProduct::table('catalog_products as p')
            ->leftJoin(CatalogVariant::getTable() . ' as v', function ($join): void {
                $join->on('v.product_id', '=', 'p.id')
                    ->where('v.is_default', '=', 1);
            })
            ->where('p.status', '=', 'active')
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('v.id')->orWhere('v.status', '=', 'active');
            })
            ->select('p.slug', 'p.updated_at')
            ->orderBy('p.id')
            ->get();

        foreach ($products as $product) {
            $entries[] = [
                'path' => '/products/' . rawurlencode((string) $product->slug),
                'lastmod' => $product->updated_at === null ? null : (string) $product->updated_at,
            ];
        }

        return $entries;
    }

    /** @return array{entity: array<string, mixed>, products: list<array<string, mixed>>, total: int, page: int, pages: int}|null */
    public function publicCategoryBySlug(string $slug, int $page = 1): ?array
    {
        $category = CatalogCategory::query()
            ->where('slug', '=', $slug)
            ->where('status', '=', 'active')
            ->select('id', 'name', 'slug', 'seo_tags')
            ->first();
        if ($category === null) {
            return null;
        }

        return $this->publicReferenceProducts($category->toArray(), 'category_id', $page);
    }

    /** @return array{entity: array<string, mixed>, products: list<array<string, mixed>>, total: int, page: int, pages: int}|null */
    public function publicBrandBySlug(string $slug, int $page = 1): ?array
    {
        $brand = CatalogBrand::query()
            ->where('slug', '=', $slug)
            ->where('status', '=', 'active')
            ->select('id', 'name', 'slug', 'seo_tags')
            ->first();
        if ($brand === null) {
            return null;
        }

        return $this->publicReferenceProducts($brand->toArray(), 'brand_id', $page);
    }

    /**
     * @param array<string, mixed> $entity
     * @return array{entity: array<string, mixed>, products: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    private function publicReferenceProducts(array $entity, string $column, int $page): array
    {
        if (!in_array($column, ['category_id', 'brand_id'], true)) {
            throw new InternalServerException('Référence publique invalide.');
        }
        $pageSize = 24;
        $total = CatalogProduct::query()
            ->where($column, '=', (int) $entity['id'])
            ->where('status', '=', 'active')
            ->count();
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);

        return [
            'entity' => $entity,
            'products' => $this->publicProductCards(
                $column,
                (int) $entity['id'],
                null,
                $pageSize,
                ($page - 1) * $pageSize,
            ),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function publicProductCards(
        string $filterColumn,
        int $filterId,
        ?int $excludeProductId,
        int $limit = 8,
        int $offset = 0
    ): array
    {
        if (!in_array($filterColumn, ['category_id', 'brand_id'], true)) {
            throw new InternalServerException('Référence publique invalide.');
        }
        $limit = min(100, max(1, $limit));
        $offset = max(0, $offset);

        $query = CatalogProduct::table('catalog_products as p')
            ->leftJoin(CatalogVariant::getTable() . ' as v', function ($join): void {
                $join->on('v.product_id', '=', 'p.id')
                    ->where('v.is_default', '=', 1)
                    ->where('v.status', '=', 'active');
            })
            ->leftJoin(CatalogBrand::getTable() . ' as b', function ($join): void {
                $join->on('b.id', '=', 'p.brand_id')->where('b.status', '=', 'active');
            })
            ->leftJoin(CatalogCategory::getTable() . ' as c', function ($join): void {
                $join->on('c.id', '=', 'p.category_id')->where('c.status', '=', 'active');
            })
            ->where('p.status', '=', 'active')
            ->where('p.' . $filterColumn, '=', $filterId);
        if ($excludeProductId !== null) {
            $query->where('p.id', '<>', $excludeProductId);
        }

        return array_map(
            static fn(CatalogProduct $product): array => $product->toArray(),
            $query->select(
                'p.id',
                'p.name',
                'p.slug',
                'p.kind',
                'p.description',
                'p.seo_tags',
                'v.id as variant_id',
                'v.price',
                'v.currency',
                'v.sku',
                'b.name as brand_name',
                'b.slug as brand_slug',
                'c.name as category_name',
                'c.slug as category_slug',
            )
                ->orderByDesc('p.updated_at')
                ->orderByDesc('p.id')
                ->limit($limit)
                ->offset($offset)
                ->get()
        );
    }

    /** @return array<string, mixed>|null */
    public function variant(int $variantId): ?array
    {
        $variant = CatalogVariant::table(CatalogVariant::getTable() . ' as v')
            ->join(CatalogProduct::getTable() . ' as p', 'p.id', '=', 'v.product_id')
            ->where('v.id', '=', $variantId)
            ->select(
                'v.id',
                'v.product_id',
                'v.sku',
                'v.price',
                'v.cost_price',
                'v.currency',
                'v.status',
                'p.name',
                'p.kind',
                'p.status as product_status',
            )
            ->first();

        return $variant?->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return array_map(
            static fn(CatalogCategory $category): array => $category->toArray(),
            CatalogCategory::query()
                ->where('status', '=', 'active')
                ->select('id', 'parent_id', 'name', 'slug', 'seo_tags', 'is_trending', 'views_count')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    /** @return list<array<string, mixed>> */
    public function brands(): array
    {
        return array_map(
            static fn(CatalogBrand $brand): array => $brand->toArray(),
            CatalogBrand::query()
                ->where('status', '=', 'active')
                ->select('id', 'name', 'slug', 'seo_tags', 'is_trending', 'views_count')
                ->orderBy('name')
                ->get()
        );
    }

    public function createCategory(
        string $name,
        ?int $parentId,
        User $actor,
        ?string $creationToken = null,
        ?string $slug = null,
        mixed $seoTags = null,
        mixed $isTrending = false
    ): int
    {
        $this->authorize($actor, 'catalog.category.manage');
        $seoTags = $this->seoTagsInput($seoTags);
        $isTrending = $this->normalizeTrending($isTrending);
        $errors = Data::create([
            'name' => $name,
            'parent_id' => $parentId,
            'creation_token' => $creationToken,
            'slug' => $slug,
            'seo_tags' => $seoTags,
            'is_trending' => $isTrending,
        ])->validate([
            'name' => 'required|string',
            'parent_id' => 'nullable|integer|min:1',
            'creation_token' => 'nullable|string',
            'slug' => 'nullable|string',
            'seo_tags' => 'nullable|string',
            'is_trending' => 'boolean',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $name = trim($name);
        if ($name === '' || $this->textLength($name) > 190) {
            throw $this->invalidInput('name', 'Le nom de la catégorie doit contenir entre 1 et 190 caractères.');
        }
        $slug = $this->normalizeSlug($slug ?? '', $name);
        if ($creationToken !== null) {
            if (preg_match('/^[a-f0-9]{64}$/i', $creationToken) !== 1) {
                throw $this->invalidInput('creation_token', 'Le formulaire a expiré. Rechargez la page puis réessayez.');
            }
            $existing = CatalogCategory::query()
                ->where('creation_token', '=', $creationToken)
                ->select('id')
                ->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }
        }
        $this->assertSlugAvailable('catalog_categories', $slug);
        if ($parentId !== null && !CatalogCategory::query()
            ->where('id', '=', $parentId)
            ->where('status', '=', 'active')
            ->exists()) {
            throw new NotFoundHttpException('La catégorie parente est introuvable.');
        }
        $depth = 1;
        $ancestorId = $parentId;
        while ($ancestorId !== null) {
            if ($depth >= $this->categoryMaxDepth) {
                throw $this->invalidInput(
                    'parent_id',
                    'La hiérarchie des catégories ne peut pas dépasser ' . $this->categoryMaxDepth . ' niveaux.'
                );
            }
            $ancestor = CatalogCategory::query()
                ->where('id', '=', $ancestorId)
                ->select('parent_id')
                ->first();
            $ancestorId = $ancestor === null || $ancestor->parent_id === null
                ? null
                : (int) $ancestor->parent_id;
            $depth++;
        }
        $now = date('Y-m-d H:i:s');
        return CatalogCategory::query()->insertGetId([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'seo_tags' => $this->normalizeSeoTags($seoTags ?? ''),
            'is_trending' => $isTrending,
            'status' => 'active',
            'sort_order' => 0,
            'creation_token' => $creationToken,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function createBrand(
        string $name,
        User $actor,
        ?string $slug = null,
        mixed $seoTags = null,
        mixed $isTrending = false
    ): int
    {
        $this->authorize($actor, 'catalog.brand.manage');
        $seoTags = $this->seoTagsInput($seoTags);
        $isTrending = $this->normalizeTrending($isTrending);
        $errors = Data::create(['name' => $name, 'slug' => $slug, 'seo_tags' => $seoTags, 'is_trending' => $isTrending])
            ->validate([
                'name' => 'required|string',
                'slug' => 'nullable|string',
                'seo_tags' => 'nullable|string',
                'is_trending' => 'boolean',
            ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $name = trim($name);
        if ($name === '' || $this->textLength($name) > 190) {
            throw $this->invalidInput('name', 'Le nom de la marque doit contenir entre 1 et 190 caractères.');
        }
        $slug = $this->normalizeSlug($slug ?? '', $name);
        if (CatalogBrand::query()->where('name', '=', $name)->exists()) {
            throw $this->invalidInput('name', 'Cette marque existe déjà.');
        }
        $this->assertSlugAvailable('catalog_brands', $slug);
        $now = date('Y-m-d H:i:s');
        return CatalogBrand::query()->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'seo_tags' => $this->normalizeSeoTags($seoTags ?? ''),
            'is_trending' => $isTrending,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateCategory(
        int $categoryId,
        string $name,
        ?int $parentId,
        User $actor,
        ?string $slug = null,
        mixed $seoTags = null,
        mixed $isTrending = null
    ): void
    {
        $this->authorize($actor, 'catalog.category.manage');
        $seoTags = $this->seoTagsInput($seoTags);
        if ($isTrending !== null) {
            $isTrending = $this->normalizeTrending($isTrending);
        }
        $errors = Data::create([
            'category_id' => $categoryId,
            'name' => $name,
            'parent_id' => $parentId,
            'slug' => $slug,
            'seo_tags' => $seoTags,
            'is_trending' => $isTrending,
        ])->validate([
            'category_id' => 'required|integer|min:1',
            'name' => 'required|string',
            'parent_id' => 'nullable|integer|min:1',
            'slug' => 'nullable|string',
            'seo_tags' => 'nullable|string',
            'is_trending' => 'nullable|boolean',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $name = trim($name);
        if ($categoryId <= 0 || $name === '' || $this->textLength($name) > 190) {
            throw $this->invalidInput('name', 'Le nom de la catégorie doit contenir entre 1 et 190 caractères.');
        }
        $slug = $this->normalizeSlug($slug ?? '', $name);
        $category = CatalogCategory::query()
            ->where('id', '=', $categoryId)
            ->where('status', '=', 'active')
            ->select('id', 'seo_tags', 'is_trending')
            ->first();
        if ($category === null) {
            throw new NotFoundHttpException('Cette catégorie est introuvable.');
        }
        $this->assertSlugAvailable('catalog_categories', $slug, $categoryId);
        if ($parentId === $categoryId) {
            throw $this->invalidInput('parent_id', 'Une catégorie ne peut pas être sa propre parente.');
        }
        if ($parentId !== null && !CatalogCategory::query()
            ->where('id', '=', $parentId)
            ->where('status', '=', 'active')
            ->exists()) {
            throw new NotFoundHttpException('La catégorie parente est introuvable.');
        }
        $ancestorId = $parentId;
        $depth = 1;
        while ($ancestorId !== null) {
            if ($ancestorId === $categoryId || $depth >= $this->categoryMaxDepth) {
                throw $this->invalidInput('parent_id', 'La hiérarchie des catégories sélectionnée est invalide.');
            }
            $ancestor = CatalogCategory::query()
                ->where('id', '=', $ancestorId)
                ->select('parent_id')
                ->first();
            $ancestorId = $ancestor === null || $ancestor->parent_id === null
                ? null
                : (int) $ancestor->parent_id;
            $depth++;
        }
        CatalogCategory::query()->where('id', '=', $categoryId)->update([
            'name' => $name,
            'slug' => $slug,
            'seo_tags' => $seoTags === null ? $category->seo_tags : $this->normalizeSeoTags($seoTags),
            'is_trending' => $isTrending === null ? (int) $category->is_trending : $isTrending,
            'parent_id' => $parentId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteCategory(int $categoryId, User $actor): void
    {
        $this->authorize($actor, 'catalog.category.manage');
        if ($categoryId <= 0 || !CatalogCategory::query()
            ->where('id', '=', $categoryId)
            ->where('status', '=', 'active')
            ->exists()) {
            throw new NotFoundHttpException('Cette catégorie est introuvable.');
        }
        if (CatalogProduct::query()->where('category_id', '=', $categoryId)->exists()
            || CatalogCategory::query()->where('parent_id', '=', $categoryId)->exists()) {
            throw $this->invalidInput('category_id', 'Cette catégorie est encore utilisée par des produits ou sous-catégories.');
        }
        CatalogCategory::query()->where('id', '=', $categoryId)->delete();
    }

    public function updateBrand(
        int $brandId,
        string $name,
        User $actor,
        ?string $slug = null,
        mixed $seoTags = null,
        mixed $isTrending = null
    ): void
    {
        $this->authorize($actor, 'catalog.brand.manage');
        $seoTags = $this->seoTagsInput($seoTags);
        if ($isTrending !== null) {
            $isTrending = $this->normalizeTrending($isTrending);
        }
        $errors = Data::create([
            'brand_id' => $brandId,
            'name' => $name,
            'slug' => $slug,
            'seo_tags' => $seoTags,
            'is_trending' => $isTrending,
        ])->validate([
            'brand_id' => 'required|integer|min:1',
            'name' => 'required|string',
            'slug' => 'nullable|string',
            'seo_tags' => 'nullable|string',
            'is_trending' => 'nullable|boolean',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $name = trim($name);
        if ($brandId <= 0 || $name === '' || $this->textLength($name) > 190) {
            throw $this->invalidInput('name', 'Le nom de la marque doit contenir entre 1 et 190 caractères.');
        }
        $slug = $this->normalizeSlug($slug ?? '', $name);
        $brand = CatalogBrand::query()
            ->where('id', '=', $brandId)
            ->where('status', '=', 'active')
            ->select('id', 'seo_tags', 'is_trending')
            ->first();
        if ($brand === null) {
            throw new NotFoundHttpException('Cette marque est introuvable.');
        }
        $this->assertSlugAvailable('catalog_brands', $slug, $brandId);
        if (CatalogBrand::query()->where('name', '=', $name)->where('id', '!=', $brandId)->exists()) {
            throw $this->invalidInput('name', 'Cette marque existe déjà.');
        }
        CatalogBrand::query()->where('id', '=', $brandId)->update([
            'name' => $name,
            'slug' => $slug,
            'seo_tags' => $seoTags === null ? $brand->seo_tags : $this->normalizeSeoTags($seoTags),
            'is_trending' => $isTrending === null ? (int) $brand->is_trending : $isTrending,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteBrand(int $brandId, User $actor): void
    {
        $this->authorize($actor, 'catalog.brand.manage');
        if ($brandId <= 0 || !CatalogBrand::query()
            ->where('id', '=', $brandId)
            ->where('status', '=', 'active')
            ->exists()) {
            throw new NotFoundHttpException('Cette marque est introuvable.');
        }
        if (CatalogProduct::query()->where('brand_id', '=', $brandId)->exists()) {
            throw $this->invalidInput('brand_id', 'Cette marque est encore associée à des produits.');
        }
        CatalogBrand::query()->where('id', '=', $brandId)->delete();
    }

    public function deleteProduct(int $productId, User $actor): void
    {
        $this->authorize($actor, 'catalog.product.delete');
        if ($this->product($productId) === null) {
            throw new NotFoundHttpException('Ce produit est introuvable.');
        }
        if ($this->usageRegistry->isUsed($productId)) {
            throw $this->invalidInput('product_id', 'Ce produit est utilisé : retirez-le du catalogue plutôt que de le supprimer.');
        }
        $this->connection->transaction(function () use ($productId): void {
            $this->catalogMedia->deleteForProduct($productId);
            CatalogVariant::query()->where('product_id', '=', $productId)->delete();
            CatalogProduct::query()->where('id', '=', $productId)->delete();
        });
    }

    private function authorize(User $actor, string $permission): void
    {
        if (!$actor->isSuper() && !$actor->hasPermission($permission)) {
            throw new \Core\Exceptions\Http\GateException();
        }
    }

    /** @return array<string, mixed> */
    private function normalizeProduct(array $data, bool $creating): array
    {
        $descriptionInput = $data['description'] ?? null;
        if ($descriptionInput !== null && !is_string($descriptionInput)) {
            throw $this->invalidInput('description', 'La description doit être du texte mis en forme.');
        }
        try {
            $description = RichTextSanitizer::sanitize(
                trim((string) ($descriptionInput ?? '')),
                rejectInvalidInlineImages: true
            );
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalidInput('description', $exception->getMessage());
        }
        if (!RichTextSanitizer::hasContent($description)) {
            $description = '';
        }

        if (array_key_exists('seo_tags', $data)) {
            $data['seo_tags'] = $this->seoTagsInput($data['seo_tags']);
        }
        $data['description'] = $description;
        $data['is_trending'] = $this->normalizeTrending($data['is_trending'] ?? false);
        $input = Data::create($data);
        $errors = $input->validate([
            'name' => 'required|string',
            'slug' => 'nullable|string',
            'description' => 'nullable|string',
            'seo_tags' => 'nullable|string',
            'kind' => 'nullable|in:goods,service',
            'status' => 'nullable|in:draft,active,archived',
            'sku' => 'nullable|string',
            'price' => 'nullable|numeric',
            'cost_price' => 'nullable|numeric',
            'currency' => 'nullable|string',
            'category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'manufacturer' => 'nullable|string',
            'creation_token' => 'nullable|string',
            'stock_unit_id' => 'nullable|integer|min:1',
            'is_trending' => 'boolean',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }

        $name = trim($this->inputString($data, 'name'));
        if ($name === '' || $this->textLength($name) > 190) {
            throw $this->invalidInput('name', 'Le nom du produit doit contenir entre 1 et 190 caractères.');
        }
        $slug = $this->normalizeSlug($this->inputString($data, 'slug'), $name);
        $kind = $this->inputString($data, 'kind', 'goods');
        $status = $this->inputString($data, 'status', $creating ? $this->defaultStatus : 'draft');
        $sku = strtoupper(trim($this->inputString($data, 'sku')));
        $priceValue = trim($this->inputString($data, 'price'));
        $price = $priceValue !== '' ? $priceValue : null;
        $costPrice = array_key_exists('cost_price', $data)
            ? $this->normalizeCostPrice($this->inputString($data, 'cost_price'))
            : null;
        $creationToken = trim($this->inputString($data, 'creation_token'));
        if (!in_array($kind, ['goods', 'service'], true) || !in_array($status, ['draft', 'active', 'archived'], true)) {
            throw $this->invalidInput('kind', 'La nature ou le statut du produit est invalide.');
        }
        if ($sku !== '' && (strlen($sku) > 100 || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $sku) !== 1)) {
            throw $this->invalidInput('sku', 'La référence doit contenir uniquement des lettres, chiffres, points, tirets ou soulignés.');
        }
        if ($price !== null && preg_match('/^\d{1,14}(?:\.\d{1,4})?$/', $price) !== 1) {
            throw $this->invalidInput('price', 'Le prix doit être un montant positif ou nul avec au maximum quatre décimales.');
        }
        if ($creationToken !== '' && preg_match('/^[a-f0-9]{64}$/i', $creationToken) !== 1) {
            throw $this->invalidInput('creation_token', 'Le formulaire a expiré. Rechargez la page puis réessayez.');
        }
        $currency = strtoupper(trim($this->inputString($data, 'currency', $this->defaultCurrency)));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw $this->invalidInput('currency', 'La devise doit être indiquée avec un code de trois lettres.');
        }
        if ($currency !== $this->defaultCurrency) {
            throw $this->invalidInput('currency', 'La devise du produit doit correspondre à la devise globale APP_CURRENCY.');
        }
        $categoryId = $this->optionalId($data['category_id'] ?? null, 'category_id');
        $brandId = $this->optionalId($data['brand_id'] ?? null, 'brand_id');
        $this->assertReferenceExists('catalog_categories', $categoryId, 'La catégorie sélectionnée est introuvable.');
        $this->assertReferenceExists('catalog_brands', $brandId, 'La marque sélectionnée est introuvable.');

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => trim($this->inputString($data, 'description')) ?: null,
            'seo_tags' => array_key_exists('seo_tags', $data)
                ? $this->normalizeSeoTags($this->inputString($data, 'seo_tags'))
                : null,
            'kind' => $kind,
            'status' => $status,
            'sku' => $sku,
            'price' => $price,
            'cost_price' => $costPrice,
            'currency' => $currency,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'manufacturer' => trim($this->inputString($data, 'manufacturer')) ?: null,
            'creation_token' => $creationToken !== '' ? $creationToken : null,
            'is_trending' => $data['is_trending'],
        ];
    }

    private function normalizeTrending(mixed $value): int
    {
        if ($value === true || $value === 1 || $value === '1' || (is_string($value) && strtolower($value) === 'true')) {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === null || $value === ''
            || (is_string($value) && strtolower($value) === 'false')) {
            return 0;
        }

        throw $this->invalidInput('is_trending', 'La valeur de tendance doit être vraie ou fausse.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function inputString(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        if (!is_scalar($value) && !($value instanceof \Stringable)) {
            throw $this->invalidInput($key, 'Cette valeur doit être une chaîne de caractères.');
        }

        return (string) $value;
    }

    private function normalizeCostPrice(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw $this->invalidInput('cost_price', 'Le coût d’achat doit être un montant positif ou nul avec au maximum quatre décimales.');
        }

        return $value;
    }

    private function normalizeSeoTags(string $value): ?string
    {
        if ($this->textLength($value) > 1000) {
            throw $this->invalidInput('seo_tags', 'Les tags SEO ne peuvent pas dépasser 1 000 caractères.');
        }
        $tags = SeoTags::parse($value);
        foreach ($tags as $tag) {
            if ($this->textLength($tag) > 80) {
                throw $this->invalidInput('seo_tags', 'Chaque tag SEO doit contenir au maximum 80 caractères.');
            }
        }
        if (count($tags) > 20) {
            throw $this->invalidInput('seo_tags', 'Vous pouvez saisir au maximum 20 tags SEO.');
        }

        return $tags === [] ? null : implode(', ', $tags);
    }

    private function seoTagsInput(mixed $value): mixed
    {
        if (!is_array($value) || !array_is_list($value)) {
            return $value;
        }
        foreach ($value as $tag) {
            if (!is_string($tag)) {
                return $value;
            }
        }

        return implode(', ', $value);
    }

    private function optionalId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1))
            || (int) $value <= 0) {
            throw $this->invalidInput($field, 'La référence sélectionnée est invalide.');
        }

        return (int) $value;
    }

    private function stockUnitId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1))
            || (int) $value <= 0) {
            throw $this->invalidInput('stock_unit_id', 'L’unité de stock sélectionnée est invalide.');
        }

        return (int) $value;
    }

    private function ensureStockItem(int $productId, ?int $unitId): void
    {
        $variant = CatalogVariant::query()
            ->where('product_id', '=', $productId)
            ->where('is_default', '=', 1)
            ->select('id')
            ->first();
        if ($variant === null) {
            throw new InternalServerException('Le produit ne possède pas sa déclinaison principale.');
        }

        $this->inventoryCatalog->createForVariant((int) $variant->id, $unitId, true);
    }

    private function assertReferenceExists(string $table, ?int $id, string $message): void
    {
        if ($id === null) {
            return;
        }
        if (!in_array($table, ['catalog_categories', 'catalog_brands'], true)) {
            throw new InternalServerException('Référentiel non autorisé.');
        }
        $query = match ($table) {
            'catalog_categories' => CatalogCategory::query(),
            'catalog_brands' => CatalogBrand::query(),
            default => throw new InternalServerException('Référentiel non autorisé.'),
        };
        if (!$query->where('id', '=', $id)->where('status', '=', 'active')->exists()) {
            throw $this->invalidInput($table === 'catalog_categories' ? 'category_id' : 'brand_id', $message);
        }
    }

    private function normalizeSlug(string $slug, string $name): string
    {
        $slug = Str::slug(trim($slug) !== '' ? $slug : $name);
        if ($slug === '') {
            throw $this->invalidInput('slug', 'Le slug doit contenir au moins une lettre ou un chiffre.');
        }
        if (strlen($slug) > 220) {
            throw $this->invalidInput('slug', 'Le slug ne peut pas dépasser 220 caractères.');
        }

        return $slug;
    }

    private function assertSlugAvailable(string $table, string $slug, ?int $exceptId = null): void
    {
        if (!in_array($table, ['catalog_products', 'catalog_categories', 'catalog_brands'], true)) {
            throw new InternalServerException('Référentiel de slug non autorisé.');
        }
        $query = match ($table) {
            'catalog_products' => CatalogProduct::query(),
            'catalog_categories' => CatalogCategory::query(),
            'catalog_brands' => CatalogBrand::query(),
            default => throw new InternalServerException('Référentiel de slug non autorisé.'),
        };
        $query->where('slug', '=', $slug);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidInput('slug', 'Ce slug est déjà utilisé.');
        }
    }

    private function assertSkuAvailable(string $sku, ?int $exceptVariantId = null): void
    {
        $query = CatalogVariant::query()->whereRaw('UPPER(sku) = ?', [strtoupper($sku)]);
        if ($exceptVariantId !== null) {
            $query->where('id', '<>', $exceptVariantId);
        }
        if ($query->exists()) {
            throw $this->invalidInput('sku', 'Cette référence est déjà utilisée.');
        }
    }

    private function assertStatusTransition(string $current, string $next): void
    {
        $allowed = match ($current) {
            'draft' => ['draft', 'active', 'archived'],
            'active' => ['active', 'archived'],
            'archived' => ['archived', 'active'],
            default => [],
        };
        if (!in_array($next, $allowed, true)) {
            throw $this->invalidInput('status', 'Cette modification de statut n’est pas autorisée.');
        }
    }

    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value);

        return $count === false ? strlen($value) : $count;
    }

    private function invalidInput(string $field, string $message): UnprocessableEntityException
    {
        return new UnprocessableEntityException([$field => [$message]], $message);
    }
}
