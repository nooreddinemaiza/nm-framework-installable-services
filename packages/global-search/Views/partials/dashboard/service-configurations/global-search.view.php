@cdn('scripts', '/dashboard/service-assets/global-search/search-config.js')
<?php
$search = app()->get(\NM\Services\GlobalSearch\Search\GlobalSearchService::class);
$search_types = $search->configuredTypes();
$search_dashboard_types = $search->configuredTypes('dashboard');
$search_public_options = array_keys(\NM\Services\GlobalSearch\Search\SearchRegistry::all('public'));
$search_dashboard_options = array_keys(\NM\Services\GlobalSearch\Search\SearchRegistry::all('dashboard'));
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$typeLabels = [
    'products' => 'Produits',
    'categories' => 'Catégories',
    'brands' => 'Marques',
    'articles' => 'Articles',
    'article_categories' => 'Catégories d’articles',
    'sales_orders' => 'Commandes',
    'online_payment_attempts' => 'Paiements en ligne',
    'quotes' => 'Devis',
    'cash_registers' => 'Caisses',
    'cash_sessions' => 'Sessions de caisse',
    'pages' => 'Pages',
    'users' => 'Utilisateurs',
    'groups' => 'Groupes',
];
$scopeGroups = [
    'public' => [
        'Catalogue' => ['products', 'categories', 'brands'],
        'Contenus' => ['articles'],
    ],
    'dashboard' => [
        'Catalogue' => ['products', 'categories', 'brands'],
        'Contenus' => ['articles', 'article_categories', 'pages'],
        'Ventes' => ['sales_orders', 'quotes'],
        'Paiements' => ['online_payment_attempts'],
        'Caisse' => ['cash_registers', 'cash_sessions'],
        'Administration' => ['users', 'groups'],
    ],
];
$scopeOptions = [
    'public' => $search_public_options ?? array_keys($search_types ?? []),
    'dashboard' => $search_dashboard_options ?? array_keys($search_dashboard_types ?? []),
];
$scopeValues = [
    'public' => $search_types ?? [],
    'dashboard' => $search_dashboard_types ?? [],
];
foreach ($scopeGroups as $scope => $groups) {
    $scopeGroups[$scope] = array_filter(array_map(
        static fn(array $types): array => array_values(array_intersect($types, $scopeOptions[$scope])),
        $groups
    ));
    $scopeValues[$scope] = array_values(array_intersect($scopeOptions[$scope], $scopeValues[$scope]));
}
?>
<dialog id="search-configuration" data-service-configuration="global-search"
    aria-labelledby="search-configuration-title"
    class="m-auto max-h-[92vh] w-[calc(100%-1.5rem)] max-w-5xl overflow-y-auto rounded-3xl bg-white p-6 text-slate-900 shadow-2xl backdrop:bg-slate-950/60 dark:bg-slate-900 dark:text-slate-100">
    <div class="border-b border-slate-200 pb-4 dark:border-slate-700">
        <h2 id="search-configuration-title" class="text-lg font-semibold">Configurer les contenus recherchés</h2>
    </div>
    <p class="mb-4 text-sm leading-6 text-slate-600 dark:text-slate-300">
        Choisissez les contenus recherchables sur le site public et dans le tableau de bord. Les types des autres
        services ne sont proposés que dans le tableau de bord et restent soumis aux permissions de l’administrateur.
    </p>
    <form id="search-config-form" action="/dashboard/settings/services/search" method="post"
        data-search-config-form>
        <?php foreach (['public', 'dashboard'] as $scope): ?>
            <input type="hidden" name="<?= $scope ?>_types"
                value="<?= $escape(implode(',', $scopeValues[$scope])) ?>"
                data-search-config-value="<?= $scope ?>">
        <?php endforeach; ?>
        <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">

        <x-container :reduiceable="true" open size="xxl" title="" maxHeight="340px" class="my-1">
            <x-slot:actions>
                <input type="search" data-search-config-search placeholder="Nom ou catégorie…"
                    aria-label="Rechercher un contenu"
                    class="min-w-56 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-700">
            </x-slot:actions>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                La recherche publique conserve les contenus publics. Les contenus opérationnels sont indexés uniquement
                pour le tableau de bord.
            </p>
        </x-container>

        <x-container :reduiceable="false" :scrollable="true" size="xxl" title="" maxHeight="50vh" class="my-1">
            <div class="space-y-4">
                <?php foreach (['public', 'dashboard'] as $scope): ?>
                    <section data-search-config-scope="<?= $scope ?>"
                        class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                                <?= $scope === 'public' ? 'Sur le site public' : 'Dans le tableau de bord' ?>
                            </h3>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" data-search-config-select-all="<?= $scope ?>"
                                    class="rounded-lg border border-blue-200 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-900/30">
                                    Tout sélectionner
                                </button>
                                <button type="button" data-search-config-clear="<?= $scope ?>"
                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">
                                    Tout retirer
                                </button>
                                <button type="button" data-search-config-reset="<?= $scope ?>"
                                    class="rounded-lg border border-amber-200 px-3 py-1.5 text-sm font-medium text-amber-700 dark:border-amber-800 dark:text-amber-300">
                                    Réinitialiser
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" data-search-config-all="<?= $scope ?>"
                                    class="h-4 w-4 cursor-pointer rounded border-slate-300 text-blue-600">
                                Tout sélectionner
                            </label>
                            <span><span data-search-config-count="<?= $scope ?>"><?= count($scopeValues[$scope]) ?></span> /
                                <?= count($scopeOptions[$scope]) ?> sélectionné(s)</span>
                        </div>

                        <?php foreach ($scopeGroups[$scope] as $groupName => $types): ?>
                            <section data-search-config-group="<?= $escape($groupName) ?>"
                                class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                        <?= $escape($groupName) ?>
                                    </h4>
                                    <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                        <input type="checkbox" data-search-config-group-all
                                            class="h-4 w-4 cursor-pointer rounded border-slate-300 text-blue-600">
                                        Tout le groupe
                                    </label>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($types as $type): ?>
                                        <label data-search-config-option
                                            class="group/search-type relative flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 transition-colors hover:border-blue-300 hover:bg-blue-50/50 has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-blue-500/50 dark:hover:bg-slate-800 dark:has-[:checked]:border-blue-500/60 dark:has-[:checked]:bg-blue-500/10">
                                            <input type="checkbox" value="<?= $escape($type) ?>"
                                                data-search-config-type
                                                <?= in_array($type, $scopeValues[$scope], true) ? 'checked' : '' ?>
                                                class="h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm font-medium text-slate-800 dark:text-slate-100">
                                                <?= $escape($typeLabels[$type]) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </x-container>

        <div class="mt-4">
            <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                <button type="button" onclick="this.closest('dialog').close()"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-600 dark:text-slate-200">
                    Annuler
                </button>
                <button type="submit" form="search-config-form"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    Enregistrer
                </button>
            </div>
        </div>
    </form>
</dialog>
