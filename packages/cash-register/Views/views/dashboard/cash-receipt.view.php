<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$receipt = $cash_receipt;
$amount = \App\Cash\CashRegisterService::formatAmount((int) $receipt['amount_minor']);
$cashReceived = $receipt['cash_received_minor'] === null
    ? null
    : \App\Cash\CashRegisterService::formatAmount((int) $receipt['cash_received_minor']);
$change = \App\Cash\CashRegisterService::formatAmount((int) $receipt['change_minor']);
$createdAt = (new DateTimeImmutable((string) $receipt['created_at'], new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone((string) $cash_timezone))
    ->format('d/m/Y H:i:s');
$isRefund = $receipt['receipt_type'] === 'refund';
?>

<main class="mx-auto max-w-2xl space-y-5 px-4 py-8 sm:px-6">
    <div class="flex items-center justify-between gap-3 print:hidden">
        <a href="/dashboard/cash" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-300">Retour à la gestion des caisses</a>
        <span class="text-xs text-slate-500">Utilisez la fonction d’impression du navigateur pour imprimer ce reçu.</span>
    </div>
    <article class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <header class="border-b border-dashed border-slate-300 pb-5 text-center dark:border-slate-700">
            <p class="text-xs font-semibold uppercase tracking-widest text-slate-500"><?= $isRefund ? 'Justificatif de remboursement' : 'Reçu de caisse' ?></p>
            <h1 class="mt-2 text-2xl font-bold text-slate-900 dark:text-white"><?= $escape($receipt['store_name_snapshot']) ?></h1>
            <?php if (!empty($receipt['business_reference_snapshot'])): ?>
                <p class="mt-1 text-sm text-slate-500"><?= $escape($receipt['business_reference_snapshot']) ?></p>
            <?php endif; ?>
            <p class="mt-4 font-mono text-lg font-semibold"><?= $escape($receipt['receipt_number']) ?></p>
        </header>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 py-5 text-sm">
            <dt class="text-slate-500">Commande Ventes</dt><dd class="text-right font-semibold"><?= $escape($receipt['order_reference']) ?></dd>
            <dt class="text-slate-500">Caisse</dt><dd class="text-right font-semibold"><?= $escape($receipt['register_name_snapshot']) ?> (<?= $escape($receipt['register_code_snapshot']) ?>)</dd>
            <dt class="text-slate-500">Session</dt><dd class="text-right font-semibold">#<?= (int) $receipt['session_id'] ?></dd>
            <dt class="text-slate-500">Date</dt><dd class="text-right font-semibold"><?= $escape($createdAt) ?></dd>
            <dt class="text-slate-500">Moyen</dt><dd class="text-right font-semibold">Espèces</dd>
        </dl>
        <div class="border-y border-dashed border-slate-300 py-5 dark:border-slate-700">
            <div class="flex items-center justify-between gap-3 text-lg font-bold">
                <span><?= $isRefund ? 'Montant remboursé' : 'Montant encaissé' ?></span>
                <span><?= $isRefund ? '−' : '' ?><?= $escape($amount) ?> <?= $escape($receipt['currency']) ?></span>
            </div>
            <?php if (!$isRefund): ?>
                <dl class="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                    <div class="flex justify-between gap-3"><dt>Espèces reçues</dt><dd><?= $escape($cashReceived ?? '—') ?> <?= $escape($receipt['currency']) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt>Monnaie rendue</dt><dd><?= $escape($change) ?> <?= $escape($receipt['currency']) ?></dd></div>
                </dl>
            <?php endif; ?>
            <?php if ($isRefund && !empty($receipt['related_sales_payment_id'])): ?>
                <p class="mt-3 text-sm text-slate-500">Paiement d’origine associé : #<?= $escape($receipt['related_sales_payment_id']) ?></p>
            <?php endif; ?>
            <?php if ($isRefund && !empty($receipt['reason'])): ?>
                <p class="mt-3 text-sm"><span class="text-slate-500">Motif :</span> <?= $escape($receipt['reason']) ?></p>
            <?php endif; ?>
        </div>
        <footer class="pt-5 text-center text-xs text-slate-500">
            Reçu de traçabilité du Service Caisse. Ce document n’est pas une facture fiscale.
        </footer>
    </article>
</main>
