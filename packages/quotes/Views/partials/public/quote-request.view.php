<div data-quote-request-root
     data-quote-source="<?= htmlspecialchars((string) ($quote_source ?? 'category'), ENT_QUOTES, 'UTF-8') ?>"
     data-quote-category="<?= (int) ($quote_category_id ?? 0) ?>"
     data-quote-brand="<?= (int) ($quote_brand_id ?? 0) ?>"
     data-quote-item="<?= (int) ($quote_item_id ?? 0) ?>">
    <button type="button" data-quote-open
        class="inline-flex min-h-11 items-center justify-center rounded-md border-2 border-[#10302c] px-5 text-sm font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5">
        Demander un devis
    </button>
    <dialog data-quote-dialog class="m-auto w-[min(44rem,calc(100vw-2rem))] rounded-md border-2 border-[#10302c] p-0">
        <form data-quote-form class="grid gap-4 p-5 sm:p-6" method="post">
            <div>
                <h2 class="text-2xl font-extrabold">Demander une estimation</h2>
                <p class="mt-1 text-sm text-slate-600">Votre sélection et vos informations sont vérifiées côté serveur.</p>
            </div>
            <label class="grid gap-1">
                <span>Nom complet *</span>
                <input class="rounded-md border px-3 py-2" name="customer_name" required maxlength="190">
            </label>
            <label class="grid gap-1">
                <span>E-mail *</span>
                <input class="rounded-md border px-3 py-2" name="customer_email" type="email" required maxlength="190">
            </label>
            <label class="grid gap-1">
                <span>Téléphone</span>
                <input class="rounded-md border px-3 py-2" name="customer_phone" maxlength="40">
            </label>
            <label class="grid gap-1">
                <span>Société</span>
                <input class="rounded-md border px-3 py-2" name="customer_company" maxlength="190">
            </label>
            <label class="grid gap-1">
                <span>Remarque</span>
                <textarea class="rounded-md border px-3 py-2" name="customer_note" rows="3"></textarea>
            </label>
            <input type="hidden" name="source" value="<?= htmlspecialchars((string) ($quote_source ?? 'category'), ENT_QUOTES, 'UTF-8') ?>">
            <?php if (($quote_source ?? '') === 'product'): ?>
                <p class="text-sm font-bold"><?= htmlspecialchars((string) ($quote_item_name ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
                <label class="grid gap-1">
                    <span>Rechercher un produit ou service</span>
                    <input data-quote-search class="rounded-md border px-3 py-2" type="search" placeholder="Nom ou référence" autocomplete="off">
                </label>
            <?php endif; ?>
            <div data-quote-results class="grid gap-2"></div>
            <p data-quote-status class="min-h-5 text-sm" role="status" aria-live="polite"></p>
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-md border px-4 py-2" onclick="this.closest('dialog').close()">Fermer</button>
                <button type="submit" class="rounded-md bg-[#ff7a1a] px-4 py-2 font-bold">Envoyer la demande</button>
            </div>
        </form>
    </dialog>
</div>
