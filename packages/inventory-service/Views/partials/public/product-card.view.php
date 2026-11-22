<?php
/** @var array $product */
/** @var \Closure $escape */
$cardImage = null;
foreach ((is_array($product['media'] ?? null) ? $product['media'] : []) as $media) {
    if (($media['media_type'] ?? '') === 'image' && !empty($media['url'])) {
        $cardImage = $media;
        break;
    }
}
$cardHasPrice = array_key_exists('price', $product) && $product['price'] !== null && $product['price'] !== '';
$cardCurrency = strtoupper((string) ($product['currency'] ?? app()->env('APP_CURRENCY', 'MAD')));
$cardDiscount = is_array($product['discount_result'] ?? null) ? $product['discount_result'] : null;
$cardHasDiscount = !empty($cardDiscount['applied_discounts']);
$cardFinal = $cardHasDiscount ? (string) $cardDiscount['final_price'] : (string) ($product['price'] ?? '');
$cardKind = ($product['kind'] ?? '') === 'service' ? 'Service' : 'Produit';
$cardDescription = trim(strip_tags((string) ($product['description'] ?? '')));
$showPreview = !empty($show_preview);
$cardModalId = 'product-modal-' . (int) ($product['id'] ?? 0);
$cardFormat = static function (string $price, string $currency): string {
    $parts = explode('.', $price, 2);
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $parts[0]);
    $fraction = rtrim($parts[1] ?? '', '0');

    return $integer . ($fraction !== '' ? ',' . $fraction : '') . ' ' . $currency;
};
$cardHref = '/products/' . rawurlencode((string) ($product['slug'] ?? ''));
$cardName = (string) ($product['name'] ?? '');
$cardAlt = (string) (($cardImage['caption'] ?? '') ?: $cardName);
$cardSaving = (string) ($cardDiscount['total_saving_percentage'] ?? '');
$eyeIcon = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
$boxIcon = '<svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 4.3 9 5.2M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m3.3 7 8.7 5 8.7-5M12 22v-10"/></svg>';
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-[#143632]';
$btnDark = 'bg-[#10302c] text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]';
?>
<?php /* Carte « Étagère » : étiquette de prix orange, pastille de remise cerclée, posée sur une planche par la vue. */ ?>
<article data-product-card class="group relative flex aspect-square snap-start flex-col rounded-md bg-white p-4 text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-1 dark:bg-[#143632] dark:text-[#cfe8dc] dark:shadow-[0_4px_0_#3a5f59]">
    <div class="<?= $cardHasDiscount ? 'pr-14' : '' ?>">
        <p class="flex items-center gap-1.5 truncate text-xs">
            <span class="font-extrabold"><?= $cardKind ?></span>
            <?php if (!empty($product['brand_name'])): ?>
                <span aria-hidden="true">/</span><span class="truncate"><?= $escape($product['brand_name']) ?></span>
            <?php endif; ?>
        </p>
        <h3 class="mt-1 line-clamp-2 text-lg font-extrabold leading-[0.95] [font-stretch:75%]">
            <a href="<?= $escape($cardHref) ?>" class="rounded after:absolute after:inset-0 after:rounded-md <?= $focus ?>"><?= $escape($cardName) ?></a>
        </h3>
    </div>

    <div class="min-h-0 flex-1 py-2">
        <?php if ($cardImage !== null): ?>
            <img src="<?= $escape($cardImage['url']) ?>" alt="<?= $escape($cardAlt) ?>" loading="lazy" class="h-full w-full object-contain">
        <?php else: ?>
            <div class="flex h-full w-full items-center justify-center opacity-40"><?= $boxIcon ?></div>
        <?php endif; ?>
    </div>

    <?php if ($cardHasDiscount): ?>
        <span class="absolute right-3 top-3 flex h-14 w-14 rotate-6 items-center justify-center rounded-full bg-[#ff7a1a] text-sm font-extrabold leading-none tabular-nums text-[#10302c] ring-2 ring-[#10302c] dark:ring-[#cfe8dc]">−<?= $escape($cardSaving) ?>%</span>
    <?php endif; ?>

    <div class="flex items-end justify-between gap-2">
        <?php if ($cardHasPrice): ?>
            <p class="flex flex-col items-start gap-1 leading-none">
                <span class="rounded bg-[#ff7a1a] px-2 py-1 text-sm font-extrabold tabular-nums text-[#10302c]"><?= $escape($cardFormat($cardFinal, $cardCurrency)) ?></span>
                <?php if ($cardHasDiscount): ?>
                    <span class="text-xs tabular-nums line-through opacity-75"><?= $escape($cardFormat((string) $product['price'], $cardCurrency)) ?></span>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="text-xs font-bold">Prix sur demande</p>
        <?php endif; ?>
        <?php if ($showPreview): ?>
            <button type="button" data-product-modal-open aria-haspopup="dialog" aria-controls="<?= $escape($cardModalId) ?>" aria-label="Aperçu de <?= $escape($cardName) ?>"
                class="relative z-10 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md <?= $btnDark ?> transition hover:bg-[#ff7a1a] hover:text-[#10302c] dark:hover:bg-[#ff7a1a] dark:hover:text-[#10302c] <?= $focus ?>"><?= $eyeIcon ?></button>
        <?php endif; ?>
    </div>

    <?php if ($showPreview): ?>
        <dialog id="<?= $escape($cardModalId) ?>" data-product-modal aria-labelledby="<?= $escape($cardModalId) ?>-title"
            class="m-auto box-border max-h-[calc(100dvh-3rem)] w-[calc(100vw-2rem)] max-w-3xl overflow-y-auto overflow-x-hidden overscroll-contain rounded-md bg-[#cfe8dc] p-0 text-[#10302c] shadow-[0_8px_0_#10302c] backdrop:bg-[#0c1c1a]/70 dark:bg-[#143632] dark:text-[#cfe8dc] dark:shadow-[0_8px_0_#3a5f59]">
            <div class="grid min-w-0 sm:grid-cols-2">
                <div class="relative min-h-56 min-w-0 border-b-[14px] border-[#10302c] bg-[#e4f3eb] dark:border-[#3a5f59] dark:bg-[#0c1c1a] sm:min-h-full">
                    <?php if ($cardImage !== null): ?>
                        <img src="<?= $escape($cardImage['url']) ?>" alt="<?= $escape($cardAlt) ?>" class="absolute inset-0 h-full w-full object-contain p-6">
                    <?php else: ?>
                        <span class="absolute inset-0 flex items-center justify-center text-sm font-bold">Image non disponible</span>
                    <?php endif; ?>
                </div>
                <div class="flex min-w-0 flex-col break-words">
                <div class="relative min-w-0 flex-1 p-6 sm:p-8">
                    <button type="button" data-product-modal-close autofocus aria-label="Fermer"
                        class="absolute right-3 top-3 inline-flex h-10 w-10 items-center justify-center rounded-md bg-white text-xl font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] <?= $focus ?>">&times;</button>
                    <p class="inline-flex rounded-md px-2.5 py-1 text-xs font-bold <?= $btnDark ?>"><?= $cardKind ?></p>
                    <h2 id="<?= $escape($cardModalId) ?>-title" class="mt-3 break-words pr-12 text-3xl font-extrabold leading-[0.95] [font-stretch:75%] sm:text-4xl"><?= $escape($cardName) ?></h2>
                    <?php if ($cardHasPrice): ?>
                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <span class="rounded-md bg-[#ff7a1a] px-3 py-1.5 text-2xl font-extrabold tabular-nums leading-none text-[#10302c] shadow-[0_3px_0_#10302c] dark:shadow-[0_3px_0_#3a5f59]"><?= $escape($cardFormat($cardFinal, $cardCurrency)) ?></span>
                            <?php if ($cardHasDiscount): ?>
                                <span class="text-sm tabular-nums line-through opacity-75"><?= $escape($cardFormat((string) $product['price'], $cardCurrency)) ?></span>
                                <span class="rounded-md px-2.5 py-1 text-xs font-extrabold <?= $btnDark ?>">−<?= $escape($cardSaving) ?> %</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="mt-5 text-2xl font-extrabold [font-stretch:80%]">Prix sur demande</p>
                    <?php endif; ?>
                    <?php if ($cardDescription !== ''): ?>
                        <p class="mt-5 whitespace-pre-line text-sm leading-6"><?= $escape($cardDescription) ?></p>
                    <?php endif; ?>
                    <a href="<?= $escape($cardHref) ?>" class="mt-6 inline-flex min-h-11 items-center rounded-md bg-white px-5 text-sm font-bold shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 hover:bg-[#ff7a1a] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] dark:hover:bg-[#ff7a1a] <?= $focus ?>">Voir la fiche complète</a>
                </div>
                <?php if (!empty($product['cart_enabled']) && $cardHasPrice && ($product['kind'] ?? '') !== 'service'): ?>
                    <?php /* Ajout au panier épinglé en bas de la modale : toujours visible, même si la description est longue. */ ?>
                    <div class="sticky bottom-0 z-10 border-t-2 border-dashed border-[#10302c]/40 bg-[#cfe8dc] px-6 pb-5 pt-1 dark:border-[#cfe8dc]/40 dark:bg-[#143632] sm:px-8 [&_[data-sales-product]]:mt-3">
                        @include('partials.public.product-cart', [
                        'product' => $product,
                        'hasPrice' => $cardHasPrice,
                        'isService' => false,
                        'discountFinalPrice' => $cardFinal,
                        'cart_enabled' => !empty($product['cart_enabled']),
                        'escape' => $escape,
                        ])
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </dialog>
    <?php endif; ?>
</article>