<?php
$settings = $quote_settings ?? [];
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$checked = static fn(string $field): string => (($settings[$field] ?? false) === true) ? ' checked' : '';
?>
<dialog id="quotes-service-configuration" class="rounded-2xl border border-slate-200 p-0 shadow-xl">
    <form method="post" action="/dashboard/settings/services/quotes" class="w-[min(46rem,calc(100vw-2rem))]">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">
        <div class="border-b border-slate-200 px-6 py-4">
            <h2 class="text-lg font-semibold">Configuration des devis</h2>
        </div>
        <div class="grid gap-4 px-6 py-5 md:grid-cols-2">
            <?php foreach ([
                'public_requests_enabled' => 'Demandes publiques',
                'product_requests_enabled' => 'Demandes depuis les produits',
                'category_requests_enabled' => 'Demandes depuis les catégories',
                'brand_requests_enabled' => 'Demandes depuis les marques',
                'allow_anonymous' => 'Autoriser les visiteurs',
                'require_phone' => 'Exiger le téléphone',
                'csv_enabled' => 'Autoriser les exports CSV',
                'pdf_enabled' => 'Autoriser les exports PDF',
            ] as $field => $label): ?>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="<?= $escape($field) ?>" value="0">
                    <input type="checkbox" name="<?= $escape($field) ?>" value="1"<?= $checked($field) ?>>
                    <span><?= $escape($label) ?></span>
                </label>
            <?php endforeach; ?>
            <label class="grid gap-1">
                <span>Validité par défaut (jours)</span>
                <input class="rounded-lg border px-3 py-2" type="number" name="default_validity_days" min="1" max="365" value="<?= $escape($settings['default_validity_days'] ?? 30) ?>">
            </label>
            <label class="grid gap-1">
                <span>Devise par défaut</span>
                <input class="rounded-lg border px-3 py-2" type="text" name="default_currency" maxlength="3" value="<?= $escape($settings['default_currency'] ?? 'MAD') ?>">
            </label>
            <label class="grid gap-1">
                <span>Nombre maximal de lignes</span>
                <input class="rounded-lg border px-3 py-2" type="number" name="max_lines" min="1" max="500" value="<?= $escape($settings['max_lines'] ?? 100) ?>">
            </label>
            <label class="grid gap-1">
                <span>Quantité maximale par ligne</span>
                <input class="rounded-lg border px-3 py-2" type="number" name="max_quantity_per_line" min="1" max="1000000" value="<?= $escape($settings['max_quantity_per_line'] ?? 1000) ?>">
            </label>
            <label class="grid gap-1">
                <span>Résultats maximum de recherche</span>
                <input class="rounded-lg border px-3 py-2" type="number" name="max_search_results" min="1" max="100" value="<?= $escape($settings['max_search_results'] ?? 20) ?>">
            </label>
            <label class="grid gap-1 md:col-span-2">
                <span>Conditions commerciales par défaut</span>
                <textarea class="rounded-lg border px-3 py-2" name="commercial_terms" rows="4"><?= $escape($settings['commercial_terms'] ?? '') ?></textarea>
            </label>
        </div>
        <div class="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
            <button type="button" class="rounded-lg border px-4 py-2" onclick="this.closest('dialog').close()">Annuler</button>
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-white">Enregistrer</button>
        </div>
    </form>
</dialog>
