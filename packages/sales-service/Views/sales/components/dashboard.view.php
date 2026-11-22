<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$json = static fn(mixed $value): string => htmlspecialchars((string) json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/* ---------- Icônes ---------- */
$iconPaths = [
    'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'pencil' => '<path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
    'trash' => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
    'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-10 6L2 7"/>',
    'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    'left' => '<path d="m15 18-6-6 6-6"/>',
    'right' => '<path d="m9 18 6-6-6-6"/>',
    'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8H8M16 12H8M13 16H8"/>',
];
$icon = static fn(string $name, string $class = 'h-4 w-4') => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($iconPaths[$name] ?? '') . '</svg>';

$ui = [
    'primary' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-teal-500 dark:text-slate-950 dark:hover:bg-teal-400 dark:focus-visible:ring-offset-slate-900',
    'secondary' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800',
    'danger' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-medium text-rose-700 ring-1 ring-inset ring-rose-300 transition hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-slate-900 dark:text-rose-300 dark:ring-rose-900 dark:hover:bg-rose-950/40',
    'iconBtn' => 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
    'select' => 'block min-h-10 rounded-lg border border-slate-300 bg-white px-2 text-sm font-semibold text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-4 focus:ring-teal-600/15 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100',
];

$orderColumns = [
    ['key' => 'selection', 'label' => '', 'sortable' => false],
    ['key' => 'order', 'label' => 'Commande', 'sortable' => false],
    ['key' => 'status', 'label' => 'Statut', 'sortable' => false],
    ['key' => 'items', 'label' => 'Articles', 'sortable' => false],
    ['key' => 'total', 'label' => 'Total', 'align' => 'right', 'sortable' => false],
    ['key' => 'actions', 'label' => 'Actions', 'align' => 'right', 'sortable' => false],
];

$initData = $json([
    'filters' => $sales_filters ?? [],
    'orders' => $sales_orders ?? [],
    'applications' => $sales_applications ?? [],
    'products' => $sales_products ?? [],
    'statistics' => $sales_statistics ?? [],
    'page' => (int) ($sales_page ?? 1),
    'pages' => (int) ($sales_pages ?? 1),
    'total' => (int) ($sales_total ?? 0),
    'perPage' => (int) ($sales_per_page ?? 10),
    'canManage' => (bool) ($sales_can_manage ?? false),
    'canSetup' => (bool) ($sales_can_setup ?? false),
    'setupRequired' => (bool) ($sales_setup_required ?? false),
    'mailEnabled' => (bool) ($sales_mail_enabled ?? false),
]);
?>
<style>
    [x-cloak] {
        display: none !important;
    }
</style>

<?php if ($sales_catalog_statistics_enabled ?? false): ?>
    <div class="mx-auto flex max-w-7xl justify-end px-4 pt-5 sm:px-6 lg:px-8">
        <a href="/dashboard/sales/statistics" class="<?= $ui['secondary'] ?>">Statistiques des produits</a>
    </div>
<?php endif; ?>
<x-container :reduiceable="true" class="space-y-6 my-2" title="Statistiques générales" size="xxl">
    <?php if (($sales_can_setup ?? false) && ($sales_setup_required ?? false)): ?>
        <section x-show="$store.sales.canSetup && $store.sales.setupRequired" x-cloak
            class="flex flex-col my-2 gap-4 rounded-2xl border border-teal-200 bg-teal-50/70 p-5 dark:border-teal-900 dark:bg-teal-950/30 sm:flex-row sm:items-center sm:justify-between">
            <div class="my-2">
                <h2 class="font-semibold text-slate-950 dark:text-white">Boutique en ligne</h2>
                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">Relie le
                    catalogue
                    et les stocks locaux à Ventes. La devise vient du paramètre global APP_CURRENCY. Le
                    paiement
                    en ligne reste désactivé.</p>
            </div>
            <button type="button" class="<?= $ui['primary'] ?>" :disabled="$store.sales.saving"
                @click="$store.sales.setupStorefront()">Configurer la boutique</button>
        </section>
    <?php endif; ?>

    <p
        class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm leading-6 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
        <?= $escape($sales_scope_notice ?? '') ?>
    </p>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 my-2" aria-label="Statistiques des ventes">
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-slate-500 dark:text-slate-400">Commandes</p>
            <p class="mt-1 font-bold tabular-nums" x-text="$store.sales.statistics.orders"></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-slate-500 dark:text-slate-400">Pièces vendues</p>
            <p class="mt-1 font-bold tabular-nums" x-text="$store.sales.number($store.sales.statistics.pieces)">
            </p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-slate-500 dark:text-slate-400">Chiffre d’affaires par devise</p>
            <template x-for="[currency, amount] in $store.sales.revenueEntries()" :key="currency">
                <p class="mt-1 font-bold tabular-nums" x-text="$store.sales.money(amount, currency)">
                </p>
            </template>
            <p class="mt-1 text-sm" x-show="$store.sales.revenueEntries().length === 0">—</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-slate-500 dark:text-slate-400">Panier moyen</p>
            <template x-for="[currency, amount] in $store.sales.averageEntries()" :key="currency">
                <p class="mt-1 font-bold tabular-nums" x-text="$store.sales.money(amount, currency)">
                </p>
            </template>
            <p class="mt-1 text-sm" x-show="$store.sales.averageEntries().length === 0">—</p>
        </div>
    </section>

    <div class="flex flex-wrap gap-2 my-2" aria-label="Répartition par état"
        x-show="$store.sales.statusEntries().length > 0">
        <template x-for="[key, count] in $store.sales.statusEntries()" :key="key">
            <span
                class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                x-text="`${$store.sales.statusLabel(key)} : ${count}`"></span>
        </template>
    </div>
</x-container>
<x-container title="Filtres" :reduiceable="false" size="xxl" class="my-1" :attributes="[
    'x-data' => '$store.sales.initialize(' . $initData . ')',
    'data-sales-dashboard-root' => '',
]">
    <x-slot:actions>
        <x-container :reduiceable="true">
            <x-slot:actions>
                <a href="/suivi-commande" class="<?= $ui['secondary'] ?>">Suivi client</a>
                <?php if ($sales_can_manage ?? false): ?>
                    <button type="button" class="<?= $ui['danger'] ?>"
                        :disabled="$store.sales.selected.length === 0 || $store.sales.saving"
                        x-show="$store.sales.selected.length > 0 && !$store.sales.saving"
                        @click="$store.sales.deleteSelected()">
                        <?= $icon('trash') ?>Supprimer:
                        <span x-text="$store.sales.selected.length"></span>
                    </button>
                <?php endif; ?>
                <x-input name="search" id="sales-search" autocomplete="off" type="search"
                    placeholder="N° commande, client, article" parent_class="flex flex-col gap-1"
                    :attributes="['x-model' => '$store.sales.filters.search', '@input.debounce.300ms' => '$store.sales.applyFilters()']" />
                <x-select parent_class="flex flex-col gap-1" xModel="$store.sales.filters.status"
                    :attributes="['@change' => '$store.sales.applyFilters()']"
                    :selected="(string) ($sales_status ?? 'all')" :options="[
                ['label' => 'Tous les statuts', 'value' => 'all'],
                ['label' => 'En attente', 'value' => 'pending'],
                ['label' => 'Confirmée', 'value' => 'confirmed'],
                ['label' => 'En traitement', 'value' => 'processing'],
                ['label' => 'Prête', 'value' => 'ready'],
                ['label' => 'Terminée', 'value' => 'completed'],
                ['label' => 'Annulée', 'value' => 'cancelled'],
            ]" />
                <x-button label="Réinitialiser" class="refresh" icon="refresh" click="$store.sales.clearFilters()" />
                <x-button label="Nouvelle" class="add" icon="add" :passed="!($sales_can_manage ?? false)"
                    click="$store.sales.openNewOrder()" />
            </x-slot:actions>
            <x-slot:components class="space-y-6">
                <div class="flex flex-wrap items-end gap-2" aria-label="Filtres avancés">
                    <x-input name="date_from" id="sales-date-from" type="date" label="Du"
                        parent_class="flex flex-col gap-1"
                        :attributes="['x-model' => '$store.sales.filters.date_from', '@change' => '$store.sales.applyFilters()']" />
                    <x-input name="date_to" id="sales-date-to" type="date" label="Au" parent_class="flex flex-col gap-1"
                        :attributes="['x-model' => '$store.sales.filters.date_to', '@change' => '$store.sales.applyFilters()']" />

                    <x-input name="min_total" id="sales-min-total" type="number" label="Montant min."
                        parent_class="flex flex-col gap-1"
                        :attributes="['min' => '0', 'step' => '0.01', 'x-model' => '$store.sales.filters.min_total', '@change' => '$store.sales.applyFilters()']" />
                    <x-input name="max_total" id="sales-max-total" type="number" label="Montant max."
                        parent_class="flex flex-col gap-1"
                        :attributes="['min' => '0', 'step' => '0.01', 'x-model' => '$store.sales.filters.max_total', '@change' => '$store.sales.applyFilters()']" />
                    <x-input name="min_pieces" id="sales-min-pieces" type="number" label="Pièces min."
                        parent_class="flex flex-col gap-1"
                        :attributes="['min' => '0', 'step' => '0.01', 'x-model' => '$store.sales.filters.min_pieces', '@change' => '$store.sales.applyFilters()']" />
                    <x-input name="max_pieces" id="sales-max-pieces" type="number" label="Pièces max."
                        parent_class="flex flex-col gap-1"
                        :attributes="['min' => '0', 'step' => '0.01', 'x-model' => '$store.sales.filters.max_pieces', '@change' => '$store.sales.applyFilters()']" />
                </div>
            </x-slot:components>
        </x-container>
    </x-slot:actions>

    <x-alert :passed="($sales_success ?? '') === ''" :message="$escape($sales_success ?? '')" variant="success" />
    <x-alert :passed="($sales_error ?? '') === ''" :message="$escape($sales_error ?? '')" variant="danger" />

    <x-slot:components>
        <section aria-label="Liste des commandes">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Toutes les commandes</h2>
                <span
                    class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300"><span
                        x-text="$store.sales.total"></span> résultat(s)</span>
            </div>

            <div class="mt-5">
                <x-table id="sales-orders" variant="plain" store="sales" store-rows-key="orders"
                    store-total-key="total" store-loading-key="loading" store-query-method="query" store-autoload
                    :columns="$orderColumns" :page-size="10" :page-sizes="[10, 25, 50, 100]"
                    empty-message="Aucune commande">
                    <x-slot:tbody>
                        <template x-for="order in $store.sales.orders" :key="order.id">
                            <tr class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                <td class="px-4 py-3.5">
                                    <?php if ($sales_can_manage ?? false): ?>
                                        <input type="checkbox" :checked="$store.sales.isSelected(order.id)"
                                            :aria-label="`Sélectionner la commande #${order.id}`"
                                            @change="$store.sales.toggleSelection(order.id)"
                                            class="h-4 w-4 rounded border-slate-300 text-teal-700">
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                            <?= $icon('receipt') ?>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-slate-900 dark:text-white"
                                                x-text="`#${order.id}`"></p>
                                            <p class="text-xs text-slate-500" x-show="order.public_reference"
                                                x-text="order.public_reference"></p>
                                            <p class="text-xs text-slate-500"
                                                x-text="`${order.application_name} · ${order.created_at}`"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5">
                                    <?php if ($sales_can_manage ?? false): ?>
                                        <select x-show="order.orders_enabled" class="<?= $ui['select'] ?> max-w-44"
                                            :aria-label="`Modifier le statut de la commande #${order.id}`"
                                            :disabled="$store.sales.saving"
                                            @change="$store.sales.setOrderStatus(order, $event.target.value)">
                                            <option :value="order.status" selected
                                                x-text="$store.sales.statusLabel(order.status)"></option>
                                            <template x-for="transition in order.available_transitions || []"
                                                :key="transition.status">
                                                <option :value="transition.status"
                                                    x-text="$store.sales.statusLabels[transition.status] || transition.label">
                                                </option>
                                            </template>
                                        </select>
                                    <?php endif; ?>
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        <?= ($sales_can_manage ?? false) ? 'x-show="!order.orders_enabled"' : '' ?>
                                        :class="$store.sales.statusClass(order.status)">
                                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                        <span x-text="$store.sales.statusLabel(order.status)"></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-sm text-slate-600 dark:text-slate-300"
                                    x-text="`${$store.sales.itemsCount(order)} article(s)`"></td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-right font-semibold tabular-nums text-slate-900 dark:text-white"
                                    x-text="$store.sales.money(order.total_minor, order.currency)"></td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" class="<?= $ui['iconBtn'] ?>" title="Voir"
                                            aria-label="Voir" @click="$store.sales.openView(order)">
                                            <?= $icon('eye') ?>
                                        </button>
                                        <?php if ($sales_can_manage ?? false): ?>
                                            <button type="button" class="<?= $ui['iconBtn'] ?>" title="Modifier"
                                                aria-label="Modifier" x-show="order.orders_enabled"
                                                @click="$store.sales.openOrder(order)">
                                                <?= $icon('pencil') ?>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="<?= $ui['iconBtn'] ?>" title="Télécharger le PDF"
                                            aria-label="Télécharger le PDF" x-show="order.orders_enabled"
                                            @click="$store.sales.downloadPdf(order)">
                                            <?= $icon('download') ?>
                                        </button>
                                        <?php if ($sales_can_manage ?? false): ?>
                                            <button type="button" class="<?= $ui['iconBtn'] ?>" title="Envoyer un e-mail"
                                                aria-label="Envoyer un e-mail"
                                                x-show="order.orders_enabled && $store.sales.mailEnabled"
                                                @click="$store.sales.openEmail(order)">
                                                <?= $icon('mail') ?>
                                            </button>
                                            <button type="button"
                                                class="<?= $ui['iconBtn'] ?> hover:!bg-red-50 hover:!text-red-700 dark:hover:!bg-red-500/10 dark:hover:!text-red-300"
                                                title="Supprimer" aria-label="Supprimer"
                                                @click="$store.sales.deleteOrder(order)">
                                                <?= $icon('trash') ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="$store.sales.orders.length === 0">
                            <td class="px-4 py-14 text-center text-slate-500 dark:text-slate-400" colspan="6">
                                <span
                                    class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800">
                                    <?= $icon('receipt', 'h-6 w-6') ?>
                                </span>
                                Aucune commande ne correspond aux filtres. Les commandes apparaîtront ici
                                lorsqu’une
                                application Ventes active en aura créé.
                            </td>
                        </tr>
                    </x-slot:tbody>
                </x-table>
            </div>

        </section>
    </x-slot:components>
    <x-slot:components>
        <div x-show="$store.sales.viewOrder" x-cloak @click.self="$store.sales.closeView()"
            @keydown.escape.stop.prevent="$store.sales.closeView()"
            class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-4">
            <section role="dialog" aria-modal="true" aria-labelledby="sales-order-modal-title" tabindex="-1"
                class="max-h-[90vh] w-full max-w-3xl space-y-5 overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl ring-1 ring-slate-900/10 dark:bg-slate-900 dark:ring-white/10 sm:p-6">
                <template x-if="$store.sales.viewOrder">
                    <div class="space-y-5">
                        <header class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 id="sales-order-modal-title"
                                    class="text-lg font-semibold text-slate-900 dark:text-white"
                                    x-text="`Commande #${$store.sales.viewOrder.id}`"></h2>
                                <p class="mt-1 text-xs text-slate-500" x-show="$store.sales.viewOrder.public_reference"
                                    x-text="`Référence client : ${$store.sales.viewOrder.public_reference}`"></p>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"
                                    x-text="`${$store.sales.viewOrder.application_name} · ${$store.sales.viewOrder.profile} · ${$store.sales.viewOrder.created_at}`">
                                </p>
                            </div>
                            <button type="button" class="<?= $ui['iconBtn'] ?> shrink-0" aria-label="Fermer"
                                @click="$store.sales.closeView()"><?= $icon('x') ?></button>
                        </header>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="$store.sales.statusClass($store.sales.viewOrder.status)">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                <span x-text="$store.sales.statusLabel($store.sales.viewOrder.status)"></span>
                            </span>
                            <p class="text-lg font-bold tabular-nums"
                                x-text="$store.sales.money($store.sales.viewOrder.total_minor, $store.sales.viewOrder.currency)">
                            </p>
                        </div>

                        <section>
                            <h3 class="font-semibold text-slate-900 dark:text-white">Articles</h3>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <template x-for="(item, index) in $store.sales.viewOrder.items || []" :key="index">
                                    <div class="rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-950">
                                        <p class="font-medium" x-text="item.label_snapshot"></p>
                                        <p class="mt-1 text-xs text-slate-500"
                                            x-text="`${item.quantity} ${item.unit} · ${item.item_reference} · ${$store.sales.money(item.line_amount_minor, item.currency)}`">
                                        </p>
                                    </div>
                                </template>
                            </div>
                        </section>

                        <section>
                            <h3 class="font-semibold text-slate-900 dark:text-white">Client et livraison</h3>
                            <dl
                                class="mt-2 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-950 sm:grid-cols-2">
                                <div>
                                    <dt class="text-slate-500">Nom</dt>
                                    <dd class="font-medium"
                                        x-text="$store.sales.customer($store.sales.viewOrder).name || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Contact</dt>
                                    <dd class="font-medium"
                                        x-text="`${$store.sales.customer($store.sales.viewOrder).phone || '—'} · ${$store.sales.customer($store.sales.viewOrder).email || '—'}`">
                                    </dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-500">Mode</dt>
                                    <dd class="font-medium"
                                        x-text="$store.sales.fulfillmentLabel($store.sales.viewOrder)"></dd>
                                </div>
                                <div class="sm:col-span-2"
                                    x-show="$store.sales.orderNote($store.sales.viewOrder) !== ''">
                                    <dt class="text-slate-500">Remarque</dt>
                                    <dd class="font-medium" x-text="$store.sales.orderNote($store.sales.viewOrder)">
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <p class="text-sm text-slate-500"
                            x-show="$store.sales.canManage && $store.sales.viewOrder.orders_enabled && !$store.sales.mailEnabled">
                            Le mailing est désactivé dans la configuration.</p>
                    </div>
                </template>
            </section>
        </div>
    </x-slot:components>
</x-container>
<script src="/dashboard/service-assets/sales-service/sales-dashboard.js" defer></script>