<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$config = $cash_data['configuration'];
$registers = $cash_data['registers'];
$sessions = $cash_data['sessions'];
$users = $cash_data['users'];
$selectedSessionId = $cash_selected_session_id ?? 0;
$orderReference = $cash_order_reference ?? '';
$orderQuote = $cash_order_quote ?? null;
$quoteError = $cash_quote_error ?? '';
$cash_can_pay = $cash_can_pay ?? false;
$cash_can_refund = $cash_can_refund ?? false;
$cash_can_view_receipt = $cash_can_view_receipt ?? false;
$statusLabels = ['active' => 'Active', 'inactive' => 'Inactive', 'maintenance' => 'En maintenance'];
$financialStatusLabels = [
    'unpaid' => 'Non payée',
    'partially_paid' => 'Partiellement payée',
    'paid' => 'Payée',
    'partially_refunded' => 'Partiellement remboursée',
    'refunded' => 'Remboursée',
];
$input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white';
$primary = 'inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';
$secondary = 'inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';
$panel = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/70';
$format = static fn(mixed $amount): string => \App\Cash\CashRegisterService::formatAmount((int) $amount);
$localize = static fn(string $value): string => (new DateTimeImmutable($value, new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone((string) $config['timezone']))
    ->format('d/m/Y H:i:s');
$boolOptions = static function (string $name, bool $value, string $label) use ($input): void { ?>
    <label class="block text-sm text-slate-700 dark:text-slate-300">
        <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <select name="<?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="<?= $input ?>">
            <option value="1" <?= $value ? 'selected' : '' ?>>Oui</option>
            <option value="0" <?= !$value ? 'selected' : '' ?>>Non</option>
        </select>
    </label>
<?php }; ?>

<main class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">Ventes · sous-service</p>
            <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Gestion des caisses</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
                Les commandes et leur solde financier restent gérés par Ventes. Le service Caisse enregistre les paiements espèces, leurs mouvements de session et leurs reçus sans recopier les lignes de commande.
            </p>
        </div>
        <a href="/dashboard/sales" class="<?= $secondary ?>">Retour aux ventes</a>
    </header>

    <?php if (!empty($cash_message)): ?>
        <div role="status" class="rounded-xl border px-4 py-3 text-sm <?= ($cash_variant ?? '') === 'danger' ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200' : 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' ?>">
            <?= $escape($cash_message) ?>
        </div>
    <?php endif; ?>

    <section class="<?= $panel ?>" aria-labelledby="cash-config-title">
        <div class="mb-5">
            <h2 id="cash-config-title" class="text-xl font-semibold text-slate-900 dark:text-white">Configuration générale</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Paramètres partagés par le service et ses sessions.</p>
        </div>
        <?php if ($cash_can_configure): ?>
            <form method="post" action="/dashboard/cash/configuration" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                <label class="block text-sm text-slate-700 dark:text-slate-300">Nom du service<input required maxlength="120" name="service_name" value="<?= $escape($config['service_name']) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Nom du commerce<input required maxlength="160" name="store_name" value="<?= $escape($config['store_name']) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Référence interne<input maxlength="80" name="business_reference" value="<?= $escape($config['business_reference']) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Devise principale<input required pattern="[A-Za-z]{3}" maxlength="3" name="currency" value="<?= $escape($config['currency']) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Fuseau horaire<input required name="timezone" value="<?= $escape($config['timezone']) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Nombre initial prévu<input required type="number" min="1" max="1000" name="planned_register_count" value="<?= (int) $config['planned_register_count'] ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Maximum autorisé<input required type="number" min="1" max="1000" name="max_register_count" value="<?= (int) $config['max_register_count'] ?>" class="<?= $input ?>"></label>

                <?php
                $boolOptions('sessions_enabled', (bool) $config['sessions_enabled'], 'Gestion des sessions activée');
                $boolOptions('require_session', (bool) $config['require_session'], 'Session obligatoire avant encaissement');
                $boolOptions('require_opening_float', (bool) $config['require_opening_float'], 'Fond de caisse obligatoire');
                $boolOptions('allow_zero_opening_float', (bool) $config['allow_zero_opening_float'], 'Autoriser un fond initial nul');
                $boolOptions('require_physical_count', (bool) $config['require_physical_count'], 'Comptage obligatoire à la fermeture');
                $boolOptions('allow_cash_variance', (bool) $config['allow_cash_variance'], 'Autoriser la fermeture avec écart');
                $boolOptions('variance_manager_approval', (bool) $config['variance_manager_approval'], 'Validation responsable des écarts');
                $boolOptions('allow_other_user_close', (bool) $config['allow_other_user_close'], 'Autoriser un autre utilisateur à fermer');
                $boolOptions('require_variance_reason', (bool) $config['require_variance_reason'], 'Justification obligatoire en cas d’écart');
                $boolOptions('allow_partial_payments', (bool) $config['allow_partial_payments'], 'Autoriser les paiements partiels en espèces');
                ?>
                <input type="hidden" name="allow_split_payments" value="0">
                <input type="hidden" name="auto_print_receipts" value="0">
                <p class="self-center text-xs text-slate-500 dark:text-slate-400">Paiements combinés et impression automatique désactivés tant qu’un connecteur externe ou une interface d’impression n’est pas disponible.</p>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Seuil d’approbation d’écart<input type="number" min="0" step="0.01" name="variance_approval_threshold" value="<?= $escape($format($config['variance_approval_threshold_minor'])) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Format des reçus<input required maxlength="80" name="receipt_number_format" value="<?= $escape($config['receipt_number_format']) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Format des opérations<input required maxlength="80" name="operation_number_format" value="<?= $escape($config['operation_number_format']) ?>" class="<?= $input ?>"></label>
                <div class="sm:col-span-2 lg:col-span-3"><button class="<?= $primary ?>" type="submit">Enregistrer la configuration</button></div>
            </form>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Seuls les paiements espèces sont actuellement exécutables. Carte, chèque, virement et paiement en ligne restent indisponibles sans connecteur de confirmation dédié.</p>
        <?php else: ?>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">Service</dt><dd class="font-semibold"><?= $escape($config['service_name']) ?></dd></div>
                <div><dt class="text-slate-500">Commerce</dt><dd class="font-semibold"><?= $escape($config['store_name']) ?></dd></div>
                <div><dt class="text-slate-500">Devise</dt><dd class="font-semibold"><?= $escape($config['currency']) ?></dd></div>
                <div><dt class="text-slate-500">Fuseau horaire</dt><dd class="font-semibold"><?= $escape($config['timezone']) ?></dd></div>
            </dl>
        <?php endif; ?>
    </section>

    <?php if ($cash_can_create): ?>
        <section class="<?= $panel ?>" aria-labelledby="cash-register-create-title">
            <h2 id="cash-register-create-title" class="mb-4 text-xl font-semibold text-slate-900 dark:text-white">Ajouter une caisse</h2>
            <form method="post" action="/dashboard/cash/registers/save" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                <label class="block text-sm text-slate-700 dark:text-slate-300">Référence unique<input required maxlength="40" name="code" placeholder="CAISSE-001" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Nom<input required maxlength="120" name="name" placeholder="Caisse principale" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Emplacement<input maxlength="120" name="location" placeholder="Accueil" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Description<textarea maxlength="4000" name="description" rows="2" class="<?= $input ?>"></textarea></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Devise<input required pattern="[A-Za-z]{3}" maxlength="3" name="currency" value="<?= $escape($config['currency']) ?>" class="<?= $input ?>"></label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Fond suggéré à l’ouverture<input type="number" min="0" step="0.01" name="suggested_opening" value="0.00" class="<?= $input ?>"></label>
                <?php if ($cash_can_assign): ?>
                    <label class="block text-sm text-slate-700 dark:text-slate-300 sm:col-span-2">Caissiers autorisés
                        <select multiple required name="allowed_user_ids[]" class="<?= $input ?> min-h-28">
                            <?php foreach ($users as $cashUser): ?>
                                <option value="<?= (int) $cashUser['id'] ?>"><?= $escape($cashUser['name'] . ' (' . $cashUser['username'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="mt-1 block text-xs text-slate-500">Sélectionnez uniquement les comptes autorisés. Les paiements gérés ici sont limités aux espèces.</span>
                    </label>
                <?php endif; ?>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Sorties de fonds
                    <select name="allow_cash_outflows" class="<?= $input ?>"><option value="1">Autorisées</option><option value="0">Interdites</option></select>
                </label>
                <label class="block text-sm text-slate-700 dark:text-slate-300">Remboursements espèces
                    <select name="allow_refunds" class="<?= $input ?>"><option value="1">Autorisés</option><option value="0" selected>Interdits</option></select>
                </label>
                <div class="self-end"><button class="<?= $primary ?>" type="submit">Créer la caisse</button></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="space-y-4" aria-labelledby="cash-register-list-title">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="cash-register-list-title" class="text-xl font-semibold text-slate-900 dark:text-white">Caisses et sessions en cours</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= count($registers) ?> caisse(s) configurée(s) · le nombre de sessions historiques reste indépendant.</p>
            </div>
        </div>
        <?php foreach ($registers as $register): ?>
            <?php $activeSession = $register['active_session']; ?>
            <article class="<?= $panel ?>">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white"><?= $escape($register['name']) ?></h3>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200"><?= $escape($register['code']) ?></span>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $register['status'] === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200' ?>"><?= $escape($statusLabels[$register['status']] ?? $register['status']) ?></span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= $escape($register['location'] ?: 'Emplacement non précisé') ?> · <?= $escape($register['currency']) ?> · Moyen autorisé : espèces</p>
                    </div>
                    <?php if ($cash_can_manage_status): ?>
                        <form method="post" action="/dashboard/cash/registers/status" class="flex items-center gap-2">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                            <input type="hidden" name="register_id" value="<?= (int) $register['id'] ?>">
                            <select name="status" aria-label="Statut de la caisse" class="<?= $input ?> mt-0">
                                <?php foreach ($statusLabels as $key => $label): ?>
                                    <option value="<?= $escape($key) ?>" <?= $register['status'] === $key ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="<?= $secondary ?>" type="submit">Modifier</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($cash_can_configure): ?>
                    <details class="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                        <summary class="cursor-pointer text-sm font-semibold text-slate-700 dark:text-slate-200">Modifier la configuration</summary>
                        <form method="post" action="/dashboard/cash/registers/save" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                            <input type="hidden" name="id" value="<?= (int) $register['id'] ?>">
                            <input type="hidden" name="status" value="<?= $escape($register['status']) ?>">
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Référence<input required maxlength="40" name="code" value="<?= $escape($register['code']) ?>" class="<?= $input ?>"></label>
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Nom<input required maxlength="120" name="name" value="<?= $escape($register['name']) ?>" class="<?= $input ?>"></label>
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Emplacement<input maxlength="120" name="location" value="<?= $escape($register['location']) ?>" class="<?= $input ?>"></label>
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Description<textarea maxlength="4000" name="description" rows="2" class="<?= $input ?>"><?= $escape($register['description']) ?></textarea></label>
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Devise<input required pattern="[A-Za-z]{3}" maxlength="3" name="currency" value="<?= $escape($register['currency']) ?>" class="<?= $input ?>"></label>
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Fond suggéré<input type="number" min="0" step="0.01" name="suggested_opening" value="<?= $escape($format($register['suggested_opening_minor'])) ?>" class="<?= $input ?>"></label>
                            <?php if ($cash_can_assign): ?>
                                <label class="block text-sm text-slate-700 dark:text-slate-300 sm:col-span-2">Caissiers autorisés
                                    <select multiple name="allowed_user_ids[]" class="<?= $input ?> min-h-28">
                                        <?php foreach ($users as $cashUser): ?>
                                            <option value="<?= (int) $cashUser['id'] ?>" <?= in_array((int) $cashUser['id'], $register['allowed_user_ids'], true) ? 'selected' : '' ?>><?= $escape($cashUser['name'] . ' (' . $cashUser['username'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Sorties de fonds
                                <select name="allow_cash_outflows" class="<?= $input ?>"><option value="1" <?= $register['allow_cash_outflows'] ? 'selected' : '' ?>>Autorisées</option><option value="0" <?= !$register['allow_cash_outflows'] ? 'selected' : '' ?>>Interdites</option></select>
                            </label>
                            <label class="block text-sm text-slate-700 dark:text-slate-300">Remboursements espèces
                                <select name="allow_refunds" class="<?= $input ?>"><option value="1" <?= $register['allow_refunds'] ? 'selected' : '' ?>>Autorisés</option><option value="0" <?= !$register['allow_refunds'] ? 'selected' : '' ?>>Interdits</option></select>
                            </label>
                            <div class="self-end"><button class="<?= $primary ?>" type="submit">Enregistrer</button></div>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if ($activeSession === null && $cash_can_open && $register['status'] === 'active' && $config['sessions_enabled']): ?>
                    <form method="post" action="/dashboard/cash/sessions/open" class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                        <input type="hidden" name="register_id" value="<?= (int) $register['id'] ?>">
                        <label class="block min-w-48 text-sm text-slate-700 dark:text-slate-300">Fond à l’ouverture (<?= $escape($register['currency']) ?>)
                            <input type="number" min="0" step="0.01" name="opening_float" value="<?= $escape($format($register['suggested_opening_minor'])) ?>" class="<?= $input ?>">
                        </label>
                        <button class="<?= $primary ?>" type="submit">Ouvrir une session</button>
                    </form>
                <?php elseif ($activeSession !== null): ?>
                    <div class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-500/25 dark:bg-indigo-500/5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">Session #<?= (int) $activeSession['id'] ?> active</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Ouverte le <?= $escape($localize($activeSession['opened_at'])) ?> · fond initial <?= $escape($format($activeSession['opening_float_minor'])) ?> <?= $escape($activeSession['currency']) ?></p>
                            </div>
                        </div>
                        <?php if ($cash_can_pay): ?>
                            <form method="get" action="/dashboard/cash" class="mt-4 flex flex-wrap items-end gap-3 border-t border-indigo-100 pt-4 dark:border-indigo-500/20">
                                <input type="hidden" name="session_id" value="<?= (int) $activeSession['id'] ?>">
                                <label class="block min-w-64 flex-1 text-sm text-slate-700 dark:text-slate-300">Référence de commande Ventes
                                    <input required maxlength="20" name="order_reference" value="<?= (int) $selectedSessionId === (int) $activeSession['id'] ? $escape($orderReference) : '' ?>" placeholder="CMD-..." class="<?= $input ?>">
                                </label>
                                <button class="<?= $secondary ?> self-end" type="submit">Rechercher le solde</button>
                            </form>
                            <?php if ((int) $selectedSessionId === (int) $activeSession['id'] && $quoteError !== ''): ?>
                                <p role="alert" class="mt-3 text-sm text-red-700 dark:text-red-300"><?= $escape($quoteError) ?></p>
                            <?php endif; ?>
                            <?php if ((int) $selectedSessionId === (int) $activeSession['id'] && is_array($orderQuote)): ?>
                                <div class="mt-3 rounded-lg bg-white p-3 text-sm dark:bg-slate-900">
                                    <p class="font-semibold"><?= $escape($orderQuote['reference']) ?> · <?= $escape($orderQuote['status']) ?> · <?= $escape($financialStatusLabels[$orderQuote['financial_status']] ?? $orderQuote['financial_status']) ?></p>
                                    <p class="mt-1 text-slate-600 dark:text-slate-400">Total <?= $escape($format($orderQuote['total_minor'])) ?> <?= $escape($orderQuote['currency']) ?> · déjà encaissé <?= $escape($format($orderQuote['paid_minor'] - $orderQuote['refunded_minor'])) ?> · solde <?= $escape($format($orderQuote['balance_due_minor'])) ?></p>
                                    <?php if ((int) $orderQuote['balance_due_minor'] > 0): ?>
                                        <form method="post" action="/dashboard/cash/payments" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                                            <input type="hidden" name="session_id" value="<?= (int) $activeSession['id'] ?>">
                                            <input type="hidden" name="order_reference" value="<?= $escape($orderQuote['reference']) ?>">
                                            <input type="hidden" name="idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
                                            <label class="block text-sm text-slate-700 dark:text-slate-300">À encaisser
                                                <input required type="number" min="0.01" max="<?= $escape($format($orderQuote['balance_due_minor'])) ?>" step="0.01" name="amount" value="<?= $escape($format($orderQuote['balance_due_minor'])) ?>" class="<?= $input ?>">
                                            </label>
                                            <label class="block text-sm text-slate-700 dark:text-slate-300">Espèces reçues
                                                <input required type="number" min="0.01" step="0.01" name="cash_received" value="<?= $escape($format($orderQuote['balance_due_minor'])) ?>" class="<?= $input ?>">
                                            </label>
                                            <p class="self-end text-xs text-slate-500 dark:text-slate-400">La monnaie rendue est calculée côté serveur. Les paramètres de règlement Ventes sont revérifiés au moment de l’écriture.</p>
                                            <button class="<?= $primary ?> self-end" type="submit">Encaisser et émettre le reçu</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($cash_can_refund && $register['allow_refunds']): ?>
                            <form method="post" action="/dashboard/cash/refunds" class="mt-4 grid gap-3 border-t border-indigo-100 pt-4 dark:border-indigo-500/20 sm:grid-cols-2 lg:grid-cols-4">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                                <input type="hidden" name="session_id" value="<?= (int) $activeSession['id'] ?>">
                                <input type="hidden" name="idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Reçu d’encaissement d’origine
                                    <input required maxlength="80" name="original_receipt_number" class="<?= $input ?>">
                                </label>
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Montant à rembourser (<?= $escape($activeSession['currency']) ?>)
                                    <input required type="number" min="0.01" step="0.01" name="amount" class="<?= $input ?>">
                                </label>
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Motif obligatoire
                                    <input required maxlength="500" name="reason" class="<?= $input ?>">
                                </label>
                                <button class="<?= $secondary ?> self-end" type="submit">Rembourser en espèces</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($cash_can_move): ?>
                            <form method="post" action="/dashboard/cash/movements" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                                <input type="hidden" name="session_id" value="<?= (int) $activeSession['id'] ?>">
                                <input type="hidden" name="idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Type
                                    <select name="movement_type" class="<?= $input ?>">
                                        <?php if ($cash_can_entry): ?><option value="entry">Entrée de fonds</option><?php endif; ?>
                                        <?php if ($cash_can_outflow && $register['allow_cash_outflows']): ?><option value="outflow">Sortie de fonds</option><?php endif; ?>
                                    </select>
                                </label>
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Montant (<?= $escape($activeSession['currency']) ?>)<input required type="number" min="0.01" step="0.01" name="amount" class="<?= $input ?>"></label>
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Motif<input required maxlength="1000" name="reason" class="<?= $input ?>"></label>
                                <button class="<?= $primary ?> self-end" type="submit">Enregistrer le mouvement</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($cash_can_close): ?>
                            <form method="post" action="/dashboard/cash/sessions/close" class="mt-4 grid gap-3 border-t border-indigo-100 pt-4 dark:border-indigo-500/20 sm:grid-cols-3">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token) ?>">
                                <input type="hidden" name="session_id" value="<?= (int) $activeSession['id'] ?>">
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Comptage physique (<?= $escape($activeSession['currency']) ?>)
                                    <input type="number" min="0" step="0.01" name="counted_amount" class="<?= $input ?>" <?= $config['require_physical_count'] ? 'required' : '' ?>>
                                </label>
                                <label class="block text-sm text-slate-700 dark:text-slate-300">Justification d’écart
                                    <input maxlength="1000" name="variance_reason" class="<?= $input ?>">
                                </label>
                                <button class="<?= $primary ?> self-end" type="submit">Compter et clôturer</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ($registers === []): ?>
            <div class="<?= $panel ?> text-center text-sm text-slate-500 dark:text-slate-400">Aucune caisse configurée. Créez la première caisse ci-dessus.</div>
        <?php endif; ?>
    </section>

    <?php if ($cash_can_report): ?>
        <section class="<?= $panel ?>" aria-labelledby="cash-history-title">
            <h2 id="cash-history-title" class="text-xl font-semibold text-slate-900 dark:text-white">Historique des sessions</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[700px] text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-800"><tr><th class="py-2 pr-4">Session / caisse</th><th class="py-2 pr-4">Ouverture</th><th class="py-2 pr-4">État</th><th class="py-2 pr-4">Théorique</th><th class="py-2 pr-4">Compté</th><th class="py-2">Écart</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td class="py-3 pr-4 font-medium"><?= $escape($session['register']) ?> (<?= $escape($session['register_code']) ?>) · #<?= (int) $session['id'] ?></td>
                            <td class="py-3 pr-4"><?= $escape($localize($session['opened_at'])) ?><?php if (!empty($session['closed_at'])): ?><br><span class="text-xs text-slate-500">Fermée <?= $escape($localize($session['closed_at'])) ?></span><?php endif; ?></td>
                            <td class="py-3 pr-4"><?= $session['status'] === 'open' ? 'Active' : 'Clôturée' ?></td>
                            <td class="py-3 pr-4"><?= $session['expected_cash_minor'] === null ? '—' : $escape($format($session['expected_cash_minor']) . ' ' . $session['currency']) ?></td>
                            <td class="py-3 pr-4"><?= $session['counted_cash_minor'] === null ? '—' : $escape($format($session['counted_cash_minor']) . ' ' . $session['currency']) ?></td>
                            <td class="py-3"><?= $session['variance_minor'] === null ? '—' : $escape($format($session['variance_minor']) . ' ' . $session['currency']) ?></td>
                        </tr>
                        <?php if ($session['movements'] !== []): ?>
                            <tr><td colspan="6" class="pb-3 pl-4 text-xs text-slate-500">
                                <?php foreach ($session['movements'] as $movement): ?>
                                    <?= match ($movement['movement_type']) { 'entry' => 'Entrée', 'sale_payment' => 'Encaissement vente', 'sale_refund' => 'Remboursement vente', default => 'Sortie' } ?> <?= $escape($format($movement['amount_minor'])) ?> · <?= $escape($movement['reason']) ?> · <?= $escape($localize($movement['created_at'])) ?><?php if (($cash_can_view_receipt || ((int) $movement['actor_user_id'] === (int) ($user['id'] ?? 0) && ($cash_can_pay || $cash_can_refund))) && !empty($movement['receipt_id'])): ?> · <a class="font-semibold text-indigo-600 underline dark:text-indigo-300" href="/dashboard/cash/receipt?id=<?= (int) $movement['receipt_id'] ?>">Voir le reçu</a><?php endif; ?><br>
                                <?php endforeach; ?>
                            </td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($sessions === []): ?><tr><td colspan="6" class="py-6 text-center text-slate-500">Aucune session enregistrée.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
