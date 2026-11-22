<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$details = $order->details['checkout'] ?? [];
$discountSnapshots = is_array($details['discounts'] ?? null) ? $details['discounts'] : [];
$totalSaving = 0;
foreach ($discountSnapshots as $snapshot) {
    if (is_array($snapshot)) {
        $totalSaving += max(0, (int) ($snapshot['saving_minor'] ?? 0));
    }
}
$customer = is_array($details['customer'] ?? null) ? $details['customer'] : [];
$fulfillment = is_array($details['fulfillment'] ?? null) ? $details['fulfillment'] : [];
$address = is_array($fulfillment['address'] ?? null) ? $fulfillment['address'] : [];
$statusLabels = ['pending' => 'En attente de confirmation', 'confirmed' => 'Confirmée', 'processing' => 'En préparation', 'ready' => 'Prête', 'completed' => 'Terminée', 'cancelled' => 'Annulée'];
$formatMoney = static fn(int $minor, string $currency): string =>
    str_replace('.', ',', (new \NM\Services\SalesService\Sales\Domain\Money($minor, $currency))->format());

$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$panel = 'rounded-md bg-white p-6 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-8';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
<style>
    .shelf { font-family: 'Anybody', ui-sans-serif, system-ui, sans-serif; }
    .shelf-sign { transform-origin: 50% 0; transform: rotate(-1.2deg); }
