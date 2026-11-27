@cdn('scripts', '/dashboard/service-assets/inventory-service/catalog-management.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/form.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/inventory-management.js')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$json = static fn(mixed $value): string => htmlspecialchars(
    (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$iconPaths = [
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    'chevron' => '<path d="m6 9 6 6 6-6"/>',
    'package' => '<path d="m7.5 4.27 9 5.15M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.73Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
    'warehouse' => '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12M6 14h12"/>',
    'layers' => '<path d="m12 2 10 5-10 5L2 7l10-5Z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
    'receipt' => '<path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
    'issue' => '<path d="M12 15V3m0 0L8 7m4-4 4 4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
    'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'unlock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/>',
    'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
    'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/>',
    'tag' => '<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.41l8.7 8.7a2 2 0 0 0 2.82 0l7.18-7.18a2 2 0 0 0 0-2.82Z"/><circle cx="7.5" cy="7.5" r="1" fill="currentColor"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"/>',
    'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
    'check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    'box' => '<path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>',
    'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
];
$icon = static fn(string $name, string $class = 'h-4 w-4'): string =>
    '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    . ($iconPaths[$name] ?? '') . '</svg>';
$balances = array_map(static function (array $balance): array {
    $balance['available_qty'] = \App\Inventory\InventoryQuantity::from((string) $balance['physical_qty'])
        ->subtract(\App\Inventory\InventoryQuantity::from((string) $balance['reserved_qty']))->toString();
    return $balance;
}, $balances);
$productStocks = array_map(static function (array $stock): array {
    $stock['available_qty'] = \App\Inventory\InventoryQuantity::from((string) $stock['physical_qty'])
        ->subtract(\App\Inventory\InventoryQuantity::from((string) $stock['reserved_qty']))->toString();
    return $stock;
}, $productStocks);
$defaultMinQty = \App\Inventory\InventoryQuantity::from((string) $default_min_qty)->toString();
$defaultMovementQuantity = \App\Inventory\InventoryQuantity::from((string) $default_movement_quantity)->toString();

$permissions = $admin_permissions ?? [];
$hasPermission = static fn(string $permission): bool => in_array($permission, $permissions, true);
$canReceive = $hasPermission('inventory.stock.receive');
$canIssue = $hasPermission('inventory.stock.issue');
$canAdjust = $hasPermission('inventory.stock.adjust');
$canReserve = $hasPermission('inventory.stock.reserve');
$canCount = $hasPermission('inventory.stock.count');
$canViewDiscounts = $can_view_discounts ?? false;
$canManageDiscounts = $can_manage_discounts ?? false;
$canCatalogManage = $hasPermission('inventory.catalog.manage');
$canCreateCatalogProduct = $hasPermission('catalog.product.create') || $hasPermission('catalog.product.view');
$canWarehouseManage = $hasPermission('inventory.warehouse.manage');
$movementOptions = [];
if ($canReceive) {
    $movementOptions['receipt'] = 'Réception';
}
if ($canIssue) {
    $movementOptions['issue'] = 'Sortie';
}
if ($canAdjust) {
    $movementOptions['adjustment'] = 'Ajustement';
}
$hasOperations = $canReserve || $canCount;
$hasConfig = ($is_super ?? false) || $canCatalogManage || $canWarehouseManage;
$ui = [
    'card' => 'rounded-2xl bg-white ring-1 ring-slate-900/5 shadow-sm dark:bg-slate-900 dark:ring-white/10',
    'field' => 'mt-1.5 block w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 ring-1 ring-inset ring-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-teal-600 dark:bg-slate-950 dark:text-slate-100 dark:ring-slate-700 dark:focus:ring-teal-400',
    'label' => 'block text-sm font-medium text-slate-700 dark:text-slate-300',
    'primary' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 focus-visible:ring-2 focus-visible:ring-teal-600 dark:bg-teal-500 dark:text-slate-950',
    'ghost' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800',
    'th' => 'whitespace-nowrap px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400',
    'td' => 'px-4 py-3.5 align-middle text-slate-700 dark:text-slate-200',
    'num' => 'px-4 py-3.5 text-right align-middle tabular-nums text-slate-700 dark:text-slate-200',
    'tbody' => 'divide-y divide-slate-100 dark:divide-slate-800',
    'row' => 'transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40',
    'empty' => 'px-4 py-14 text-center text-sm text-slate-500 dark:text-slate-400',
    'hint' => 'text-sm text-slate-500 dark:text-slate-400',
    'menuBtn' => 'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800',
    'tab' => 'inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-white/70 aria-selected:bg-white aria-selected:text-teal-800 aria-selected:shadow-sm dark:text-slate-300 dark:aria-selected:bg-slate-700 dark:aria-selected:text-teal-200',
];

$tabs = [];
$tabIcons = ['stock' => 'layers', 'products' => 'package', 'catalog' => 'tag', 'warehouses' => 'warehouse', 'movements' => 'list', 'discounts' => 'tag'];
if ($can_view_stock) {
    if ($simple_dashboard_enabled) {
        $tabs['products'] = ['Vue simplifiée', count($productStocks)];
        $tabs['stock'] = ['Stock détaillé', count($balances)];
    } else {
        $tabs['stock'] = ['Stock détaillé', count($balances)];
        $tabs['products'] = ['Produits', count($productStocks)];
    }
}
if ($can_view_catalog) {
    $tabs['catalog'] = ['Catalogue', count($items)];
}
if ($can_view_warehouses) {
    $tabs['warehouses'] = ['Entrepôts', count($warehouses)];
}
if ($can_view_stock) {
    $tabs['movements'] = ['Mouvements', count($entries)];
}
if ($canViewDiscounts) {
    $tabs['discounts'] = ['Réductions', count($discounts)];
}
$showActions = $canReceive || $canIssue || $canReserve;
$stockCols = $showActions ? 7 : 6;
$productCols = ($canReceive || $canIssue || $canViewDiscounts) ? 7 : 6;
$stockTableColumns = [
    ['key' => 'product', 'label' => 'Produit / SKU', 'sortable' => false],
    ['key' => 'location', 'label' => 'Entrepôt / emplacement', 'sortable' => false],
    ['key' => 'lot', 'label' => 'Lot', 'sortable' => false],
    ['key' => 'physical', 'label' => 'Physique', 'align' => 'right', 'sortable' => false],
    ['key' => 'reserved', 'label' => 'Réservé', 'align' => 'right', 'sortable' => false],
    ['key' => 'available', 'label' => 'Disponible', 'align' => 'right', 'sortable' => false],
];
if ($showActions) {
    $stockTableColumns[] = ['key' => 'actions', 'label' => 'Actions', 'sortable' => false];
}
$productTableColumns = [
    ['key' => 'product', 'label' => 'Produit / SKU', 'sortable' => false],
    ['key' => 'unit', 'label' => 'Unité', 'sortable' => false],
    ['key' => 'physical', 'label' => 'Physique', 'align' => 'right', 'sortable' => false],
    ['key' => 'reserved', 'label' => 'Réservé', 'align' => 'right', 'sortable' => false],
    ['key' => 'available', 'label' => 'Disponible', 'align' => 'right', 'sortable' => false],
    ['key' => 'status', 'label' => 'Indicateur', 'sortable' => false],
];
if ($canReceive || $canIssue || $canViewDiscounts) {
    $productTableColumns[] = ['key' => 'actions', 'label' => 'Actions', 'sortable' => false];
}
$kpis = [];
if ($can_view_catalog || $canCatalogManage) {
    $kpis[] = ['Articles actifs', count($items), 'package'];
}
if ($can_view_warehouses || $canWarehouseManage) {
    $kpis[] = ['Emplacements disponibles', count($locations), 'warehouse'];
}
if ($can_view_stock) {
    $kpis[] = ['Lignes de stock', count($balances), 'layers'];
}
$badgeTone = [
    'receipt' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    'issue' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300',
    'adjustment' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300',
];
$movementLabels = ['receipt' => 'Réception', 'issue' => 'Sortie', 'adjustment' => 'Ajustement'];

$itemSelect = static function (string $name, ?array $sourceItems = null) use ($items, $escape, $ui): void { ?>
    <select name="<?= $escape($name) ?>" required data-item-select class="<?= $ui['field'] ?>">
        <option value="">Choisir un article</option>
        <?php foreach ($sourceItems ?? $items as $item): ?>
            <option value="<?= $escape($item['id']) ?>" data-unit="<?= $escape($item['unit_id']) ?>">
                <?= $escape($item['sku'] . ' — ' . $item['product_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
<?php };
$locationOptions = array_map(
    static fn(array $location): array => ['value' => $location['id'], 'label' => $location['warehouse_name'] . ' / ' . $location['code'] . ' — ' . $location['name']],
    $locations
);
$unitOptions = array_map(static fn(array $unit): array => ['value' => $unit['id'], 'label' => $unit['code'] . ' — ' . $unit['name']], $units);
$variantOptions = array_map(static fn(array $variant): array => ['value' => $variant['id'], 'label' => $variant['sku'] . ' — ' . $variant['product_name'] . ' — ' . $variant['label']], $available_variants);
$discountTargetOptions = [];
$discountTargetLabels = [];
foreach ($discount_targets ?? [] as $targetType => $targets) {
    foreach ($targets as $target) {
        $discountTargetLabels[$targetType][(int) $target['id']] = $target['label'];
        $discountTargetOptions[] = [
            'value' => (string) $target['id'],
            'label' => $target['label'],
            'targetType' => $targetType,
        ];
    }
}
$modalStart = static function (string $id, string $title, string $hint, string $action, string $csrf, string $size = 'max-w-2xl', bool $reloadAfterSave = false) use ($ui, $escape, $icon): void { ?>
    <dialog id="<?= $escape($id) ?>" data-modal aria-labelledby="<?= $escape($id) ?>-title"
        class="m-auto max-h-[92vh] w-[calc(100%-1.5rem)] <?= $size ?> overflow-hidden rounded-3xl bg-white p-0 text-slate-900 shadow-2xl ring-1 ring-slate-900/10 backdrop:bg-slate-950/60 dark:bg-slate-900 dark:text-slate-100 dark:ring-white/10">
        <div data-endpoint="<?= $escape($action) ?>" data-inventory-form <?= $reloadAfterSave ? 'data-reload-after-save' : '' ?> class="flex max-h-[92vh] flex-col">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
            <header class="flex items-start gap-4 px-6 pb-4 pt-6">
                <span
                    class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300"><?= $icon('package', 'h-5 w-5') ?></span>
                <div class="min-w-0 flex-1">
                    <h2 id="<?= $escape($id) ?>-title" class="text-lg font-semibold"><?= $escape($title) ?></h2>
                    <?php if ($hint !== ''): ?>
                        <p class="mt-1 <?= $ui['hint'] ?>"><?= $escape($hint) ?></p><?php endif; ?>
                </div>
                <button type="button" data-modal-close aria-label="Fermer"
                    class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"><?= $icon('x', 'h-5 w-5') ?></button>
            </header>
            <div class="grid gap-4 overflow-y-auto px-6 pb-6 pt-2 sm:grid-cols-2">
            <?php };
$modalEnd = static function (string $buttons) use ($ui): void { ?>
            </div>
            <footer
                class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/80 px-6 py-4 dark:border-slate-800 dark:bg-slate-950/40">
                <button type="button" data-modal-close class="<?= $ui['ghost'] ?>">Annuler</button>
                <?= $buttons ?>
            </footer>
        </div>
    </dialog>
<?php };
$submitButton = static fn(string $label): string =>
    '<button type="button" class="' . $ui['primary'] . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</button>';
$menuStart = static function (string $label, string $iconName) use ($ui, $icon, $escape): void { ?>
    <details data-menu class="relative">
        <summary class="<?= $ui['ghost'] ?> cursor-pointer list-none [&::-webkit-details-marker]:hidden">
            <?= $icon($iconName) ?>     <?= $escape($label) ?>     <?= $icon('chevron', 'h-4 w-4 text-slate-400') ?>
        </summary>
        <div
            class="absolute right-0 z-30 mt-2 w-72 rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-slate-900/10 dark:bg-slate-900 dark:ring-white/10">
        <?php };
$menuEnd = static function (): void {
    echo "        </div>\n    </details>\n";
};
$menuItem = static function (string $modal, string $label, string $iconName) use ($ui, $icon, $escape): void { ?>
            <button type="button" data-open-modal="<?= $escape($modal) ?>"
                class="<?= $ui['menuBtn'] ?>"><?= $icon($iconName, 'h-4 w-4') ?><?= $escape($label) ?></button>
        <?php };
$searchBar = static function (string $attr, string $placeholder, string $model) use ($escape, $icon): string {
    return '<div class="relative w-full sm:max-w-xs"><span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">'
        . $icon('search') . '</span><input type="search" ' . $attr . ' x-model="$store.inventory.searches.' . $model
        . '" placeholder="' . $escape($placeholder) . '" aria-label="' . $escape($placeholder)
        . '" class="block w-full rounded-xl border-0 bg-slate-50 py-2.5 pl-9 pr-3 text-sm ring-1 ring-inset ring-slate-200 focus:outline-none focus:ring-2 focus:ring-teal-600 dark:bg-slate-950 dark:text-white dark:ring-slate-700"></div>';
};
$tableStart = static function (array $headers) use ($ui, $escape): void { ?>
            <div class="overflow-hidden <?= $ui['card'] ?>">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50/80 dark:bg-slate-800/50">
                            <tr><?php foreach ($headers as $header): ?>
                                    <th scope="col" class="<?= $ui['th'] ?><?= ($header[1] ?? false) ? ' text-right' : '' ?>">
                                        <?= $escape($header[0]) ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                    <?php };
$tableEnd = static function (): void {
    echo "    </table></div></div>\n";
};
?>

                    <style>
                        @media (prefers-reduced-motion: reduce) {

                            dialog[data-modal][open],
                            dialog::backdrop {
                                animation: none !important;
                            }
                        }
                    </style>
                    <section x-data
                        x-init="$store.inventory.initialize({balances: <?= $json($balances) ?>, productStocks: <?= $json($productStocks) ?>})"
                        class="mx-auto max-w-7xl space-y-6 px-3 pb-12 sm:px-6" data-inventory-page
                        data-csrf="<?= $escape($csrf_token) ?>">
                        <header class="flex flex-wrap items-end justify-between gap-4 pt-2">
                            <div>
                                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Inventaire
                                </h1>
                                <p class="mt-1 max-w-2xl <?= $ui['hint'] ?>">Suivez vos articles et vos quantités par
                                    emplacement et par lot.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($movementOptions !== []): ?>
                                    <x-button variant="teal" label="Nouveau mouvement" icon="add"
                                        :attributes="['data-open-modal' => 'modal-movement']" />
                                <?php endif; ?>
                                <?php if ($hasOperations): ?>
                                    <?php $menuStart('Opérations', 'sliders'); ?>
                                    <?php if ($canReserve): ?>
                                        <?php $menuItem('modal-reserve', 'Réserver du stock', 'lock'); ?>
                                        <?php $menuItem('modal-reservation-close', 'Libérer ou consommer', 'unlock'); ?>
                                    <?php endif; ?>
                                    <?php if ($canCount): ?>
                                        <?php $menuItem('modal-count-start', 'Ouvrir un comptage', 'clipboard'); ?>
                                        <?php $menuItem('modal-count-reconcile', 'Réconcilier un comptage', 'check'); ?>
                                    <?php endif; ?>
                                    <?php $menuEnd(); ?>
                                <?php endif; ?>
                                <?php if ($hasConfig): ?>
                                    <?php $menuStart('Configuration', 'wrench'); ?>
                                    <button type="button" data-open-inventory-settings
                                        class="<?= $ui['menuBtn'] ?>"><?= $icon('sliders') ?>Paramètres du service</button>
                                    <?php if ($canCatalogManage): ?>
                                        <?php $menuItem('inventory-unit-setup', 'Ajouter une unité', 'tag'); ?>
                                        <?php $menuItem('inventory-item-setup', 'Relier un produit existant', 'link'); ?>
                                        <?php if ($lot_form_items !== []): ?>
                                            <?php $menuItem('inventory-lot-setup', 'Ajouter un lot', 'box'); ?>         <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($canWarehouseManage): ?>
                                        <?php $menuItem('inventory-warehouse-setup', 'Ajouter un entrepôt', 'warehouse'); ?>
                                    <?php endif; ?>
                                    <?php $menuEnd(); ?>
                                <?php endif; ?>
                            </div>
                        </header>
                        <x-container :reduiceable="true" title="Général" open="true" :scrollable="true" size="xxl" :padded="false">

                            <?php if ($simple_dashboard_enabled && $can_view_stock): ?>
                                <section class="grid gap-3 sm:grid-cols-2 my-2 mx-4" aria-label="Indicateurs de stock">
                                    <x-card variant="flat" class="!mb-0 !rounded-2xl !p-4">
                                        <p class="<?= $ui['hint'] ?>">Articles en rupture</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums text-red-600"
                                            x-text="$store.inventory.productStocks.filter(stock => Number(stock.physical_qty) - Number(stock.reserved_qty) <= 0).length">
                                            <?= count(array_filter($productStocks, static fn(array $stock): bool => \App\Inventory\InventoryQuantity::compare($stock['available_qty'], '0') <= 0)) ?>
                                        </p>
                                    </x-card>
                                    <x-card variant="flat" class="!mb-0 !rounded-2xl !p-4">
                                        <p class="<?= $ui['hint'] ?>">Articles sous le seuil d’alerte</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums text-amber-600"
                                            x-text="$store.inventory.productStocks.filter(stock => Number(stock.physical_qty) - Number(stock.reserved_qty) > 0 && Number(stock.physical_qty) - Number(stock.reserved_qty) <= Number(stock.min_qty ?? <?= $json($defaultMinQty) ?>)).length">
                                            <?= count(array_filter($productStocks, static fn(array $stock): bool => \App\Inventory\InventoryQuantity::compare($stock['available_qty'], '0') > 0 && \App\Inventory\InventoryQuantity::compare($stock['available_qty'], $stock['min_qty'] === null ? $defaultMinQty : (string) $stock['min_qty']) <= 0)) ?>
                                        </p>
                                    </x-card>
                                </section>
                            <?php endif; ?>

                            <?php if ($kpis !== []): ?>
                                <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3 mx-4 pb-4" aria-label="Indicateurs de l’inventaire">
                                    <?php foreach ($kpis as [$label, $value, $symbol]): ?>
                                        <x-card variant="flat" class="!mb-0 !rounded-2xl !p-4">
                                            <div class="flex items-center gap-4"><span
                                                    class="grid h-11 w-11 place-items-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300"><?= $icon($symbol, 'h-5 w-5') ?></span>
                                                <div>
                                                    <dt class="<?= $ui['hint'] ?>"><?= $escape($label) ?></dt>
                                                    <dd
                                                        class="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">
                                                        <?= (int) $value ?>
                                                    </dd>
                                                </div>
                                            </div>
                                        </x-card>
                                    <?php endforeach; ?>
                                </dl>
                            <?php endif; ?>
                        </x-container>

                        <?php if ($setup_steps !== []): ?>
                            <x-alert variant="warning" title="À configurer avant les mouvements de stock">
                                <ul class="mt-2 space-y-2">
                                    <?php foreach ($setup_steps as $step):
                                        $canDoStep = ($step['permission'] === 'catalog' && $canCatalogManage) || ($step['permission'] === 'warehouse' && $canWarehouseManage); ?>
                                        <li class="flex flex-wrap items-center gap-2">
                                            <span><?= $escape($step['message']) ?></span>
                                            <?php if ($step['target'] === 'catalog' && $canCreateCatalogProduct): ?>
                                                <a href="/dashboard/catalog" class="text-xs font-semibold underline">Ouvrir les
                                                    produits</a>
                                            <?php elseif ($canDoStep): ?>
                                                <x-button variant="outline" size="sm" label="Ouvrir le formulaire"
                                                    :attributes="['data-open-modal' => $step['target']]" />
                                            <?php else: ?><span class="italic opacity-80">Demandez à un administrateur de
                                                    réaliser cette étape.</span><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </x-alert>
                        <?php endif; ?>

                        <?php if ($tabs !== []): ?>
                            <div class="space-y-5">
                                <div role="tablist" aria-label="Sections de l’inventaire"
                                    class="inline-flex max-w-full gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 dark:bg-slate-800/60">
                                    <?php $first = true;
                                    foreach ($tabs as $key => [$label, $count]): ?>
                                        <button type="button" role="tab" id="tab-<?= $key ?>" data-tab="<?= $key ?>"
                                            aria-controls="panel-<?= $key ?>" aria-selected="<?= $first ? 'true' : 'false' ?>"
                                            tabindex="<?= $first ? '0' : '-1' ?>" class="<?= $ui['tab'] ?>">
                                            <?= $icon($tabIcons[$key] ?? 'list') ?>         <?= $escape($label) ?>
                                            <span
                                                class="rounded-full bg-slate-200/70 px-2 py-0.5 text-xs tabular-nums dark:bg-slate-700"
                                                <?php if ($key === 'stock'): ?>x-text="$store.inventory.balances.length" <?php elseif ($key === 'products'): ?>x-text="$store.inventory.productStocks.length"
                                                <?php endif; ?>><?= $count ?></span>
                                        </button>
                                        <?php $first = false; endforeach; ?>
                                </div>

                                <?php $first = true;
                                foreach ($tabs as $key => $_): ?>
                                    <div role="tabpanel" id="panel-<?= $key ?>" aria-labelledby="tab-<?= $key ?>"
                                        data-tab-panel="<?= $key ?>" <?= $first ? '' : 'hidden' ?>>
                                        <?php if ($key === 'stock'): ?>
                                            <x-table id="inventory-stock-table" variant="plain" :columns="$stockTableColumns"
                                                :page-size="0">
                                                <x-slot:toolbar>
                                                    <div>
                                                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Stock par
                                                            emplacement et lot</h2>
                                                    </div>
                                                    <?= $searchBar('data-stock-search', 'Rechercher un nom, SKU, emplacement ou lot', 'stock') ?>
                                                </x-slot:toolbar>
                                                <x-slot:tbody>
                                                    <template x-for="balance in $store.inventory.balances"
                                                        :key="`${balance.item_id}:${balance.location_id}:${balance.lot_id}`">
                                                        <tr data-stock-row :data-item-id="balance.item_id"
                                                            :data-location-id="balance.location_id" :data-lot-id="balance.lot_id"
                                                            x-show="$store.inventory.matches('stock', $el)"
                                                            class="<?= $ui['row'] ?>">
                                                            <td class="<?= $ui['td'] ?>"><span class="text-xs text-slate-500"
                                                                    x-text="balance.sku"></span><br><span
                                                                    class="font-medium text-slate-900 dark:text-white"
                                                                    x-text="balance.product_name"></span></td>
                                                            <td class="<?= $ui['td'] ?>"><span
                                                                    x-text="`${balance.warehouse_name} / ${balance.location_code}`"></span>
                                                            </td>
                                                            <td class="<?= $ui['td'] ?>" x-text="balance.lot_code"></td>
                                                            <td class="<?= $ui['num'] ?>" x-text="balance.physical_qty"></td>
                                                            <td class="<?= $ui['num'] ?>" x-text="balance.reserved_qty"></td>
                                                            <td class="<?= $ui['num'] ?> font-semibold"
                                                                :class="Number(balance.available_qty) <= 0 ? 'text-red-600' : 'text-emerald-700'"
                                                                x-text="balance.available_qty"></td>
                                                            <?php if ($showActions): ?>
                                                                <td class="<?= $ui['td'] ?> text-right">
                                                                    <x-action-menu>
                                                                        <?php if ($canReceive): ?><button type="button" role="menuitem"
                                                                                class="<?= $ui['menuBtn'] ?>"
                                                                                data-open-modal="modal-movement" data-type="receipt"
                                                                                :data-item="balance.item_id" :data-unit="balance.unit_id"
                                                                                :data-location="balance.location_id"
                                                                                :data-lot="balance.lot_id">Réception</button><?php endif; ?>
                                                                        <?php if ($canIssue): ?><button type="button" role="menuitem"
                                                                                class="<?= $ui['menuBtn'] ?>"
                                                                                data-open-modal="modal-movement" data-type="issue"
                                                                                :data-item="balance.item_id" :data-unit="balance.unit_id"
                                                                                :data-location="balance.location_id"
                                                                                :data-lot="balance.lot_id">Sortie</button><?php endif; ?>
                                                                        <?php if ($canReserve): ?><button type="button" role="menuitem"
                                                                                class="<?= $ui['menuBtn'] ?>"
                                                                                data-open-modal="modal-reserve" :data-item="balance.item_id"
                                                                                :data-unit="balance.unit_id"
                                                                                :data-location="balance.location_id"
                                                                                :data-lot="balance.lot_id">Réserver</button><?php endif; ?>
                                                                    </x-action-menu>
                                                                </td><?php endif; ?>
                                                        </tr>
                                                    </template>
                                                    <tr x-show="$store.inventory.balances.length === 0">
                                                        <td colspan="<?= $stockCols ?>" class="<?= $ui['empty'] ?>">Aucune quantité
                                                            en stock. Réceptionnez un produit pour le voir ici.</td>
                                                    </tr>
                                                    <tr data-stock-empty x-show="$store.inventory.showEmpty('stock')">
                                                        <td colspan="<?= $stockCols ?>" class="<?= $ui['empty'] ?>">Aucun résultat
                                                            pour cette recherche.</td>
                                                    </tr>
                                                </x-slot:tbody>
                                            </x-table>
                                        <?php elseif ($key === 'products'): ?>
                                            <x-table id="inventory-products-table" variant="plain" :columns="$productTableColumns"
                                                :page-size="0">
                                                <x-slot:toolbar>
                                                    <div>
                                                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Totaux
                                                            par produit</h2>
                                                        <p class="<?= $ui['hint'] ?>">Tous emplacements et lots confondus. Les
                                                            produits sans stock restent affichés.</p>
                                                    </div>
                                                    <?= $searchBar('data-products-search', 'Rechercher un produit ou un SKU', 'products') ?>
                                                </x-slot:toolbar>
                                                <x-slot:tbody>
                                                    <template x-for="productStock in $store.inventory.productStocks"
                                                        :key="productStock.item_id">
                                                        <tr data-product-row :data-item-id="productStock.item_id"
                                                            x-show="$store.inventory.matches('products', $el)"
                                                            class="<?= $ui['row'] ?>">
                                                            <td class="<?= $ui['td'] ?>"><span class="text-xs text-slate-500"
                                                                    x-text="productStock.sku"></span><br><span
                                                                    class="font-medium text-slate-900 dark:text-white"
                                                                    x-text="productStock.product_name"></span></td>
                                                            <td class="<?= $ui['td'] ?>" x-text="productStock.unit_code"></td>
                                                            <td class="<?= $ui['num'] ?>" x-text="productStock.physical_qty"></td>
                                                            <td class="<?= $ui['num'] ?>" x-text="productStock.reserved_qty"></td>
                                                            <td class="<?= $ui['num'] ?> font-semibold"
                                                                x-text="productStock.available_qty"></td>
                                                            <td class="<?= $ui['td'] ?>">
                                                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                                                    :class="Number(productStock.available_qty) <= 0 ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : Number(productStock.available_qty) <= Number(productStock.min_qty ?? <?= $json($defaultMinQty) ?>) ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'"
                                                                    x-text="Number(productStock.available_qty) <= 0 ? 'Rupture' : Number(productStock.available_qty) <= Number(productStock.min_qty ?? <?= $json($defaultMinQty) ?>) ? 'Stock faible' : 'Disponible'"></span>
                                                            </td>
                                                            <?php if ($canReceive || $canIssue || $canViewDiscounts): ?>
                                                                <td class="<?= $ui['td'] ?> text-right">
                                                                    <div class="flex flex-col items-end gap-1">
                                                                        <x-action-menu>
                                                                        <?php if ($canReceive): ?><button type="button"
                                                                            role="menuitem"
                                                                            data-open-modal="modal-movement" data-type="receipt"
                                                                            data-quantity="<?= $escape($defaultMovementQuantity) ?>"
                                                                            :data-item="productStock.item_id"
                                                                            :data-unit="productStock.unit_id"
                                                                            class="<?= $ui['menuBtn'] ?>">Ajouter</button><?php endif; ?>
                                                                        <?php if ($canIssue): ?><button type="button"
                                                                            role="menuitem"
                                                                            data-open-modal="modal-movement" data-type="issue"
                                                                            data-quantity="<?= $escape($defaultMovementQuantity) ?>"
                                                                            :data-item="productStock.item_id"
                                                                            :data-unit="productStock.unit_id"
                                                                            class="<?= $ui['menuBtn'] ?>">Retirer</button><?php endif; ?>
                                                                        <?php if ($canViewDiscounts): ?><button type="button"
                                                                            role="menuitem"
                                                                            data-calculate-discount
                                                                            :data-variant-id="productStock.variant_id"
                                                                            class="<?= $ui['menuBtn'] ?>">Prix remisé</button><?php endif; ?>
                                                                        <?php if ($canManageDiscounts): ?><button type="button"
                                                                            role="menuitem"
                                                                            data-open-modal="inventory-discount-setup"
                                                                                data-target-type="product"
                                                                                :data-target-id="productStock.product_id"
                                                                                class="<?= $ui['menuBtn'] ?>">Configurer la réduction</button><?php endif; ?>
                                                                        </x-action-menu>
                                                                        <?php if ($canViewDiscounts): ?><span data-discount-result
                                                                            class="text-xs font-medium text-teal-700 dark:text-teal-300"
                                                                            aria-live="polite"></span><?php endif; ?>
                                                                    </div>
                                                                </td><?php endif; ?>
                                                        </tr>
                                                    </template>
                                                    <tr x-show="$store.inventory.productStocks.length === 0">
                                                        <td colspan="<?= $productCols ?>" class="<?= $ui['empty'] ?>">Aucun produit.
                                                            Ajoutez une unité, puis créez un article stocké.</td>
                                                    </tr>
                                                    <tr data-products-empty x-show="$store.inventory.showEmpty('products')">
                                                        <td colspan="<?= $productCols ?>" class="<?= $ui['empty'] ?>">Aucun résultat
                                                            pour cette recherche.</td>
                                                    </tr>
                                                </x-slot:tbody>
                                            </x-table>
                                        <?php elseif ($key === 'catalog'): ?>
                                            <?php $tableStart([['SKU'], ['Article'], ['Unité'], ['Traçabilité']]); ?>
                                            <tbody class="<?= $ui['tbody'] ?>">
                                                <?php foreach ($items as $item):
                                                    $tracked = $item['tracking_mode'] === 'lot'; ?>
                                                    <tr class="<?= $ui['row'] ?>">
                                                        <td class="<?= $ui['td'] ?>"><?= $escape($item['sku']) ?></td>
                                                        <td class="<?= $ui['td'] ?> font-medium"><?= $escape($item['product_name']) ?>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?>"><?= $escape($item['unit_code']) ?></td>
                                                        <td class="<?= $ui['td'] ?>"><?= $tracked ? 'Par lot' : 'Aucune' ?></td>
                                                    </tr>
                                                <?php endforeach; ?>             <?php if ($items === []): ?>
                                                    <tr>
                                                        <td colspan="4" class="<?= $ui['empty'] ?>">Aucun article actif.</td>
                                                    </tr><?php endif; ?>
                                            </tbody><?php $tableEnd(); ?>
                                        <?php elseif ($key === 'warehouses'): ?>
                                            <?php $tableStart([['Code'], ['Nom'], ['Emplacement par défaut']]); ?>
                                            <tbody class="<?= $ui['tbody'] ?>">
                                                <?php foreach ($warehouses as $warehouse): ?>
                                                    <tr class="<?= $ui['row'] ?>">
                                                        <td class="<?= $ui['td'] ?>"><?= $escape($warehouse['code']) ?></td>
                                                        <td class="<?= $ui['td'] ?>"><?= $escape($warehouse['name']) ?></td>
                                                        <td class="<?= $ui['td'] ?>">
                                                            <?= $escape(($warehouse['default_location_code'] ?? '') . ' — ' . ($warehouse['default_location_name'] ?? '')) ?>
                                                        </td>
                                                    </tr><?php endforeach; ?>
                                                <?php if ($warehouses === []): ?>
                                                    <tr>
                                                        <td colspan="3" class="<?= $ui['empty'] ?>">Aucun entrepôt actif.</td>
                                                    </tr><?php endif; ?>
                                            </tbody><?php $tableEnd(); ?>
                                        <?php elseif ($key === 'movements'): ?>
                                            <?php $tableStart([['Date'], ['Type'], ['Article'], ['Emplacement'], ['Lot'], ['Quantité', true], ['Motif']]); ?>
                                            <tbody class="<?= $ui['tbody'] ?>">
                                                <?php foreach ($entries as $entry):
                                                    $type = (string) $entry['movement_type']; ?>
                                                    <tr class="<?= $ui['row'] ?>">
                                                        <td class="<?= $ui['td'] ?> whitespace-nowrap">
                                                            <?= $escape($entry['operational_at']) ?>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?>"><span
                                                                class="rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset <?= $badgeTone[$type] ?? '' ?>"><?= $escape($movementLabels[$type] ?? $type) ?></span>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?>">
                                                            <?= $escape($entry['sku'] . ' — ' . $entry['product_name']) ?>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?>"><?= $escape($entry['location_code']) ?></td>
                                                        <td class="<?= $ui['td'] ?>"><?= $escape($entry['lot_code']) ?></td>
                                                        <td class="<?= $ui['num'] ?>"><?= $escape($entry['quantity']) ?></td>
                                                        <td class="<?= $ui['td'] ?> max-w-xs truncate"
                                                            title="<?= $escape($entry['reason'] ?? '') ?>">
                                                            <?= $escape($entry['reason'] ?? '') ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>             <?php if ($entries === []): ?>
                                                    <tr>
                                                        <td colspan="7" class="<?= $ui['empty'] ?>">Aucun mouvement enregistré.</td>
                                                    </tr><?php endif; ?>
                                            </tbody><?php $tableEnd(); ?>
                                        <?php elseif ($key === 'discounts'): ?>
                                            <?php if ($canManageDiscounts): ?>
                                                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Gestion des
                                                            réductions</h2>
                                                        <p class="<?= $ui['hint'] ?>">Les remises désactivées et expirées restent
                                                            disponibles dans l’historique.</p>
                                                    </div>
                                                    <button type="button" data-open-modal="inventory-discount-setup"
                                                        class="<?= $ui['primary'] ?>" <?= $discountTargetOptions === [] ? 'disabled' : '' ?>>Créer une réduction</button>
                                                </div>
                                                <?php if ($discountTargetOptions === []): ?>
                                                    <p class="mb-4 <?= $ui['hint'] ?>">Aucune marque, catégorie ou produit n’est disponible
                                                        comme cible.</p><?php endif; ?>
                                            <?php else: ?>
                                                <div class="mb-4">
                                                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">Calculer un prix
                                                        remisé</h2>
                                                    <p class="<?= $ui['hint'] ?>">Choisissez une déclinaison active pour appliquer les
                                                        réductions en vigueur.</p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($discount_products !== []): ?>
                                                <div
                                                    class="mb-5 flex flex-wrap items-end gap-3 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-900/5 dark:bg-slate-950/50 dark:ring-white/10">
                                                    <label class="<?= $ui['label'] ?> min-w-64 flex-1">Déclinaison tarifée
                                                        <select data-discount-calculator-variant class="<?= $ui['field'] ?>">
                                                            <option value="">Choisir une déclinaison</option>
                                                            <?php foreach ($discount_products as $product): ?>
                                                                <option value="<?= $escape($product['variant_id']) ?>">
                                                                    <?= $escape($product['product_name'] . ' — ' . $product['sku'] . ' — ' . $product['price'] . ' ' . $product['currency']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                    <button type="button" data-calculate-selected-discount
                                                        class="<?= $ui['ghost'] ?>">Calculer le prix</button>
                                                    <p data-selected-discount-result
                                                        class="w-full text-sm font-medium text-teal-800 dark:text-teal-200"
                                                        aria-live="polite"></p>
                                                </div>
                                            <?php else: ?>
                                                <p class="mb-4 <?= $ui['hint'] ?>">Aucune déclinaison active avec un prix n’est
                                                    disponible pour le calcul.</p>
                                            <?php endif; ?>
                                            <?php $tableStart([['Cible'], ['Réduction'], ['Période UTC'], ['État'], ['Actions']]); ?>
                                            <tbody class="<?= $ui['tbody'] ?>">
                                                <?php foreach ($discounts as $discount): ?>
                                                    <?php
                                                    $discountValue = $discount['discount_type'] === 'percentage'
                                                        ? $discount['value'] . ' %'
                                                        : $discount['value'] . ' ' . $discount['currency'];
                                                    $targetLabels = ['brand' => 'Marque', 'category' => 'Catégorie', 'product' => 'Produit'];
                                                    $targetLabel = $discountTargetLabels[$discount['target_type']][(int) $discount['target_id']]
                                                        ?? '#' . $discount['target_id'];
                                                    ?>
                                                    <tr class="<?= $ui['row'] ?>">
                                                        <td class="<?= $ui['td'] ?>">
                                                            <?= $escape(($targetLabels[$discount['target_type']] ?? $discount['target_type']) . ' — ' . $targetLabel) ?>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?>"><?= $escape($discountValue) ?> <span
                                                                class="text-xs text-slate-500">(<?= $escape($discount['discount_type']) ?>)</span>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?> whitespace-nowrap text-xs">
                                                            <?= $escape($discount['starts_at']) ?><br>→
                                                            <?= $escape($discount['ends_at']) ?>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?>">
                                                            <?= (bool) $discount['is_active'] ? 'Activée' : 'Désactivée' ?>
                                                        </td>
                                                        <td class="<?= $ui['td'] ?> text-right">
                                                            <?php if ($canManageDiscounts): ?>
                                                            <x-action-menu>
                                                                    <button type="button" role="menuitem" class="<?= $ui['menuBtn'] ?>" data-open-modal="inventory-discount-setup"
                                                                        data-discount-id="<?= $escape($discount['id']) ?>"
                                                                        data-target-type="<?= $escape($discount['target_type']) ?>"
                                                                        data-target-id="<?= $escape($discount['target_id']) ?>"
                                                                        data-discount-type="<?= $escape($discount['discount_type']) ?>"
                                                                        data-value="<?= $escape($discount['value']) ?>"
                                                                        data-currency="<?= $escape($discount['currency'] ?? '') ?>"
                                                                        data-starts-at="<?= $escape(str_replace(' ', 'T', $discount['starts_at'])) ?>"
                                                                        data-ends-at="<?= $escape(str_replace(' ', 'T', $discount['ends_at'])) ?>"
                                                                        data-is-active="<?= (bool) $discount['is_active'] ? '1' : '0' ?>">Modifier</button>
                                                                    <button type="button" role="menuitem" class="<?= $ui['menuBtn'] ?>"
                                                                        data-discount-status="<?= (bool) $discount['is_active'] ? 'deactivate' : 'activate' ?>"
                                                                        data-discount-id="<?= $escape($discount['id']) ?>"><?= (bool) $discount['is_active'] ? 'Désactiver' : 'Activer' ?></button>
                                                            </x-action-menu>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if ($discounts === []): ?>
                                                    <tr>
                                                        <td colspan="5" class="<?= $ui['empty'] ?>">Aucune réduction enregistrée.</td>
                                                    </tr><?php endif; ?>
                                            </tbody><?php $tableEnd(); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php $first = false; endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <?php if ($movementOptions !== []): ?>
                        <?php $modalStart('modal-movement', 'Enregistrer un mouvement', 'Réception, sortie ou ajustement d’un article.', '/dashboard/inventory/movement', $csrf_token); ?>
                        <input type="hidden" name="unit_id" value="" data-unit-field>
                        <x-select name="movement_type" label="Type" :options="$movementOptions" required
                            class="<?= $ui['field'] ?>" />
                        <x-input name="quantity" label="Quantité" type="number" required
                            :attributes="['step' => '0.000001', 'min' => '0.000001', 'inputmode' => 'decimal', 'value' => $defaultMovementQuantity]" />
                        <label class="<?= $ui['label'] ?> sm:col-span-2">Article <?php $itemSelect('item_id'); ?></label>
                        <x-select name="location_id" label="Emplacement" placeholder="Choisir un emplacement"
                            :options="$locationOptions" required class="<?= $ui['field'] ?>" />
                        <label class="<?= $ui['label'] ?>">Lot
                            <select name="lot_id" required data-lot-select class="<?= $ui['field'] ?>">
                                <option value="">Choisir un lot</option>
                                <?php foreach ($lots as $lot): ?>
                                    <option value="<?= $escape($lot['id']) ?>" data-item="<?= $escape($lot['item_id']) ?>">
                                        <?= $escape($lot['lot_code']) ?>
                                    </option><?php endforeach; ?>
                            </select>
                        </label>
                        <x-input name="reference" label="Référence" :attributes="['maxlength' => '190']" />
                        <x-input name="reason" label="Motif" :attributes="['maxlength' => '1000']" />
                        <?php $modalEnd($submitButton('Enregistrer le mouvement')); ?>
                    <?php endif; ?>

                    <?php if ($canReserve): ?>
                        <?php $modalStart('modal-reserve', 'Réserver du stock', 'Met une quantité de côté pour un besoin précis.', '/dashboard/inventory/reserve', $csrf_token); ?>
                        <label class="<?= $ui['label'] ?> sm:col-span-2">Article <?php $itemSelect('item_id'); ?></label>
                        <x-select name="location_id" label="Emplacement" placeholder="Choisir un emplacement"
                            :options="$locationOptions" required class="<?= $ui['field'] ?>" />
                        <label class="<?= $ui['label'] ?>">Lot<select name="lot_id" required data-lot-select
                                class="<?= $ui['field'] ?>">
                                <option value="">Choisir un lot</option><?php foreach ($lots as $lot): ?>
                                    <option value="<?= $escape($lot['id']) ?>" data-item="<?= $escape($lot['item_id']) ?>">
                                        <?= $escape($lot['lot_code']) ?>
                                    </option><?php endforeach; ?>
                            </select></label>
                        <x-input name="quantity" label="Quantité" type="number" required
                            :attributes="['step' => '0.000001', 'min' => '0.000001', 'inputmode' => 'decimal']" />
                        <x-input name="reservation_key" label="Clé de réservation" required
                            :attributes="['maxlength' => '190']" />
                        <?php $modalEnd($submitButton('Réserver')); ?>
                        <?php $modalStart('modal-reservation-close', 'Libérer ou consommer une réservation', 'Libérer remet la quantité en disponible ; consommer la retire du stock.', '/dashboard/inventory/reserve/release', $csrf_token, 'max-w-lg'); ?>
                        <x-input name="reservation_id" label="ID de la réservation" type="number" required
                            :attributes="['min' => '1']" />
                        <x-input name="quantity" label="Quantité" type="number" required
                            :attributes="['step' => '0.000001', 'min' => '0.000001', 'inputmode' => 'decimal']" />
                        <?php $modalEnd('<button type="button" class="' . $ui['ghost'] . '">Libérer</button><button type="button" data-endpoint="/dashboard/inventory/reserve/consume" class="' . $ui['primary'] . '">Consommer</button>'); ?>
                    <?php endif; ?>

                    <?php if ($canCount): ?>
                        <?php $modalStart('modal-count-start', 'Ouvrir un comptage', 'Enregistre le stock système au moment où le comptage démarre.', '/dashboard/inventory/count/start', $csrf_token); ?>
                        <label class="<?= $ui['label'] ?> sm:col-span-2">Article <?php $itemSelect('item_id'); ?></label>
                        <x-select name="location_id" label="Emplacement" placeholder="Choisir un emplacement"
                            :options="$locationOptions" required class="<?= $ui['field'] ?>" />
                        <label class="<?= $ui['label'] ?>">Lot<select name="lot_id" required data-lot-select
                                class="<?= $ui['field'] ?>">
                                <option value="">Choisir un lot</option><?php foreach ($lots as $lot): ?>
                                    <option value="<?= $escape($lot['id']) ?>" data-item="<?= $escape($lot['item_id']) ?>">
                                        <?= $escape($lot['lot_code']) ?>
                                    </option><?php endforeach; ?>
                            </select></label>
                        <x-input name="snapshot_qty" label="Stock système au démarrage" type="number" required
                            :attributes="['step' => '0.000001', 'min' => '0', 'inputmode' => 'decimal']" />
                        <?php $modalEnd($submitButton('Ouvrir le comptage')); ?>
                        <?php $modalStart('modal-count-reconcile', 'Réconcilier un comptage', 'Applique la quantité réellement comptée.', '/dashboard/inventory/count/reconcile', $csrf_token); ?>
                        <x-input name="count_operation_id" label="ID du comptage" type="number" required
                            :attributes="['min' => '1']" />
                        <x-input name="final_counted_qty" label="Quantité finale" type="number" required
                            :attributes="['step' => '0.000001', 'min' => '0', 'inputmode' => 'decimal']" />
                        <label class="<?= $ui['label'] ?> sm:col-span-2">Article <?php $itemSelect('item_id'); ?></label>
                        <x-select name="location_id" label="Emplacement" placeholder="Choisir un emplacement"
                            :options="$locationOptions" required class="<?= $ui['field'] ?>" />
                        <label class="<?= $ui['label'] ?>">Lot<select name="lot_id" required data-lot-select
                                class="<?= $ui['field'] ?>">
                                <option value="">Choisir un lot</option><?php foreach ($lots as $lot): ?>
                                    <option value="<?= $escape($lot['id']) ?>" data-item="<?= $escape($lot['item_id']) ?>">
                                        <?= $escape($lot['lot_code']) ?>
                                    </option><?php endforeach; ?>
                            </select></label>
                        <?php $modalEnd($submitButton('Réconcilier')); ?>
                    <?php endif; ?>

                    <?php if ($canCatalogManage): ?>
                        <?php $modalStart('inventory-unit-setup', 'Ajouter une unité', 'Pièce, kilogramme, litre…', '/dashboard/inventory/catalog/units', $csrf_token, 'max-w-lg'); ?>
                        <x-input name="code" label="Code" required placeholder="PCS"
                            :attributes="['maxlength' => '40', 'pattern' => '[A-Za-z0-9_\-]+']" />
                        <x-input name="name" label="Nom" required placeholder="Pièce"
                            :attributes="['maxlength' => '100']" />
                        <?php $modalEnd($submitButton('Ajouter l’unité')); ?>
                        <?php $modalStart('inventory-item-setup', 'Relier un produit existant à l’inventaire', '', '/dashboard/inventory/catalog/items', $csrf_token); ?>
                        <?php if ($units === []): ?>
                            <p class="sm:col-span-2 <?= $ui['hint'] ?>">Ajoutez d’abord une unité de mesure.</p>
                        <?php elseif ($available_variants === []): ?>
                            <p class="sm:col-span-2 <?= $ui['hint'] ?>">Tous les produits physiques actifs sont déjà reliés, ou
                                aucun produit physique actif n’existe dans le catalogue.</p>
                            <a href="/dashboard/catalog" class="text-sm font-semibold text-teal-700 underline">Ouvrir les
                                produits</a>
                        <?php else: ?>
                            <x-select name="variant_id" label="Produit du catalogue" placeholder="Choisir un produit"
                                :options="$variantOptions" required class="<?= $ui['field'] ?>" />
                            <x-select name="unit_id" label="Unité" placeholder="Choisir une unité" :options="$unitOptions"
                                required class="<?= $ui['field'] ?>" />
                            <x-select name="tracking_mode" label="Traçabilité"
                                :options="['none' => 'Aucune (lot par défaut)', 'lot' => 'Par lot']" required
                                class="<?= $ui['field'] ?>" />
                            <x-checkbox name="allow_negative_stock" label="Autoriser le stock négatif" value="1"
                                parent_class="sm:col-span-2" />
                        <?php endif; ?>
                        <?php $modalEnd($units === [] || $available_variants === [] ? '' : $submitButton('Relier à l’inventaire')); ?>
                        <?php if ($lot_form_items !== []): ?>
                            <?php $modalStart('inventory-lot-setup', 'Ajouter un lot', '', '/dashboard/inventory/catalog/lots', $csrf_token); ?>
                            <label class="<?= $ui['label'] ?>">Article <?php $itemSelect('item_id', $lot_form_items); ?></label>
                            <x-input name="lot_code" label="Code du lot" required placeholder="Code fournisseur ou DEFAULT"
                                :attributes="['maxlength' => '120']" />
                            <?php $modalEnd($submitButton('Créer le lot')); ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($canWarehouseManage): ?>
                        <?php $modalStart('inventory-warehouse-setup', 'Ajouter un entrepôt', 'Son premier emplacement est créé en même temps.', '/dashboard/inventory/warehouses', $csrf_token); ?>
                        <x-input name="code" label="Code de l’entrepôt" required
                            :attributes="['maxlength' => '80', 'pattern' => '[A-Za-z0-9_\-]+']" />
                        <x-input name="name" label="Nom de l’entrepôt" required :attributes="['maxlength' => '190']" />
                        <x-input name="location_code" label="Code de l’emplacement" required
                            :attributes="['maxlength' => '100', 'pattern' => '[A-Za-z0-9_\-]+']" />
                        <x-input name="location_name" label="Nom de l’emplacement" required
                            :attributes="['maxlength' => '190']" />
                        <?php $modalEnd($submitButton('Créer l’entrepôt')); ?>
                    <?php endif; ?>

                    <?php if ($canManageDiscounts): ?>
                        <?php $modalStart('inventory-discount-setup', 'Créer une réduction', 'Les dates et heures sont saisies en UTC.', '/dashboard/inventory/discounts/save', $csrf_token, 'max-w-2xl', true); ?>
                        <input type="hidden" name="discount_id" value="">
                        <label class="<?= $ui['label'] ?>">Type de cible
                            <select name="target_type" required class="<?= $ui['field'] ?>">
                                <option value="brand">Marque</option>
                                <option value="category">Catégorie</option>
                                <option value="product">Produit</option>
                            </select>
                        </label>
                        <label class="<?= $ui['label'] ?>">Cible
                            <select name="target_id" required class="<?= $ui['field'] ?>">
                                <option value="">Choisir une cible</option>
                                <?php foreach ($discountTargetOptions as $targetOption): ?>
                                    <option value="<?= $escape($targetOption['value']) ?>"
                                        data-target-type="<?= $escape($targetOption['targetType']) ?>">
                                        <?= $escape($targetOption['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="<?= $ui['label'] ?>">Type de réduction
                            <select name="discount_type" required class="<?= $ui['field'] ?>">
                                <option value="percentage">Pourcentage</option>
                                <option value="fixed">Montant fixe</option>
                            </select>
                        </label>
                        <x-input name="value" label="Valeur" required type="number"
                            :attributes="['step' => '0.01', 'min' => '0.01', 'inputmode' => 'decimal']" />
                        <x-input name="currency" label="Devise (requise pour montant fixe)"
                            :attributes="['maxlength' => '3', 'pattern' => '[A-Z]{3}', 'placeholder' => 'MAD']" />
                        <x-input name="starts_at" label="Début (UTC)" required type="datetime-local"
                            :attributes="['step' => '1']" />
                        <x-input name="ends_at" label="Fin (UTC)" required type="datetime-local"
                            :attributes="['step' => '1']" />
                        <x-checkbox name="is_active" label="Réduction activée" value="1" :checked="true"
                            parent_class="sm:col-span-2" />
                        <?php $modalEnd($submitButton('Enregistrer la réduction')); ?>
                    <?php endif; ?>

                    <?php if ($hasConfig): ?>
                        <?php $inventory_configuration_return = 'inventory'; ?>
                        @include('partials.dashboard.service-configurations.inventory-service')
                    <?php endif; ?>

                    <script>
                        (() => {
                            const page = document.querySelector('[data-inventory-page]');
                            if (!page) return;

                            const utcToLocalDateTime = (value) => {
                                const date = new Date(`${String(value).replace(' ', 'T')}Z`);
                                if (Number.isNaN(date.getTime())) return '';
                                return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 19);
                            };
                            const localToUtcDateTime = (value) => new Date(value).toISOString().replace(/\.\d{3}Z$/, 'Z');

                            const openModal = (id, data = {}) => {
                                const dialog = document.getElementById(id);
                                const form = dialog?.querySelector('[data-inventory-form]');
                                if (!form) return;
                                const values = {};
                                const fields = [];
                                form.querySelectorAll('[name]').forEach((control) => {
                                    if (control.name === 'csrf_token' || control.name === 'discount_id' || control.matches('[data-unit-field]')) return;
                                    const label = control.closest('label');
                                    const componentLabel = control.id ? form.querySelector(`label[for="${CSS.escape(control.id)}"]`) : null;
                                    const labelText = label?.firstChild?.textContent?.trim() || componentLabel?.textContent?.trim() || control.name;
                                    const isSelect = control instanceof HTMLSelectElement;
                                    const options = isSelect ? Array.from(control.options)
                                        .filter((option) => option.value !== '' || option.textContent.trim() !== '')
                                        .map((option) => ({
                                            value: option.value,
                                            label: option.textContent.trim(),
                                            item: option.dataset.item || null,
                                            targetType: option.dataset.targetType || null
                                        })) : undefined;
                                    const field = {
                                        name: control.name, label: labelText,
                                        type: isSelect ? 'select' : control.type === 'checkbox' ? 'checkbox' : control.type,
                                        required: control.required, min: control.min || undefined, max: control.max || undefined,
                                        step: control.step || undefined, maxLength: control.maxLength > 0 ? control.maxLength : undefined,
                                        pattern: control.pattern || undefined, options
                                    };
                                    if (id === 'inventory-discount-setup' && ['starts_at', 'ends_at'].includes(control.name)) {
                                        field.type = 'datetime-local';
                                        field.step = '1';
                                    }
                                    if (control.matches('[data-lot-select]')) {
                                        field.options = (formValues) => options.filter((option) => !option.item || option.item === String(formValues.item_id));
                                        field.dependsOn = 'item_id';
                                    }
                                    if (control.name === 'target_id') {
                                        field.options = (formValues) => options.filter((option) => !option.targetType || option.targetType === formValues.target_type);
                                        field.dependsOn = 'target_type';
                                    }
                                    fields.push(field);
                                    values[control.name] = control.type === 'checkbox' ? (data.isActive !== undefined ? data.isActive : (control.checked ? control.value : ''))
                                        : data[control.name] !== undefined ? data[control.name]
                                            : id === 'inventory-discount-setup' && ['starts_at', 'ends_at'].includes(control.name) ? utcToLocalDateTime(control.value)
                                                : control.name === 'movement_type' && data.type ? data.type
                                                    : control.name === 'item_id' && data.item ? String(data.item)
                                                        : control.name === 'quantity' && data.quantity ? String(data.quantity)
                                                            : control.name === 'location_id' && data.location ? String(data.location)
                                                                : control.name === 'lot_id' && data.lot ? String(data.lot)
                                                                    : control.value;
                                });
                                if (id === 'modal-reservation-close') {
                                    fields.unshift({
                                        name: 'reservation_action', label: 'Action', type: 'select', required: true,
                                        options: [{ value: 'release', label: 'Libérer' }, { value: 'consume', label: 'Consommer' }]
                                    });
                                    values.reservation_action = 'release';
                                }
                                Form.open({
                                    variant: data.discountId ? 'edit' : 'new',
                                    title: data.discountId ? 'Modifier une réduction' : (dialog.querySelector('h2')?.textContent.trim() || 'Enregistrer'),
                                    message: dialog.querySelector('header p')?.textContent.trim() || '',
                                    confirmText: id === 'modal-reservation-close' ? 'Continuer'
                                        : (form.querySelector('footer button:not([data-modal-close])')?.textContent.trim() || 'Enregistrer'),
                                    cancelText: 'Annuler',
                                    values,
                                    fields,
                                    onSubmit: async (formValues, { close }) => {
                                        const payload = { ...formValues };
                                        delete payload.reservation_action;
                                        if (form.dataset.endpoint === '/dashboard/inventory/discounts/save') {
                                            payload.starts_at = localToUtcDateTime(payload.starts_at);
                                            payload.ends_at = localToUtcDateTime(payload.ends_at);
                                        }
                                        if (data.discountId) payload.discount_id = data.discountId;
                                        const itemControl = form.querySelector('[data-item-select]');
                                        if (payload.item_id && itemControl) {
                                            const selectedItem = itemControl.querySelector(`option[value="${CSS.escape(String(payload.item_id))}"]`);
                                            if (selectedItem?.dataset.unit) payload.unit_id = selectedItem.dataset.unit;
                                        }
                                        const action = formValues.reservation_action;
                                        const result = await Alpine.store('inventory').submit(
                                            action === 'consume' ? '/dashboard/inventory/reserve/consume' : form.dataset.endpoint,
                                            payload,
                                            { confirm: action === 'consume' ? 'Consommer cette réservation retire définitivement la quantité du stock. Continuer ?' : '' }
                                        );
                                        if (result) {
                                            close();
                                            if (form.hasAttribute('data-reload-after-save')) location.reload();
                                        }
                                    }
                                });
                            };

                            document.addEventListener('click', (event) => {
                                const opener = event.target.closest('[data-open-modal]');
                                if (opener) {
                                    opener.closest('details[data-menu]')?.removeAttribute('open');
                                    const data = { ...opener.dataset };
                                    if (data.targetType) {
                                        data.target_type = data.targetType;
                                        data.target_id = data.targetId;
                                    }
                                    if (data.discountId) {
                                        data.isActive = data.isActive === '1' ? '1' : '';
                                        data.target_type = data.targetType;
                                        data.target_id = data.targetId;
                                        data.discount_type = data.discountType;
                                        data.value = data.value;
                                        data.currency = data.currency;
                                        data.starts_at = utcToLocalDateTime(data.startsAt);
                                        data.ends_at = utcToLocalDateTime(data.endsAt);
                                    }
                                    openModal(opener.dataset.openModal, data);
                                }

                                const statusButton = event.target.closest('[data-discount-status]');
                                if (statusButton) {
                                    Alpine.store('inventory').submit('/dashboard/inventory/discounts/status', {
                                        discount_id: statusButton.dataset.discountId,
                                        action: statusButton.dataset.discountStatus
                                    }).then((result) => {
                                        if (result) location.reload();
                                    }).catch((error) => window.Toaster.warning(error.message || 'Impossible de modifier cette réduction.'));
                                }

                                const calculateButton = event.target.closest('[data-calculate-discount]');
                                const calculateSelectedButton = event.target.closest('[data-calculate-selected-discount]');
                                if (calculateButton || calculateSelectedButton) {
                                    const variantSelect = calculateSelectedButton
                                        ? page.querySelector('[data-discount-calculator-variant]')
                                        : null;
                                    const variantId = calculateSelectedButton
                                        ? variantSelect?.value
                                        : calculateButton.dataset.variantId;
                                    const output = calculateSelectedButton
                                        ? page.querySelector('[data-selected-discount-result]')
                                        : calculateButton.closest('tr')?.querySelector('[data-discount-result]');
                                    if (!variantId) {
                                        if (output) output.textContent = 'Choisissez une déclinaison tarifée.';
                                        return;
                                    }
                                    if (output) output.textContent = 'Calcul en cours…';
                                    window.api.post('/dashboard/inventory/discounts/calculate', { variant_id: variantId }, { toast: false })
                                        .then((response) => {
                                            if (!response.success || !response.data) throw new Error(response.message || 'Le calcul du prix remisé a échoué.');
                                            const result = response.data;
                                            if (output) output.textContent = `Prix initial : ${result.original_price} ${result.currency} — Prix remisé : ${result.final_price} ${result.currency} — Économie : ${result.total_saving} ${result.currency} (${result.total_saving_percentage} %) — ${result.applied_discounts.length} réduction(s) appliquée(s).`;
                                        })
                                        .catch((error) => {
                                            if (output) output.textContent = error.message || 'Le calcul du prix remisé a échoué.';
                                            window.Toaster.warning(error.message || 'Le calcul du prix remisé a échoué.');
                                        });
                                }
                                document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
                                    if (!menu.contains(event.target)) menu.removeAttribute('open');
                                });
                            });

                            const tabs = [...page.querySelectorAll('[role=tab]')];
                            const activateTab = (tab, focus = false) => {
                                tabs.forEach((current) => {
                                    const active = current === tab;
                                    current.setAttribute('aria-selected', active ? 'true' : 'false');
                                    current.tabIndex = active ? 0 : -1;
                                    const panel = page.querySelector(`[data-tab-panel="${current.dataset.tab}"]`);
                                    if (panel) panel.hidden = !active;
                                });
                                if (focus) tab.focus();
                                history.replaceState(null, '', '#' + tab.dataset.tab);
                            };
                            tabs.forEach((tab, index) => {
                                tab.addEventListener('click', () => activateTab(tab));
                                tab.addEventListener('keydown', (event) => {
                                    const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
                                    if (move) { event.preventDefault(); activateTab(tabs[(index + move + tabs.length) % tabs.length], true); }
                                    if (event.key === 'Home') { event.preventDefault(); activateTab(tabs[0], true); }
                                    if (event.key === 'End') { event.preventDefault(); activateTab(tabs[tabs.length - 1], true); }
                                });
                            });
                            const fromHash = tabs.find((tab) => '#' + tab.dataset.tab === location.hash);
                            if (fromHash) activateTab(fromHash);
                            const discountTargetType = new URLSearchParams(location.search).get('discount_target_type');
                            const discountTargetId = new URLSearchParams(location.search).get('discount_target_id');
                            if (discountTargetType && discountTargetId && document.getElementById('inventory-discount-setup')) {
                                const discountsTab = tabs.find((tab) => tab.dataset.tab === 'discounts');
                                if (discountsTab) activateTab(discountsTab);
                                openModal('inventory-discount-setup', {
                                    target_type: discountTargetType,
                                    target_id: discountTargetId,
                                    discount_type: 'percentage',
                                    isActive: '1'
                                });
                            }
                        })();
                    </script>