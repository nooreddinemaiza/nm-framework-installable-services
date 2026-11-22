<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$card = 'rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900/60';
$field = 'mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white';
$button = 'inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';
$data = $online_payments ?? [];
$configuration = $data['configuration'] ?? [];
$availableProviders = $data['available_providers'] ?? [];
?>

<x-container :reduiceable="false" title="Paiement en ligne" :scrollable="true" class="mx-auto w-full"
    maxHeight="88vh" size="xl" :padded="false">
    <x-slot:components class="mx-3 my-4 space-y-6">
        <?php if (!empty($online_payment_message)): ?>
            <x-alert :variant="$online_payment_variant ?? 'success'" :message="$online_payment_message" />
        <?php endif; ?>

        <section class="<?= $card ?>">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">État du service</h2>
            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                Le noyau est prêt pour des adaptateurs hébergés. Aucun fournisseur n’est activé : aucun paiement ne peut
                être lancé ou confirmé tant qu’un adaptateur réel n’est pas installé et configuré.
            </p>
            <?php if ($availableProviders === []): ?>
                <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    Aucun adaptateur de paiement n’est actuellement enregistré. Les paiements en ligne restent indisponibles.
                </p>
            <?php endif; ?>
        </section>

        <section class="<?= $card ?>">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Configuration générale</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Région initiale : Maroc. Les paiements partiels restent désactivés par défaut.
            </p>
            <form method="post" action="/dashboard/online-payments/configuration" class="mt-4 grid gap-4 sm:grid-cols-3">
                <input type="hidden" name="_token" value="<?= $escape($csrf_token ?? '') ?>">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Pays du compte marchand
                    <input class="<?= $field ?>" name="merchant_country" maxlength="2" required
                        value="<?= $escape($configuration['merchant_country'] ?? 'MA') ?>">
                </label>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Devise
                    <input class="<?= $field ?>" name="currency" maxlength="3" required
                        value="<?= $escape($configuration['currency'] ?? 'MAD') ?>">
                </label>
                <label class="flex items-center gap-2 pt-6 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="allow_partial_payments" value="0">
                    <input type="checkbox" name="allow_partial_payments" value="1"
                        <?= !empty($configuration['allow_partial_payments']) ? 'checked' : '' ?>>
                    Autoriser les paiements partiels
                </label>
                <div class="sm:col-span-3">
                    <button class="<?= $button ?>" type="submit">Enregistrer la configuration</button>
                </div>
            </form>
        </section>

        <section class="<?= $card ?>">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Fournisseurs installés</h2>
            <?php if ($availableProviders !== []): ?>
                <div class="mt-4 space-y-4">
                    <?php foreach ($availableProviders as $provider): ?>
                        <form method="post" action="/dashboard/online-payments/providers/save" class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <input type="hidden" name="_token" value="<?= $escape($csrf_token ?? '') ?>">
                            <input type="hidden" name="code" value="<?= $escape($provider['code']) ?>">
                            <input type="hidden" name="enabled" value="0">
                            <h3 class="font-semibold text-gray-900 dark:text-white"><?= $escape($provider['name']) ?></h3>
                            <label class="mt-3 block text-sm text-gray-700 dark:text-gray-300">
                                Configuration secrète JSON (stockée chiffrée)
                                <textarea class="<?= $field ?>" name="credentials_json" rows="4" autocomplete="off"
                                    placeholder='{"api_key":"…","webhook_secret":"…"}'></textarea>
                            </label>
                            <label class="mt-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="enabled" value="1">
                                Activer ce fournisseur
                            </label>
                            <button class="<?= $button ?> mt-4" type="submit">Enregistrer le fournisseur</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Les paramètres propres à un fournisseur apparaîtront avec son adaptateur.</p>
            <?php endif; ?>
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Clé de chiffrement des secrets :
                <?= !empty($data['encryption_key_configured']) ? 'configurée' : 'à configurer avant d’ajouter des identifiants' ?>.
                Variable serveur : <code>ONLINE_PAYMENTS_ENCRYPTION_KEY</code> (32 octets encodés en hexadécimal ou Base64).
            </p>
        </section>

        <section class="<?= $card ?>">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Tentatives récentes</h2>
            <form method="post" action="/dashboard/online-payments/reconciliation/retry" class="mt-4">
                <input type="hidden" name="_token" value="<?= $escape($csrf_token ?? '') ?>">
                <button class="<?= $button ?>" type="submit">Reprendre les rapprochements en attente</button>
            </form>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <?php foreach (($data['statuses'] ?? []) as $status => $count): ?>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                        <dt class="text-gray-500 dark:text-gray-400"><?= $escape($status) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 dark:text-white"><?= (int) $count ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php if (($data['attempts'] ?? []) !== []): ?>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b border-gray-200 dark:border-gray-800">
                            <th class="py-2 pr-3">Commande</th><th class="py-2 pr-3">Montant</th><th class="py-2 pr-3">État</th><th class="py-2">Créée</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($data['attempts'] as $attempt): ?>
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-3"><?= $escape($attempt['order_reference']) ?></td>
                                    <td class="py-2 pr-3"><?= $escape($attempt['amount_minor']) ?> <?= $escape($attempt['currency']) ?></td>
                                    <td class="py-2 pr-3"><?= $escape($attempt['status']) ?></td>
                                    <td class="py-2"><?= $escape($attempt['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Aucune tentative de paiement enregistrée.</p>
            <?php endif; ?>
        </section>
    </x-slot:components>
</x-container>