</style>
@include('partials.site-nav', ['active' => ''])
<main data-sales-checkout-complete class="shelf min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc]">
    <div class="mx-auto max-w-3xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a href="/" class="rounded underline underline-offset-4 <?= $focus ?>">Accueil</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page">Commande enregistrée</span>
        </nav>

        <header class="mt-8">
            <div class="shelf-sign w-fit max-w-full">
                <div class="flex justify-between px-8" aria-hidden="true">
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                </div>
                <h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-7xl">Merci <?= $escape($customer['name'] ?? '') ?> !</h1>
            </div>
            <p class="mt-6 inline-flex items-center gap-2 rounded-md bg-[#10302c] px-2.5 py-1 text-xs font-bold text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                Commande enregistrée
            </p>
        </header>

        <p class="mt-6 max-w-2xl text-base leading-7">
            Votre commande <strong class="font-extrabold tabular-nums">#<?= $escape($order->id) ?></strong> est <?= $escape($statusLabels[$order->status] ?? $order->status) ?>.
            Un membre de l’équipe confirmera les détails de remise.
        </p>

        <?php if (!empty($online_payment_error)): ?>
            <p role="status" class="mt-5 rounded-md border border-[#10302c] bg-white p-4 text-sm font-bold dark:border-[#3a5f59] dark:bg-[#143632]">
                <?= $escape($online_payment_error) ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($online_payment_available) && is_array($online_payment_quote ?? null) && (int) $online_payment_quote['balance_due_minor'] > 0): ?>
            <section class="mt-8 rounded-md border-2 border-[#10302c] bg-white p-5 dark:border-[#3a5f59] dark:bg-[#143632]">
                <h2 class="text-lg font-extrabold">Régler en ligne</h2>
                <p class="mt-2 text-sm">Solde restant :
                    <strong><?= $escape($formatMoney((int) $online_payment_quote['balance_due_minor'], (string) $online_payment_quote['currency'])) ?></strong>
                </p>
                <form method="post" action="/checkout/confirmation/<?= rawurlencode((string) $order->id) ?>/pay-online" class="mt-4 flex flex-wrap items-end gap-3">
                    <input type="hidden" name="_token" value="<?= $escape($csrf_token ?? '') ?>">
                    <?php if (!empty($online_payment_configuration['allow_partial_payments'])): ?>
                        <label class="block text-sm font-bold">
                            Montant (<?= $escape($online_payment_quote['currency']) ?>)
                            <input name="amount" type="number" min="0.01" step="0.01" inputmode="decimal"
                                max="<?= $escape(number_format((int) $online_payment_quote['balance_due_minor'] / 100, 2, '.', '')) ?>"
                                class="mt-1 block w-52 rounded-md border border-[#10302c] bg-white px-3 py-2 text-[#10302c]"
                                placeholder="Laisser vide pour le solde complet">
                        </label>
                    <?php endif; ?>
                    <button type="submit" class="min-h-11 rounded-md bg-[#ff7a1a] px-5 font-extrabold text-[#10302c] shadow-[0_4px_0_#10302c] <?= $focus ?>">
                        Continuer vers le paiement sécurisé
                    </button>
                </form>
                <p class="mt-3 text-xs">La commande ne sera marquée payée qu’après vérification de la notification du fournisseur.</p>
            </section>
        <?php elseif (empty($online_payment_available)): ?>
            <p class="mt-4 text-sm">Aucun paiement en ligne n’a été effectué ; cette option n’est pas encore configurée.</p>
        <?php endif; ?>

        <?php if (is_string($public_reference) && $public_reference !== ''): ?>
            <div class="mt-8 rounded-md border-2 border-dashed border-[#10302c] bg-white p-5 dark:border-[#3a5f59] dark:bg-[#143632]">
                <p class="text-sm font-bold">Référence à conserver</p>
                <p class="mt-2 inline-block max-w-full break-all rounded-md bg-[#ff7a1a] px-4 py-2 text-2xl font-extrabold tabular-nums leading-none text-[#10302c] shadow-[0_4px_0_#10302c] [font-stretch:80%] dark:shadow-[0_4px_0_#3a5f59] sm:text-3xl"><?= $escape($public_reference) ?></p>
                <div class="mt-4">
                    <a class="inline-flex min-h-11 items-center rounded-md bg-white px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] <?= $focus ?>" href="/suivi-commande">Consulter l’état de la commande</a>
                </div>
            </div>
        <?php endif; ?>

        <?php /* Étiquette suspendue : détail de la commande */ ?>
        <section class="relative mt-12 pt-10 before:absolute before:left-1/2 before:top-3 before:h-3.5 before:w-3.5 before:-translate-x-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a]" aria-label="Détail de la commande">
            <span class="absolute left-1/2 top-0 h-3 w-px bg-[#10302c] dark:bg-[#8fb8ad]" aria-hidden="true"></span>
            <div class="<?= $panel ?>">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-bold opacity-75">E-mail</dt><dd class="mt-1 break-all font-bold"><?= $escape($customer['email'] ?? '') ?></dd></div>
                    <div><dt class="text-xs font-bold opacity-75">Téléphone</dt><dd class="mt-1 font-bold"><?= $escape($customer['phone'] ?? '') ?></dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs font-bold opacity-75">Remise</dt><dd class="mt-1 break-words font-bold">
                        <?php if (($fulfillment['mode'] ?? '') === 'delivery'): ?>
                            Livraison demandée — <?= $escape(implode(', ', array_filter([$address['street'] ?? '', $address['city'] ?? '', $address['postal_code'] ?? '']))) ?>
                        <?php else: ?>
                            Retrait à confirmer
                        <?php endif; ?>
                    </dd></div>
                </dl>

                <ul class="mt-6 divide-y-2 divide-dashed divide-[#10302c]/25 border-t-2 border-dashed border-[#10302c]/25 dark:divide-[#cfe8dc]/25 dark:border-[#cfe8dc]/25">
                    <?php foreach ($order->lines as $index => $line): ?>
                        <?php
                        $discountSnapshot = is_array($discountSnapshots[$index] ?? null) ? $discountSnapshots[$index] : [];
                        $lineSaving = max(0, (int) ($discountSnapshot['saving_minor'] ?? 0));
                        $originalLineAmount = (int) ($discountSnapshot['original_line_amount_minor'] ?? $line['line_amount_minor']);
                        ?>
                        <li class="flex items-start justify-between gap-4 py-4 text-sm">
                            <span class="min-w-0 break-words font-bold">
                                <?= $escape($line['label']) ?> <span class="font-normal opacity-75 tabular-nums">× <?= $escape($line['quantity']) ?></span>
                                <?php if ($lineSaving > 0): ?>
                                    <span class="mt-1 block text-xs font-bold">
                                        <span class="rounded-md bg-[#10302c] px-2 py-0.5 text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]">Réduction</span>
                                        −<?= $escape($formatMoney($lineSaving, (string) $line['currency'])) ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <span class="shrink-0 text-right tabular-nums">
                                <?php if ($lineSaving > 0): ?>
                                    <span class="block text-xs line-through opacity-75"><?= $escape($formatMoney($originalLineAmount, (string) $line['currency'])) ?></span>
                                <?php endif; ?>
                                <span class="block font-extrabold"><?= $escape($formatMoney((int) $line['line_amount_minor'], (string) $line['currency'])) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($totalSaving > 0): ?>
                    <p class="text-right text-sm font-bold tabular-nums">
                        Économie totale : <?= $escape($formatMoney($totalSaving, $order->total->currency)) ?>
                    </p>
                <?php endif; ?>
                <div class="mt-4 flex items-center justify-between gap-3 border-t-2 border-[#10302c] pt-4 dark:border-[#cfe8dc]">
                    <span class="font-bold">Total des articles</span>
                    <span class="inline-block rounded-md bg-[#ff7a1a] px-4 py-2 text-3xl font-extrabold tabular-nums leading-none text-[#10302c] shadow-[0_4px_0_#10302c] [font-stretch:80%] dark:shadow-[0_4px_0_#3a5f59]"><?= $escape($formatMoney($order->total->minorAmount, $order->total->currency)) ?></span>
                </div>
            </div>
        </section>

        <a href="/" class="mt-12 inline-flex min-h-12 items-center justify-center rounded-md bg-[#ff7a1a] px-6 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:shadow-[0_4px_0_#3a5f59] <?= $focus ?>">Continuer les achats</a>
    </div>
</main>