<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$json = static fn(mixed $value): string => htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$has = static fn(string $permission): bool => in_array($permission, $admin_permissions ?? [], true);
$canManage = $has('catalog.category.manage');
$categoryColumns = [
    ['key' => 'category', 'label' => 'Catégorie', 'sortable' => false],
    ['key' => 'actions', 'label' => 'Actions', 'align' => 'right', 'sortable' => false],
];

$ui = [
    'panel' => 'rounded-3xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-black/[0.02] dark:border-slate-800 dark:bg-slate-900 dark:ring-white/[0.03]',
    'field' => 'block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-teal-600 focus:outline-none focus:ring-4 focus:ring-teal-600/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-teal-400 dark:focus:ring-teal-400/20',
    'label' => 'block text-sm font-medium text-slate-700 dark:text-slate-300',
    'primary' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-teal-600/40 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-teal-400 dark:text-slate-950 dark:hover:bg-teal-300',
    'secondary' => 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-400/30 dark:border-slate-700 dark:bg-transparent dark:text-slate-200 dark:hover:bg-slate-800',
    'navLink' => 'rounded-lg px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-white hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
];
?>
@cdn('scripts', '/dashboard/service-assets/inventory-service/catalog-management.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/discount-management.js')
<x-container title="Catégories" :reduiceable="false" size="xxl" title="" class="my-1" :attributes="['x-data'=>'',
        'x-init'=>'$store.catalog.initialize({filters: {search:'.$json($catalog_reference_search ?? '') .'}, categories: '.$json($catalog_categories ?? []) .', brands: '.$json($catalog_brands ?? []) .', categoryRows: '.$json($catalog_reference_result['items'] ?? []) .', categoryTotal: '.(int) ($catalog_reference_result['total'] ?? 0) .', categoryCreationToken: '.$json($catalog_category_creation_token ?? '') .'})',
        'aria-labelledby'=>'categories-title',
        'data-discount-scope'=>'',
        ]">
    <x-slot:actions>
        <x-input name="search" id="category-search" autocomplete="off" type="search"
            placeholder="Rechercher une catégorie…" parent_class="flex flex-col gap-1"
            :attributes="['x-model'=>'$store.catalog.filters.search', '@input.debounce.300ms'=>'$store.catalog.applyReferenceSearch(\'categories\')']" />
        <x-button label="Nouvelle" class="add" icon="add" :passed="!$canManage" click="$store.catalog.openCategory()" />
    </x-slot:actions>
    <x-alert :passed="!($catalog_reference_success ?? '') !== ''" :message="$escape($catalog_reference_success)"
        variant="success" />
    <x-alert :passed="!($catalog_reference_error ?? '') !== ''" :message="$escape($catalog_reference_error)"
        variant="warning" />
    <x-slot:components class="divide-y divide-slate-100 dark:divide-slate-800">
        <x-table id="catalog-categories-table" variant="plain" store="catalog" store-rows-key="categoryRows"
            store-total-key="categoryTotal" store-loading-key="categoryLoading"
            store-query-method="queryCategories" store-autoload :columns="$categoryColumns"
            :page-size="10" :page-sizes="[10, 25, 50, 100]" empty-message="Aucune catégorie"
            no-results-message="Aucun résultat trouvé">
            <x-slot:cell-category>
                <div class="flex min-w-0 items-center gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-100 text-sm font-bold uppercase text-teal-800 dark:bg-teal-400/10 dark:text-teal-300"
                        aria-hidden="true" x-text="(row.name || '?').charAt(0)"></span>
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white" x-text="row.name"></p>
                        <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400"
                            x-text="row.parent_name ? `Sous-catégorie de ${row.parent_name}` : 'Catégorie principale'"></p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                            <span class="rounded-full px-2 py-0.5 font-medium ring-1 ring-inset"
                                :class="Number(row.is_trending) === 1 ? 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20' : 'bg-slate-50 text-slate-600 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600'"
                                x-text="Number(row.is_trending) === 1 ? 'Tendance' : 'Non tendance'"></span>
                            <span x-text="`${Number(row.views_count || 0).toLocaleString('fr-FR')} visite(s)`"></span>
                        </p>
                    </div>
                </div>
            </x-slot:cell-category>
            <x-slot:cell-actions>
                <x-action-menu>
                    <a role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                        :href="`/dashboard/catalog?category_id=${row.id}`">Voir les produits</a>
                    <?php if ($has('inventory.discount.manage')): ?>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                            data-open-discount-modal data-discount-target-type="category"
                            :data-discount-target-id="row.id" :data-discount-target-name="row.name">Configurer les réductions</button>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-500/10"
                            @click="$store.catalog.toggleTrending('category', row)"
                            x-text="Number(row.is_trending) === 1 ? 'Retirer des tendances' : 'Mettre en tendance'"></button>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="$store.catalog.openCategory(row)">Modifier</button>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                            @click="$store.catalog.deleteCategory(row)">Supprimer</button>
                    <?php endif; ?>
                </x-action-menu>
            </x-slot:cell-actions>
        </x-table>
        <?php if ($has('inventory.discount.manage')): ?>
            <dialog data-discount-modal aria-labelledby="category-discounts-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-2xl bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/60 dark:bg-slate-900 dark:text-slate-100">
                <div class="space-y-5 p-5 sm:p-6">
                    <header class="flex items-start justify-between gap-4">
                        <div><h2 id="category-discounts-title" class="text-lg font-semibold">Réductions de la catégorie</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" data-discount-target-name></p></div>
                        <button type="button" data-discount-close class="<?= $ui['secondary'] ?>" aria-label="Fermer">×</button>
                    </header>
                    <div class="space-y-3" data-discount-list aria-live="polite"></div>
                    <button type="button" data-discount-new class="<?= $ui['primary'] ?>">Ajouter une réduction</button>
                    <section data-discount-editor hidden class="border-t border-slate-200 pt-5 dark:border-slate-700">
                        <h3 data-discount-editor-title class="mb-4 font-semibold"></h3>
                        <form data-discount-form class="grid gap-4 sm:grid-cols-2">
                            <input type="hidden" name="discount_id">
                            <label class="<?= $ui['label'] ?>">Type
                                <select name="discount_type" data-discount-type required class="<?= $ui['field'] ?>"><option value="percentage">Pourcentage</option><option value="fixed">Montant fixe</option></select>
                            </label>
                            <label class="<?= $ui['label'] ?>">Taux / montant<input name="value" type="number" min="0.01" step="0.01" required class="<?= $ui['field'] ?>" placeholder="15.00"></label>
                            <label data-discount-currency hidden class="<?= $ui['label'] ?>">Devise<input name="currency" maxlength="3" pattern="[A-Z]{3}" class="<?= $ui['field'] ?>" placeholder="MAD"></label>
                            <label class="<?= $ui['label'] ?>">Début (heure locale)<input name="starts_at" type="datetime-local" step="1" required class="<?= $ui['field'] ?>"></label>
                            <label class="<?= $ui['label'] ?>">Fin (heure locale)<input name="ends_at" type="datetime-local" step="1" required class="<?= $ui['field'] ?>"></label>
                            <label class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300 sm:col-span-2"><input name="is_active" type="checkbox" value="1" checked> Réduction activée</label>
                            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" data-discount-cancel class="<?= $ui['secondary'] ?>">Annuler</button><button type="submit" class="<?= $ui['primary'] ?>">Enregistrer</button></div>
                        </form>
                    </section>
                </div>
            </dialog>
        <?php endif; ?>
    </x-slot:components>
</x-container>