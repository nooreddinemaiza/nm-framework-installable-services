@cdn('scripts', '/dashboard/service-assets/articles/articles-configuration.js')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$settings = app()->get(\NM\Services\Articles\Services\ArticleConfigurationStore::class)->values();
$field = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
?>
<dialog id="articles-service-configuration" data-articles-settings-dialog data-service-configuration="articles"
    aria-labelledby="articles-service-configuration-title"
    class="m-auto max-h-[92vh] w-[calc(100%-1.5rem)] max-w-2xl overflow-hidden rounded-2xl bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/60 dark:bg-slate-900 dark:text-slate-100">
    <form method="post" action="/dashboard/articles/configuration" data-articles-settings-form
        class="flex max-h-[92vh] flex-col">
        <header class="flex items-start gap-4 border-b border-slate-200 px-6 py-5 dark:border-slate-800">
            <div class="min-w-0 flex-1">
                <h2 id="articles-service-configuration-title" class="text-lg font-semibold">Configuration des articles</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Réglages de publication et de comptabilisation des consultations.</p>
            </div>
            <button type="button" data-articles-settings-close aria-label="Fermer"
                class="min-h-11 rounded-lg px-3 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">Fermer</button>
        </header>
        <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">
        <div class="grid gap-5 overflow-y-auto px-6 py-5">
            <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                Articles par page
                <input class="<?= $field ?>" type="number" name="items_per_page" min="1" max="100" required
                    value="<?= (int) $settings['items_per_page'] ?>">
                <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">S’applique aux listes publiques et au tableau de bord.</span>
            </label>
            <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                Fenêtre de déduplication des vues (secondes)
                <input class="<?= $field ?>" type="number" name="view_deduplication_ttl" min="60" max="2592000" required
                    value="<?= (int) $settings['view_deduplication_ttl'] ?>">
                <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Une même session ne compte qu’une fois le même article durant cette période.</span>
            </label>
            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-medium text-slate-700 dark:text-slate-300">
                <input type="hidden" name="public_popularity_enabled" value="0">
                <input type="checkbox" name="public_popularity_enabled" value="1"
                    <?= $settings['public_popularity_enabled'] ? 'checked' : '' ?>
                    class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                Afficher les articles populaires sur la page publique
            </label>
        </div>
        <footer class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 dark:border-slate-800 dark:bg-slate-950/40">
            <button type="button" data-articles-settings-close
                class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-200">Annuler</button>
            <button type="submit"
                class="min-h-11 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60 dark:bg-indigo-500 dark:text-slate-950">Enregistrer</button>
        </footer>
    </form>
</dialog>
