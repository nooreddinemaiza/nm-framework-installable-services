<?php
$data = $page_management_data ?? [];
$json = static fn(mixed $value): string => htmlspecialchars(
    json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$columns = [
    ['key' => 'title', 'label' => 'Page', 'sortable' => false],
    ['key' => 'status', 'label' => 'Statut', 'sortable' => false],
    ['key' => 'layout', 'label' => 'Layout', 'sortable' => false],
    ['key' => 'updated_at', 'label' => 'Modifiée', 'sortable' => false],
    ['key' => 'actions', 'label' => 'Actions', 'align' => 'right', 'sortable' => false],
];
$labelClass = 'flex flex-col gap-1 text-sm font-medium text-slate-700 dark:text-slate-200';
$fieldClass = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100';
$secondaryClass = 'inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700';
$dangerClass = 'inline-flex min-h-9 items-center justify-center rounded-lg border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-700 transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 dark:border-red-500/30 dark:bg-slate-900 dark:text-red-300 dark:hover:bg-red-500/10';
?>
@cdn('scripts', '/dashboard/service-assets/page-management/page-management.js')

<section x-cloak x-data="$store.pageManagement" x-init="$store.pageManagement.initialize($el)"
    data-page-management="<?= $json($data) ?>" class="mx-auto max-w-screen-2xl space-y-4 px-3 pb-8 sm:px-6">
    <x-container title="Gestion des pages" :reduiceable="false" size="xxl" class="my-1">
        <x-slot:actions>
            <x-input name="page-search" type="search" placeholder="Rechercher un titre ou un slug"
                parent_class="w-full sm:w-56"
                :attributes="['x-model' => '$store.pageManagement.filters.search', '@input.debounce.300ms' => '$store.pageManagement.loadItems()']" />
            <x-select name="page-status" parent_class="w-full sm:w-40"
                xModel="$store.pageManagement.filters.status"
                :attributes="['@change' => '$store.pageManagement.loadItems()']"
                :options="[
                    ['label' => 'Tous les statuts', 'value' => ''],
                    ['label' => 'Brouillons', 'value' => 'draft'],
                    ['label' => 'Publiées', 'value' => 'published'],
                    ['label' => 'Archivées', 'value' => 'archived'],
                ]" />
            <x-button label="Outils CMS" class="outline" type="button"
                click="$store.pageManagement.openManager('templates')"
                :attributes="['x-show' => '$store.pageManagement.can(\'pages.manage_templates\') || $store.pageManagement.can(\'pages.manage_sections\') || $store.pageManagement.can(\'pages.manage_navigation\')']" />
            <x-button label="Depuis un template" class="secondary" type="button"
                click="$store.pageManagement.openTemplatePicker()"
                :attributes="['x-show' => '$store.pageManagement.can(\'pages.create\')']" />
            <x-button label="Nouvelle page" class="add" type="button"
                click="$store.pageManagement.newPage()"
                :attributes="['x-show' => '$store.pageManagement.can(\'pages.create\')']" />
        </x-slot:actions>

        <x-slot:components>
            <div class="space-y-4">
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    Créez et publiez des pages, gérez leur structure et prévisualisez les modifications avant leur mise en ligne.
                </p>

                <section class="grid gap-3 sm:grid-cols-3" aria-label="Résumé des pages">
                    <article class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Résultats affichés</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900 dark:text-white"
                            x-text="items.length"></p>
                    </article>
                    <article class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Pages publiées</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900 dark:text-white"
                            x-text="items.filter(page => page.status === 'published').length"></p>
                    </article>
                    <article class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Sections réutilisables</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900 dark:text-white"
                            x-text="library.length"></p>
                    </article>
                </section>

                <x-table id="page-management-table" variant="plain" store="pageManagement"
                    store-rows-key="items" :columns="$columns" empty-message="Aucune page"
                    responsive="stack" stack-below="md" sticky-header max-height="65vh">
                    <x-slot:cell-title>
                        <div class="min-w-0">
                            <button type="button"
                                class="text-left font-medium text-slate-900 hover:underline dark:text-white"
                                @click="$store.pageManagement.editPage(row.id)" x-text="row.title"></button>
                            <p class="mt-1 break-all font-mono text-xs text-slate-500 dark:text-slate-400"
                                x-text="'/' + row.slug"></p>
                            <span x-show="Boolean(Number(row.is_homepage))"
                                class="mt-1 inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">Accueil</span>
                        </div>
                    </x-slot:cell-title>
                    <x-slot:cell-status>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="$store.pageManagement.statusClass(row.status)"
                            x-text="$store.pageManagement.statusLabel(row.status)"></span>
                    </x-slot:cell-status>
                    <x-slot:cell-layout>
                        <span class="text-slate-600 dark:text-slate-300"
                            x-text="$store.pageManagement.layoutName(row.layout)"></span>
                    </x-slot:cell-layout>
                    <x-slot:cell-updated_at>
                        <span class="whitespace-nowrap text-slate-600 dark:text-slate-300"
                            x-text="$store.pageManagement.formatDate(row.updated_at)"></span>
                    </x-slot:cell-updated_at>
                    <x-slot:cell-actions>
                        <div class="flex flex-wrap justify-end gap-1.5">
                            <a x-show="row.status === 'published'"
                                :href="Boolean(Number(row.is_homepage)) ? '/' : '/p/' + encodeURIComponent(row.slug)"
                                target="_blank" rel="noopener noreferrer"
                                class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs">Voir</a>
                            <button x-show="$store.pageManagement.can('pages.update')" type="button"
                                class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs"
                                @click="$store.pageManagement.editPage(row.id)">Modifier</button>
                            <button x-show="$store.pageManagement.can('pages.manage_templates')" type="button"
                                class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs"
                                @click="$store.pageManagement.createTemplateFromPage(row.id)">Créer un template</button>
                            <button x-show="$store.pageManagement.can('pages.manage_settings') && row.status === 'published' && !Boolean(Number(row.is_homepage))"
                                type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs"
                                @click="$store.pageManagement.designateHomepage(row.id)">Définir l’accueil</button>
                            <button x-show="$store.pageManagement.can('pages.create')" type="button"
                                class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs"
                                @click="$store.pageManagement.duplicate(row.id)">Dupliquer</button>
                            <button x-show="$store.pageManagement.can('pages.archive') && row.status !== 'archived'"
                                type="button" class="<?= $dangerClass ?> !min-h-8 !px-2.5 !text-xs"
                                @click="$store.pageManagement.archive(row.id)">Archiver</button>
                            <button x-show="$store.pageManagement.can('pages.delete')" type="button"
                                class="<?= $dangerClass ?> !min-h-8 !px-2.5 !text-xs"
                                @click="$store.pageManagement.deletePage(row.id)">Supprimer</button>
                        </div>
                    </x-slot:cell-actions>
                </x-table>
            </div>
        </x-slot:components>
    </x-container>

    <x-modal id="page-editor" title="Éditeur de page" open="editorOpen"
        store="$store.pageManagement" size="xxl" :closable="true"
        close-action="$store.pageManagement.closeEditor()">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white"
                    x-text="form.id ? 'Modifier la page' : 'Créer une page'"></h2>
                <span class="text-sm" :class="dirty ? 'text-amber-700 dark:text-amber-300' : 'text-slate-500 dark:text-slate-400'"
                    x-text="dirty ? 'Modifications non enregistrées' : 'Enregistré'"></span>
            </div>

            <div class="grid min-w-0 gap-5 xl:grid-cols-2">
                <div class="min-w-0 space-y-5 xl:max-h-[68vh] xl:overflow-y-auto xl:pr-2">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="<?= $labelClass ?> sm:col-span-2">Titre
                            <input x-model="form.title" @input="markDirty()" maxlength="190" required class="<?= $fieldClass ?>">
                        </label>
                        <label class="<?= $labelClass ?>">Slug
                            <input x-model="form.slug" @input="markDirty()" placeholder="Généré depuis le titre" class="<?= $fieldClass ?>">
                        </label>
                        <label class="<?= $labelClass ?>">Statut
                            <select x-model="form.status" @change="markDirty()" class="<?= $fieldClass ?>">
                                <option value="draft">Brouillon</option><option value="published">Publiée</option><option value="archived">Archivée</option>
                            </select>
                        </label>
                        <label class="<?= $labelClass ?>">Layout
                            <select x-model="form.layout" @change="markDirty()" class="<?= $fieldClass ?>">
                                <template x-for="(layout, id) in layouts" :key="id"><option :value="id" x-text="layout.name"></option></template>
                            </select>
                        </label>
                        <label class="<?= $labelClass ?>">Visibilité
                            <select x-model="form.visibility" @change="markDirty()" class="<?= $fieldClass ?>">
                                <option value="public">Publique</option><option value="protected">Protégée</option>
                            </select>
                        </label>
                        <label class="<?= $labelClass ?> sm:col-span-2">Description
                            <textarea x-model="form.description" @input="markDirty()" rows="2" class="<?= $fieldClass ?>"></textarea>
                        </label>
                    </div>

                    <section class="space-y-3" aria-labelledby="page-sections-heading">
                        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-2 dark:border-slate-700">
                            <div>
                                <h3 id="page-sections-heading" class="text-base font-semibold text-slate-900 dark:text-white">Structure de la page</h3>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Sections indépendantes ou partagées.</p>
                            </div>
                            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                                <select x-model="selectedLibrary" class="<?= $fieldClass ?> min-w-40 flex-1 sm:w-auto">
                                    <option value="">Bibliothèque de sections</option>
                                    <template x-for="section in library" :key="section.id"><option :value="section.id" x-text="`${section.name} · ${section.usage_count} référence(s)`"></option></template>
                                </select>
                                <select x-model="libraryMode" class="<?= $fieldClass ?> w-full sm:w-auto" aria-label="Mode de réutilisation">
                                    <option value="reference">Référence partagée</option><option value="copy">Copie indépendante</option>
                                </select>
                                <x-button label="Ajouter" class="outline" type="button"
                                    click="$store.pageManagement.addLibrary()" />
                                <x-button label="Section" class="add" type="button"
                                    click="$store.pageManagement.addSection()" />
                            </div>
                        </div>

                        <div x-show="sectionExamples.length > 0" class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                            <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">Besoin d’un point de départ ? Choisissez un exemple : le style Étagère sera activé automatiquement.</p>
                            <div class="grid min-w-0 gap-2 sm:grid-cols-3">
                                <template x-for="example in sectionExamples" :key="example.key">
                                    <button type="button" @click="addExampleSection(example)"
                                        class="min-w-0 rounded-md border border-slate-200 bg-white p-3 text-left transition hover:border-indigo-400 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">
                                        <span class="block font-medium text-slate-900 dark:text-white" x-text="example.name"></span>
                                        <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400" x-text="example.description"></span>
                                        <span class="mt-2 block text-xs font-semibold text-indigo-600 dark:text-indigo-400">Ajouter à la page</span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <template x-for="(section, sectionIndex) in form.sections" :key="section._key">
                                <article class="min-w-0 rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-3 py-2 dark:border-slate-700">
                                        <span class="text-xs font-semibold tabular-nums text-slate-500" x-text="String(sectionIndex + 1).padStart(2, '0')"></span>
                                        <input x-model="section.name" @input="markDirty()" :disabled="Boolean(section.library_section_id)"
                                            aria-label="Nom de section" class="<?= $fieldClass ?> min-w-32 flex-1 !py-1.5 disabled:opacity-60">
                                        <select x-model="section.type" @change="markDirty()" :disabled="Boolean(section.library_section_id)"
                                            class="<?= $fieldClass ?> w-auto !py-1.5 disabled:opacity-60">
                                            <template x-for="(definition, type) in sectionTypes" :key="type"><option :value="type" x-text="definition.name"></option></template>
                                        </select>
                                        <select x-model="section.slot" @change="markDirty()" class="<?= $fieldClass ?> w-auto !py-1.5">
                                            <option value="content">Contenu</option><option value="header">Header</option><option value="footer">Footer</option><option value="announcement">Bandeau</option><option value="sidebar">Sidebar</option>
                                        </select>
                                        <label class="inline-flex items-center gap-1 text-xs text-slate-600 dark:text-slate-300"><input type="checkbox" x-model="section.enabled" @change="markDirty()"> Active</label>
                                        <span x-show="section.library_section_id" class="rounded-full bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">Partagée</span>
                                        <button x-show="can('pages.manage_sections') && !section.library_section_id" type="button"
                                            class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                            @click="saveSectionToLibrary(sectionIndex)">Bibliothèque</button>
                                        <button type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2" @click="moveSection(sectionIndex, -1)" aria-label="Monter">↑</button>
                                        <button type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2" @click="moveSection(sectionIndex, 1)" aria-label="Descendre">↓</button>
                                        <button type="button" class="<?= $dangerClass ?> !min-h-8 !px-2" @click="removeSection(sectionIndex)" aria-label="Supprimer">×</button>
                                    </div>
                                    <div x-show="!section.library_section_id" class="space-y-3 p-3">
                                        <template x-for="(block, blockIndex) in section.blocks" :key="block._key">
                                            <div class="min-w-0 rounded-md border border-slate-200 p-3 dark:border-slate-700">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <select x-model="block.type" @change="block.configurationText = defaultBlockConfig(block.type); markDirty()" class="<?= $fieldClass ?> w-auto">
                                                        <template x-for="(definition, type) in blocks" :key="type"><option :value="type" x-text="definition.name"></option></template>
                                                    </select>
                                                    <label class="inline-flex items-center gap-1 text-xs text-slate-600 dark:text-slate-300"><input type="checkbox" x-model="block.enabled" @change="markDirty()"> Active</label>
                                                    <div class="ml-auto flex flex-wrap gap-1">
                                                        <button type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2" @click="moveBlock(sectionIndex, blockIndex, -1)" aria-label="Monter le bloc">↑</button>
                                                        <button type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2" @click="moveBlock(sectionIndex, blockIndex, 1)" aria-label="Descendre le bloc">↓</button>
                                                        <button type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2" @click="duplicateBlock(sectionIndex, blockIndex)">Dupliquer</button>
                                                        <button type="button" class="<?= $dangerClass ?> !min-h-8 !px-2" @click="removeBlock(sectionIndex, blockIndex)">Retirer</button>
                                                    </div>
                                                </div>
                                                <label class="<?= $labelClass ?> mt-2">Configuration du bloc (JSON)
                                                    <textarea x-model="block.configurationText" @input="markDirty()" rows="4" spellcheck="false"
                                                        class="<?= $fieldClass ?> font-mono text-xs"></textarea>
                                                </label>
                                            </div>
                                        </template>
                                        <x-button label="Ajouter un bloc" class="outline" type="button"
                                            click="$store.pageManagement.addBlock(sectionIndex)" />
                                    </div>
                                    <p x-show="section.library_section_id" class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">Section partagée : modifiez son contenu dans la bibliothèque.</p>
                                </article>
                            </template>
                            <p x-show="form.sections.length === 0" class="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Aucune section. Ajoutez-en une ou choisissez un template.</p>
                        </div>
                    </section>

                    <details class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <summary class="cursor-pointer text-sm font-semibold text-slate-900 dark:text-white">Affichage et métadonnées SEO</summary>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <label class="<?= $labelClass ?>">Titre SEO<input x-model="form.seo_title" @input="markDirty()" class="<?= $fieldClass ?>"></label>
                            <label class="<?= $labelClass ?>">Image principale<input x-model="form.featured_image" @input="markDirty()" class="<?= $fieldClass ?>"></label>
                            <label class="<?= $labelClass ?> sm:col-span-2">Description SEO<textarea x-model="form.seo_description" @input="markDirty()" rows="2" class="<?= $fieldClass ?>"></textarea></label>
                            <label class="<?= $labelClass ?> sm:col-span-2">URL canonique<input x-model="form.canonical_url" @input="markDirty()" class="<?= $fieldClass ?>"></label>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" x-model="form.no_index" @change="markDirty()"> Interdire l’indexation</label>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" x-model="form.add_to_navigation" @change="markDirty()"> Proposer dans la navigation</label>
                            <label x-show="form.add_to_navigation" class="<?= $labelClass ?>">Libellé de navigation<input x-model="form.navigation_label" @input="markDirty()" class="<?= $fieldClass ?>"></label>
                            <label x-show="form.add_to_navigation" class="<?= $labelClass ?>">Position<input type="number" min="0" x-model.number="form.navigation_order" @input="markDirty()" class="<?= $fieldClass ?>"></label>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" x-model="form.show_header" @change="markDirty()"> Afficher l’en-tête</label>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" x-model="form.show_footer" @change="markDirty()"> Afficher le pied de page</label>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 sm:col-span-2"><input type="checkbox" x-model="form.show_announcement_bar" @change="markDirty()"> Afficher le bandeau d’annonce</label>
                        </div>
                    </details>
                </div>

                <aside class="min-w-0 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Prévisualisation</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Aperçu isolé dans un cadre sandboxé.</p>
                        </div>
                        <div class="flex gap-1">
                            <template x-for="device in ['desktop', 'tablet', 'mobile']" :key="device">
                                <button type="button" @click="viewport = device"
                                    :class="viewport === device ? 'bg-indigo-600 text-white dark:bg-indigo-500' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'"
                                    class="rounded-md px-2.5 py-1.5 text-xs font-medium" x-text="device"></button>
                            </template>
                        </div>
                    </div>
                    <div class="flex justify-center rounded-lg border border-slate-200 bg-slate-100 p-2 dark:border-slate-700 dark:bg-slate-950">
                        <div class="w-full overflow-hidden rounded-md border border-slate-300 bg-white dark:border-slate-700 transition-[width] duration-200"
                            :class="{'w-full': viewport === 'desktop', 'max-w-[768px]': viewport === 'tablet', 'max-w-[390px]': viewport === 'mobile'}">
                            <iframe id="page-management-preview" title="Aperçu de la page" sandbox
                                class="h-[55vh] min-h-64 max-h-[650px] w-full border-0"></iframe>
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2">
                        <x-button label="Actualiser l’aperçu" class="outline" type="button"
                            click="$store.pageManagement.refreshPreview()" />
                        <x-button label="Enregistrer" class="save" type="button"
                            :attributes="['@click' => '$store.pageManagement.save()', ':disabled' => '$store.pageManagement.saving']" />
                    </div>
                </aside>
            </div>
        </div>
    </x-modal>

    <x-modal id="page-template-picker" title="Créer une page depuis un template"
        open="templatePickerOpen" store="$store.pageManagement" size="md" :closable="true">
        <div class="space-y-4">
            <label class="<?= $labelClass ?>">Titre de la nouvelle page
                <input x-model="templateTitle" class="<?= $fieldClass ?>">
            </label>
            <div class="max-h-[55vh] space-y-2 overflow-y-auto overscroll-contain">
                <template x-for="template in templates" :key="template.origin === 'developer' ? template.key : template.id">
                    <button type="button"
                        @click="createFromTemplate(template.origin === 'developer' ? 'developer:' + template.key : String(template.id))"
                        class="flex w-full flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3 text-left hover:border-indigo-400 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">
                        <span class="min-w-0"><span class="block font-medium text-slate-900 dark:text-white" x-text="template.name"></span><span class="mt-1 block text-sm text-slate-500 dark:text-slate-400" x-text="template.description || template.category || 'Template réutilisable'"></span></span>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="template.origin === 'developer' ? 'Exemple intégré' : 'Personnalisé'"></span>
                    </button>
                </template>
                <p x-show="templates.length === 0" class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800 dark:text-slate-400">Aucun template disponible.</p>
            </div>
        </div>
    </x-modal>

    <x-modal id="page-management-tools" title="Outils CMS" open="managerOpen"
        store="$store.pageManagement" size="xxl" :closable="true">
        <div class="space-y-4">
            <nav class="flex flex-wrap gap-2 border-b border-slate-200 pb-3 dark:border-slate-700" aria-label="Outils CMS">
                <button x-show="can('pages.manage_templates')" type="button" @click="managerTab = 'templates'"
                    :class="managerTab === 'templates' ? 'border-indigo-600 text-indigo-700 dark:text-indigo-300' : 'border-transparent text-slate-500 dark:text-slate-400'"
                    class="border-b-2 px-3 py-2 text-sm font-medium">Templates</button>
                <button x-show="can('pages.manage_sections')" type="button" @click="managerTab = 'sections'"
                    :class="managerTab === 'sections' ? 'border-indigo-600 text-indigo-700 dark:text-indigo-300' : 'border-transparent text-slate-500 dark:text-slate-400'"
                    class="border-b-2 px-3 py-2 text-sm font-medium">Bibliothèque de sections</button>
                <button x-show="can('pages.manage_navigation')" type="button" @click="managerTab = 'menus'"
                    :class="managerTab === 'menus' ? 'border-indigo-600 text-indigo-700 dark:text-indigo-300' : 'border-transparent text-slate-500 dark:text-slate-400'"
                    class="border-b-2 px-3 py-2 text-sm font-medium">Menus</button>
            </nav>

            <section x-show="managerTab === 'templates' && can('pages.manage_templates')"
                class="grid min-w-0 gap-5 xl:grid-cols-2">
                <div class="min-w-0 space-y-3">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Templates disponibles</h3>
                    <div class="max-h-[55vh] space-y-2 overflow-y-auto overscroll-contain pr-1">
                        <template x-for="template in templates" :key="template.origin === 'developer' ? template.key : template.id">
                            <article class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0"><h4 class="font-medium text-slate-900 dark:text-white" x-text="template.name"></h4><p class="mt-1 text-sm text-slate-500 dark:text-slate-400" x-text="template.description || template.category || 'Sans description'"></p></div>
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="template.origin === 'developer' ? 'Exemple intégré' : 'Personnalisé'"></span>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button x-show="can('pages.preview')" type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs" @click="previewTemplate(template)">Prévisualiser</button>
                                    <button x-show="template.origin === 'custom'" type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs" @click="editTemplate(template)">Modifier</button>
                                    <button x-show="template.origin === 'custom'" type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs" @click="duplicateTemplate(template)">Dupliquer</button>
                                    <button x-show="template.origin === 'custom'" type="button" class="<?= $dangerClass ?> !min-h-8 !px-2.5 !text-xs" @click="deleteTemplate(template)">Supprimer</button>
                                </div>
                            </article>
                        </template>
                        <p x-show="templates.length === 0" class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Aucun template disponible.</p>
                    </div>
                </div>
                <form x-show="templateForm.id" @submit.prevent="saveTemplate()" class="min-w-0 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Modifier le template</h3>
                    <label class="<?= $labelClass ?>">Nom<input x-model="templateForm.name" required maxlength="190" class="<?= $fieldClass ?>"></label>
                    <label class="<?= $labelClass ?>">Description<input x-model="templateForm.description" class="<?= $fieldClass ?>"></label>
                    <label class="<?= $labelClass ?>">Catégorie<input x-model="templateForm.category" class="<?= $fieldClass ?>"></label>
                    <label class="<?= $labelClass ?>">Structure (JSON)<textarea x-model="templateForm.structureText" rows="7" spellcheck="false" class="<?= $fieldClass ?> font-mono text-xs"></textarea></label>
                    <label class="<?= $labelClass ?>">Paramètres (JSON)<textarea x-model="templateForm.settingsText" rows="3" spellcheck="false" class="<?= $fieldClass ?> font-mono text-xs"></textarea></label>
                    <div class="flex flex-wrap gap-2">
                        <x-button label="Enregistrer" class="save" type="submit" />
                        <x-button label="Annuler" class="cancel" type="button"
                            click="$store.pageManagement.templateForm = $store.pageManagement.emptyTemplateForm()" />
                    </div>
                </form>
            </section>

            <section x-show="managerTab === 'sections' && can('pages.manage_sections')"
                class="grid min-w-0 gap-5 xl:grid-cols-2">
                <div class="min-w-0 space-y-3">
                    <div><h3 class="text-base font-semibold text-slate-900 dark:text-white">Bibliothèque de sections</h3><p class="text-sm text-slate-500 dark:text-slate-400">Les sections référencées ne peuvent être supprimées avant le retrait de leurs utilisations.</p></div>
                    <div class="max-h-[55vh] space-y-2 overflow-y-auto overscroll-contain pr-1">
                        <template x-for="section in library" :key="section.id">
                            <article class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0"><h4 class="font-medium text-slate-900 dark:text-white" x-text="section.name"></h4><p class="mt-1 text-sm text-slate-500 dark:text-slate-400" x-text="`${section.type} · ${section.usage_count} référence(s)`"></p></div>
                                    <div class="flex gap-2"><button type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2.5 !text-xs" @click="editLibrarySection(section)">Modifier</button><button type="button" class="<?= $dangerClass ?> !min-h-8 !px-2.5 !text-xs" @click="deleteLibrarySection(section)">Supprimer</button></div>
                                </div>
                                <p x-show="section.usage_pages?.length" class="mt-2 text-xs text-slate-500 dark:text-slate-400"><span class="font-medium">Utilisée par :</span> <template x-for="page in section.usage_pages" :key="page.id"><span class="mr-1" x-text="page.title"></span></template></p>
                            </article>
                        </template>
                        <p x-show="library.length === 0" class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">La bibliothèque est vide.</p>
                    </div>
                </div>
                <form @submit.prevent="saveLibrarySection()" class="min-w-0 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                    <h3 class="font-semibold text-slate-900 dark:text-white" x-text="sectionForm.id ? 'Modifier une section' : 'Créer une section'"></h3>
                    <label class="<?= $labelClass ?>">Nom<input x-model="sectionForm.name" required maxlength="190" class="<?= $fieldClass ?>"></label>
                    <label class="<?= $labelClass ?>">Type<select x-model="sectionForm.type" class="<?= $fieldClass ?>"><template x-for="(definition, type) in sectionTypes" :key="type"><option :value="type" x-text="definition.name"></option></template></select></label>
                    <label class="<?= $labelClass ?>">Paramètres (JSON)<textarea x-model="sectionForm.settingsText" rows="3" spellcheck="false" class="<?= $fieldClass ?> font-mono text-xs"></textarea></label>
                    <label class="<?= $labelClass ?>">Blocs (JSON)<textarea x-model="sectionForm.blocksText" rows="6" spellcheck="false" class="<?= $fieldClass ?> font-mono text-xs"></textarea></label>
                    <div class="flex flex-wrap gap-2">
                        <x-button label="Enregistrer" class="save" type="submit" />
                        <x-button label="Annuler" class="cancel" type="button"
                            click="$store.pageManagement.sectionForm = $store.pageManagement.emptySectionForm()" />
                    </div>
                </form>
            </section>

            <section x-show="managerTab === 'menus' && can('pages.manage_navigation')"
                class="grid min-w-0 gap-5 xl:grid-cols-[minmax(12rem,.7fr)_minmax(0,1.3fr)]">
                <div class="min-w-0 space-y-3">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Menus</h3>
                    <div class="max-h-48 space-y-1 overflow-y-auto xl:max-h-[55vh]">
                        <template x-for="menu in menus" :key="menu.id">
                            <button type="button" @click="selectMenu(menu.id)"
                                :class="Number(selectedMenuId) === Number(menu.id) ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900'"
                                class="block w-full rounded-lg border p-3 text-left">
                                <span class="block text-sm font-medium text-slate-900 dark:text-white" x-text="menu.name"></span>
                                <span class="mt-1 block break-all font-mono text-xs text-slate-500 dark:text-slate-400" x-text="menu.slug"></span>
                            </button>
                        </template>
                        <p x-show="menus.length === 0" class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Aucun menu.</p>
                    </div>
                    <x-button label="Nouveau menu" class="add" type="button"
                        click="$store.pageManagement.selectMenu(null)" />
                </div>

                <div class="min-w-0 space-y-4">
                    <form @submit.prevent="saveMenu()" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 sm:grid-cols-2">
                        <h3 class="font-semibold text-slate-900 dark:text-white sm:col-span-2" x-text="menuForm.id ? 'Modifier le menu' : 'Créer un menu'"></h3>
                        <label class="<?= $labelClass ?>">Nom<input x-model="menuForm.name" required maxlength="190" class="<?= $fieldClass ?>"></label>
                        <label class="<?= $labelClass ?>">Slug<input x-model="menuForm.slug" placeholder="Généré depuis le nom" class="<?= $fieldClass ?>"></label>
                        <label class="<?= $labelClass ?>">Emplacement<input x-model="menuForm.location" placeholder="primary, footer, mobile…" class="<?= $fieldClass ?>"></label>
                        <div class="flex flex-wrap items-end gap-2">
                            <x-button label="Enregistrer le menu" class="save" type="submit" />
                            <button x-show="menuForm.id" type="button" class="<?= $dangerClass ?>" @click="deleteMenu()">Supprimer le menu</button>
                        </div>
                    </form>

                    <div x-show="selectedMenuId" class="grid min-w-0 gap-4 lg:grid-cols-2">
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold text-slate-900 dark:text-white">Éléments du menu</h3><x-button label="Ajouter" class="outline" type="button" click="$store.pageManagement.menuItemForm = $store.pageManagement.emptyMenuItemForm()" /></div>
                            <div class="max-h-[45vh] space-y-2 overflow-y-auto overscroll-contain">
                                <template x-for="item in (menus.find(menu => Number(menu.id) === Number(selectedMenuId))?.items || [])" :key="item.id">
                                    <article class="flex min-w-0 items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                                        <div class="min-w-0"><span class="block truncate text-sm font-medium text-slate-900 dark:text-white" x-text="item.label"></span><span class="text-xs text-slate-500 dark:text-slate-400" x-text="`${item.type} · ordre ${item.sort_order}${!asBoolean(item.active) ? ' · inactif' : ''}`"></span></div>
                                        <div class="flex shrink-0 gap-1"><button type="button" class="<?= $secondaryClass ?> !min-h-8 !px-2 !text-xs" @click="editMenuItem(item)">Modifier</button><button type="button" class="<?= $dangerClass ?> !min-h-8 !px-2 !text-xs" @click="deleteMenuItem(item)">Retirer</button></div>
                                    </article>
                                </template>
                            </div>
                        </div>
                        <form @submit.prevent="saveMenuItem()" class="min-w-0 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                            <h3 class="font-semibold text-slate-900 dark:text-white" x-text="menuItemForm.id ? 'Modifier un élément' : 'Ajouter un élément'"></h3>
                            <label class="<?= $labelClass ?>">Libellé<input x-model="menuItemForm.label" required maxlength="190" class="<?= $fieldClass ?>"></label>
                            <label class="<?= $labelClass ?>">Type<select x-model="menuItemForm.type" class="<?= $fieldClass ?>"><option value="page">Page interne</option><option value="external">URL externe</option><option value="custom">Lien personnalisé</option></select></label>
                            <label x-show="menuItemForm.type === 'page'" class="<?= $labelClass ?>">Page<select x-model="menuItemForm.page_id" class="<?= $fieldClass ?>"><option value="">Choisir une page</option><template x-for="page in items" :key="page.id"><option :value="page.id" x-text="`${page.title} · ${page.status}`"></option></template></select></label>
                            <label x-show="menuItemForm.type !== 'page'" class="<?= $labelClass ?>">Destination<input x-model="menuItemForm.destination" required class="<?= $fieldClass ?>"></label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="<?= $labelClass ?>">Élément parent<select x-model="menuItemForm.parent_id" class="<?= $fieldClass ?>"><option value="">Aucun</option><template x-for="item in (menus.find(menu => Number(menu.id) === Number(selectedMenuId))?.items || [])" :key="item.id"><option x-show="Number(item.id) !== Number(menuItemForm.id)" :value="item.id" x-text="item.label"></option></template></select></label>
                                <label class="<?= $labelClass ?>">Ordre<input type="number" min="0" x-model.number="menuItemForm.sort_order" class="<?= $fieldClass ?>"></label>
                                <label class="<?= $labelClass ?>">Icône<input x-model="menuItemForm.icon" class="<?= $fieldClass ?>"></label>
                                <label class="<?= $labelClass ?>">Cible<select x-model="menuItemForm.target" class="<?= $fieldClass ?>"><option value="_self">Même onglet</option><option value="_blank">Nouvel onglet</option></select></label>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" x-model="menuItemForm.active"> Élément actif</label>
                            <div class="flex flex-wrap gap-2">
                                <x-button label="Enregistrer l’élément" class="save" type="submit" />
                                <button x-show="menuItemForm.id" type="button" class="<?= $secondaryClass ?>" @click="menuItemForm = emptyMenuItemForm()">Annuler</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </x-modal>

    <x-modal id="page-template-preview" title="Prévisualisation du template"
        open="templatePreviewOpen" store="$store.pageManagement" size="xl" :closable="true">
        <iframe id="page-management-template-preview" title="Prévisualisation du template" sandbox
            class="h-[65vh] min-h-64 max-h-[700px] w-full rounded-md border border-slate-200 bg-white dark:border-slate-700"></iframe>
    </x-modal>
</section>
