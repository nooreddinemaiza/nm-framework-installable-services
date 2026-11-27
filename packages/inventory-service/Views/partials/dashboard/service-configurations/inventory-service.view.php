@cdn('scripts', '/dashboard/service-assets/inventory-service/inventory-configuration.js')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$inventorySettings = app()->get(\NM\Services\InventoryService\Inventory\InventoryConfigurationStore::class)
    ->viewValues(app()->get(\NM\Services\InventoryService\Inventory\InventoryConfig::class));
$returnTo = ($inventory_configuration_return ?? 'inventory') === 'services' ? 'services' : 'inventory';
$field = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
$selected = static fn(bool $condition): string => $condition ? ' selected' : '';
?>
<dialog id="inventory-service-configuration" data-inventory-settings-dialog
    data-service-configuration="inventory-service"
    aria-labelledby="inventory-service-configuration-title"
    class="m-auto max-h-[92vh] w-[calc(100%-1.5rem)] max-w-3xl overflow-hidden rounded-3xl bg-white p-0 text-slate-900 shadow-2xl ring-1 ring-slate-900/10 backdrop:bg-slate-950/60 dark:bg-slate-900 dark:text-slate-100 dark:ring-white/10">
    <form method="post" data-inventory-settings-form action="/dashboard/inventory/configuration"
        class="flex max-h-[92vh] flex-col">
        <header class="flex items-start gap-4 border-b border-slate-100 px-6 py-5 dark:border-slate-800">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300"
                aria-hidden="true">⚙</span>
            <div class="min-w-0 flex-1">
                <h2 id="inventory-service-configuration-title" class="text-lg font-semibold">Configuration du service Produits et stocks</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Ces paramètres sont partagés entre les vues Inventaire et Services installables.
                </p>
            </div>
            <button type="button" data-inventory-settings-close aria-label="Fermer"
                class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">✕</button>
        </header>

        <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">
        <input type="hidden" name="return_to" value="<?= $returnTo ?>">
        <div class="overflow-y-auto px-6 py-5">
            <section aria-labelledby="inventory-operation-settings-title">
                <h3 id="inventory-operation-settings-title" class="text-sm font-semibold text-slate-900 dark:text-white">
                    Fonctionnement du service
                </h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Gestion multi-entrepôt
                        <select name="multi_warehouse" class="<?= $field ?>">
                            <option value="0"<?= $selected(!$inventorySettings['multi_warehouse']) ?>>Désactivée</option>
                            <option value="1"<?= $selected($inventorySettings['multi_warehouse']) ?>>Activée</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Précision des quantités
                        <select name="quantity_scale" class="<?= $field ?>">
                            <?php for ($scale = 0; $scale <= 6; ++$scale): ?>
                                <option value="<?= $scale ?>"<?= $selected($inventorySettings['quantity_scale'] === $scale) ?>>
                                    <?= $scale ?> décimale<?= $scale > 1 ? 's' : '' ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Saisie antidatée
                        <select name="allow_backdating" class="<?= $field ?>">
                            <option value="0"<?= $selected(!$inventorySettings['allow_backdating']) ?>>Interdite</option>
                            <option value="1"<?= $selected($inventorySettings['allow_backdating']) ?>>Autorisée</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Mouvements simultanés
                        <select name="concurrent_movement_mode" class="<?= $field ?>">
                            <option value="snapshot_adjust"<?= $selected($inventorySettings['concurrent_movement_mode'] === 'snapshot_adjust') ?>>
                                Ajuster depuis le snapshot
                            </option>
                            <option value="freeze"<?= $selected($inventorySettings['concurrent_movement_mode'] === 'freeze') ?>>
                                Geler pendant l’opération
                            </option>
                            <option value="ignore"<?= $selected($inventorySettings['concurrent_movement_mode'] === 'ignore') ?>>
                                Ignorer les conflits
                            </option>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300 sm:col-span-2">
                        Gestion des transactions
                        <select name="transaction_mode" class="<?= $field ?>">
                            <option value="join"<?= $selected($inventorySettings['transaction_mode'] === 'join') ?>>
                                Rejoindre la transaction existante
                            </option>
                            <option value="own_only"<?= $selected($inventorySettings['transaction_mode'] === 'own_only') ?>>
                                Utiliser uniquement une transaction propre
                            </option>
                        </select>
                    </label>
                </div>
            </section>

            <section aria-labelledby="inventory-display-settings-title"
                class="mt-6 border-t border-slate-100 pt-5 dark:border-slate-800">
                <h3 id="inventory-display-settings-title" class="text-sm font-semibold text-slate-900 dark:text-white">
                    Affichage et quantités par défaut
                </h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Vue simplifiée
                        <select name="simple_dashboard_enabled" class="<?= $field ?>">
                            <option value="1"<?= $selected($inventorySettings['simple_dashboard_enabled']) ?>>Activée</option>
                            <option value="0"<?= $selected(!$inventorySettings['simple_dashboard_enabled']) ?>>Désactivée</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Seuil de stock faible par défaut
                        <input type="number" name="default_min_qty" value="<?= $escape($inventorySettings['default_min_qty']) ?>"
                            required min="0" step="0.000001" inputmode="decimal" class="<?= $field ?>">
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-300">
                        Quantité de mouvement préremplie
                        <input type="number" name="default_movement_quantity"
                            value="<?= $escape($inventorySettings['default_movement_quantity']) ?>" required min="0.000001"
                            step="0.000001" inputmode="decimal" class="<?= $field ?>">
                    </label>
                </div>
            </section>
        </div>

        <footer class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/80 px-6 py-4 dark:border-slate-800 dark:bg-slate-950/40">
            <button type="button" data-inventory-settings-close
                class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2.5 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                Annuler
            </button>
            <button type="submit"
                class="inline-flex items-center justify-center rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-teal-500 dark:text-slate-950">
                Enregistrer la configuration
            </button>
        </footer>
    </form>
</dialog>
