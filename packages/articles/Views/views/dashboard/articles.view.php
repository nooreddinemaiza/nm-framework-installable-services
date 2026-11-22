<?php
$json = static fn(mixed $value): string => htmlspecialchars(
    (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$listing = $article_listing ?? [
    'items' => [],
    'categories' => [],
    'statistics' => [],
    'popular' => [],
    'total' => 0,
    'page' => 1,
    'pages' => 1,
    'per_page' => 10,
];
$initialData = [
    ...$listing,
    'filters' => $article_filters ?? [],
    'canCreate' => $article_can_create ?? false,
    'canUpdate' => $article_can_update ?? false,
    'canPublish' => $article_can_publish ?? false,
    'canDelete' => $article_can_delete ?? false,
    'canManageCategories' => $article_can_manage_categories ?? false,
    'canViewStats' => $article_can_view_stats ?? false,
];
$categoryOptions = [
    ['id' => 0, 'name' => 'Toutes les catégories'],
    ...($listing['categories'] ?? []),
];
$columns = [
    ['key' => 'article', 'label' => 'Article', 'sortable' => false],
    ['key' => 'category', 'label' => 'Catégories', 'sortable' => false],
    ['key' => 'status', 'label' => 'État', 'sortable' => false],
    ['key' => 'author', 'label' => 'Auteur', 'sortable' => false],
    ['key' => 'updated', 'label' => 'Modifié le', 'sortable' => false],
    ...(!empty($article_can_view_stats) ? [['key' => 'views', 'label' => 'Vues', 'sortable' => false]] : []),
    ['key' => 'actions', 'label' => 'Actions', 'align' => 'right', 'sortable' => false],
];
$ui = [
    'panel' => 'overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950',
    'menu' => 'flex w-full cursor-pointer items-center rounded-md px-2.5 py-1.5 text-left text-sm text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800',
    'menuWarn' => 'flex w-full cursor-pointer items-center rounded-md px-2.5 py-1.5 text-left text-sm text-amber-700 transition-colors hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-400/10',
    'menuDanger' => 'flex w-full cursor-pointer items-center rounded-md px-2.5 py-1.5 text-left text-sm text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-400/10',
    'cell' => 'px-4 py-3 align-middle',
    'muted' => 'text-zinc-600 dark:text-zinc-400',
];
?>
@cdn('scripts', '/dashboard/service-assets/articles/articles-management.js')

<x-container title="Articles" :reduiceable="false" size="xxl" class="my-1"
    :attributes="['x-data' => '$store.articles.initialize(' . $json($initialData) . ')']">
    <x-slot:actions>
        <x-input name="search" id="articles-search" autocomplete="off" type="search"
            placeholder="Rechercher un article…" parent_class="flex flex-col gap-1"
            :attributes="['x-model' => '$store.articles.filters.search', '@input.debounce.300ms' => '$store.articles.applyFilters()']" />
        <x-select parent_class="flex flex-col gap-1" xModel="$store.articles.filters.status"
            :attributes="['@change' => '$store.articles.applyFilters()']" :options="[
                ['label' => 'Tous les états', 'value' => 'all'],
                ['label' => 'Brouillons', 'value' => 'draft'],
                ['label' => 'Publiés', 'value' => 'published'],
                ['label' => 'Archivés', 'value' => 'archived'],
            ]" />
        <x-select parent_class="flex flex-col gap-1" xModel="$store.articles.filters.category_id"
            :attributes="['@change' => '$store.articles.applyFilters()']"
            :options="$categoryOptions"
            option-value="id" option-label="name" :selected="(string) ($article_filters['category_id'] ?? '0')" />
        <x-select parent_class="flex flex-col gap-1" xModel="$store.articles.filters.sort"
            :attributes="['@change' => '$store.articles.applyFilters()']" :options="[
                ['label' => 'Modification récente', 'value' => 'updated'],
                ['label' => 'Titre', 'value' => 'title'],
                ...(!empty($article_can_view_stats) ? [['label' => 'Popularité', 'value' => 'views']] : []),
            ]" />
        <x-button label="Réinitialiser" class="refresh" icon="refresh" click="$store.articles.resetFilters()" />
        <?php if (!empty($article_can_create)): ?>
            <x-button label="Nouvel article" class="add" icon="new" click="$store.articles.openNew()" />
        <?php endif; ?>
        <?php if (!empty($article_can_view_stats)): ?>
            <a href="/dashboard/articles/statistics" class="inline-flex min-h-10 items-center rounded-md border border-zinc-300 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-900">Statistiques et classement</a>
        <?php endif; ?>
    </x-slot:actions>

    <x-slot:components>
        <?php if (!empty($article_can_view_stats)): ?>
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Statistiques des articles">
                <article class="rounded-md border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Total</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="Number($store.articles.statistics.total || 0).toLocaleString('fr-FR')"></p>
                </article>
                <article class="rounded-md border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Publiés</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="Number($store.articles.statistics.published || 0).toLocaleString('fr-FR')"></p>
                </article>
                <article class="rounded-md border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Brouillons</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="Number($store.articles.statistics.drafts || 0).toLocaleString('fr-FR')"></p>
                </article>
                <article class="rounded-md border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Consultations</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="Number($store.articles.statistics.views || 0).toLocaleString('fr-FR')"></p>
                </article>
            </section>
            <section class="<?= $ui['panel'] ?> mt-4" aria-labelledby="articles-popular-heading">
                <header class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <h2 id="articles-popular-heading" class="text-sm font-semibold text-zinc-900 dark:text-white">Articles les plus consultés</h2>
                </header>
                <ol class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-5">
                    <template x-for="article in $store.articles.popular" :key="article.id">
                        <li class="rounded-md bg-zinc-50 p-3 dark:bg-zinc-900">
                            <?php if (!empty($article_can_update)): ?>
                                <button type="button" class="text-left text-sm font-medium text-zinc-900 hover:underline dark:text-white"
                                    @click="$store.articles.openArticle(article.id)" x-text="article.title"></button>
                            <?php else: ?>
                                <span class="text-sm font-medium text-zinc-900 dark:text-white" x-text="article.title"></span>
                            <?php endif; ?>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" x-text="`${Number(article.views || 0).toLocaleString('fr-FR')} consultation(s)`"></p>
                        </li>
                    </template>
                </ol>
            </section>
        <?php endif; ?>

        <section class="<?= $ui['panel'] ?> mt-4">
            <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800 sm:px-5">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Liste des articles</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="text-lg font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="$store.articles.total"></span>
                    <span class="ml-1">résultat(s)</span>
                </p>
            </header>
            <x-table id="articles-table" variant="plain" store="articles" store-rows-key="items"
                store-total-key="total" store-loading-key="loading" store-query-method="query" store-autoload
                :columns="$columns" empty-message="Aucun article" sortable sticky-header
                max-height="60vh" responsive="stack" stack-below="md"
                :page-size="(int) ($article_listing['per_page'] ?? 10)"
                :page-sizes="[(int) ($article_listing['per_page'] ?? 10)]"
                no-results-message="Aucun article ne correspond à ces filtres">
                <x-slot:tbody>
                    <template x-for="item in $store.articles.items" :key="item.id">
                        <tr class="border-t border-zinc-100 transition-colors first:border-t-0 hover:bg-zinc-50 dark:border-zinc-800/70 dark:hover:bg-zinc-900/60">
                            <td class="<?= $ui['cell'] ?>">
                                <div class="min-w-52">
                                    <?php if (!empty($article_can_update)): ?>
                                        <button type="button" class="text-left font-medium text-zinc-900 hover:underline dark:text-white"
                                            @click="$store.articles.openArticle(item.id)" x-text="item.title"></button>
                                    <?php else: ?>
                                        <span class="font-medium text-zinc-900 dark:text-white" x-text="item.title"></span>
                                    <?php endif; ?>
                                    <p class="mt-1 break-all font-mono text-xs <?= $ui['muted'] ?>" x-text="item.slug"></p>
                                    <p class="mt-1 line-clamp-2 text-sm <?= $ui['muted'] ?>" x-text="item.excerpt"></p>
                                </div>
                            </td>
                            <td class="<?= $ui['cell'] ?> text-sm <?= $ui['muted'] ?>" x-text="(item.categories || []).join(', ') || '—'"></td>
                            <td class="<?= $ui['cell'] ?>">
                                <span class="inline-flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <span class="h-2 w-2 shrink-0 rounded-full" :class="{
                                        draft: 'bg-zinc-400 dark:bg-zinc-500',
                                        published: 'bg-emerald-500',
                                        archived: 'bg-amber-500'
                                    }[item.status] || 'bg-zinc-400'"></span>
                                    <span x-text="{draft: 'Brouillon', published: 'Publié', archived: 'Archivé'}[item.status] || item.status"></span>
                                </span>
                            </td>
                            <td class="<?= $ui['cell'] ?> text-sm <?= $ui['muted'] ?>" x-text="item.author_name || '—'"></td>
                            <td class="<?= $ui['cell'] ?> whitespace-nowrap text-sm <?= $ui['muted'] ?>" x-text="item.updated_at || '—'"></td>
                            <?php if (!empty($article_can_view_stats)): ?>
                                <td class="<?= $ui['cell'] ?> whitespace-nowrap text-sm tabular-nums <?= $ui['muted'] ?>" x-text="Number(item.views_count || 0).toLocaleString('fr-FR')"></td>
                            <?php endif; ?>
                            <td class="<?= $ui['cell'] ?> whitespace-nowrap text-right">
                                <x-action-menu>
                                    <?php if (!empty($article_can_update)): ?>
                                        <button type="button" role="menuitem" class="<?= $ui['menu'] ?>"
                                            @click="$store.articles.openArticle(item.id)">Modifier</button>
                                    <?php endif; ?>
                                    <a role="menuitem" class="<?= $ui['menu'] ?>" :href="`/dashboard/articles/preview/${item.id}`"
                                        target="_blank" rel="noopener">Prévisualiser</a>
                                    <a x-show="item.status === 'published'" role="menuitem" class="<?= $ui['menu'] ?>"
                                        :href="`/articles/${encodeURIComponent(item.slug)}`"
                                        target="_blank" rel="noopener">Voir l’article public</a>
                                    <?php if (!empty($article_can_publish)): ?>
                                        <button x-show="item.status === 'draft' || item.status === 'archived'" type="button" role="menuitem" class="<?= $ui['menu'] ?>"
                                            @click="$store.articles.confirmStatus(item, 'published')"
                                            x-text="item.status === 'archived' ? 'Restaurer et publier' : 'Publier'">Publier</button>
                                        <button x-show="item.status === 'published' || item.status === 'archived'" type="button" role="menuitem" class="<?= $ui['menuWarn'] ?>"
                                            @click="$store.articles.confirmStatus(item, 'draft')"
                                            x-text="item.status === 'archived' ? 'Restaurer en brouillon' : 'Dépublier'">Dépublier</button>
                                    <?php endif; ?>
                                    <?php if (!empty($article_can_delete)): ?>
                                        <button x-show="item.status !== 'archived'" type="button" role="menuitem" class="<?= $ui['menuWarn'] ?>"
                                            @click="$store.articles.confirmArchive(item)">Archiver</button>
                                        <button type="button" role="menuitem" class="<?= $ui['menuDanger'] ?>"
                                            @click="$store.articles.confirmDelete(item)">Supprimer</button>
                                    <?php endif; ?>
                                </x-action-menu>
                            </td>
                        </tr>
                    </template>
                </x-slot:tbody>
            </x-table>
        </section>

        <?php if (!empty($article_can_manage_categories)): ?>
            <x-container title="Catégories" :reduiceable="true" :open="false" class="mt-4"
                :attributes="['aria-label' => 'Gestion des catégories']">
                <x-slot:actions>
                    <x-button label="Ajouter une catégorie" class="add" icon="new" click="$store.articles.openCategory()" />
                </x-slot:actions>
                <ul class="flex flex-wrap gap-2 p-4">
                    <template x-for="category in $store.articles.categories" :key="category.id">
                        <li class="flex items-center gap-2 rounded-md border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
                            <span class="text-zinc-800 dark:text-zinc-200" x-text="category.name"></span>
                            <button type="button" class="rounded px-2 text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950"
                                :aria-label="`Supprimer ${category.name}`"
                                @click="$store.articles.confirmDeleteCategory(category)">×</button>
                        </li>
                    </template>
                    <template x-if="$store.articles.categories.length === 0">
                        <li class="text-sm text-zinc-500 dark:text-zinc-400">Aucune catégorie.</li>
                    </template>
                </ul>
            </x-container>
        <?php endif; ?>
    </x-slot:components>
</x-container>
