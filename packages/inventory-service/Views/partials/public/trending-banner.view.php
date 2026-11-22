<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$products = array_slice(array_values(is_array($trending_products ?? null) ? $trending_products : []), 0, 4);
$count = count($products);
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$focusWindow = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#10302c] dark:focus-visible:ring-offset-[#143632]';
$formatPrice = static function (string $price, string $currency): string {
    $parts = explode('.', $price, 2);
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $parts[0]);
    $fraction = rtrim($parts[1] ?? '', '0');

    return $integer . ($fraction !== '' ? ',' . $fraction : '') . ' ' . $currency;
};
$shortDescription = static function (string $description): string {
    $description = trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)));
    $length = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
    if ($length <= 180) {
        return $description;
    }

    $short = function_exists('mb_substr')
        ? mb_substr($description, 0, 177, 'UTF-8')
        : substr($description, 0, 177);

    return rtrim($short) . '…';
};

// Données préparées une fois : utilisées par les diapositives et par la rangée de sélection.
$items = [];
foreach ($products as $product) {
    $image = null;
    foreach ((is_array($product['media'] ?? null) ? $product['media'] : []) as $media) {
        if (($media['media_type'] ?? '') === 'image' && !empty($media['url'])) {
            $image = $media;
            break;
        }
    }
    $name = (string) ($product['name'] ?? '');
    $hasPrice = array_key_exists('price', $product) && $product['price'] !== null && $product['price'] !== '';
    $discount = is_array($product['discount_result'] ?? null) ? $product['discount_result'] : null;
    $hasDiscount = $hasPrice && !empty($discount['applied_discounts']);
    $price = $hasDiscount ? (string) $discount['final_price'] : (string) ($product['price'] ?? '');
    $currency = strtoupper((string) ($product['currency'] ?? app()->env('APP_CURRENCY', 'MAD')));
    $percent = 0;
    if ($hasDiscount && is_numeric($product['price']) && (float) $product['price'] > 0 && is_numeric($price)) {
        $percent = (int) round((1 - ((float) $price / (float) $product['price'])) * 100);
    }
    $items[] = [
        'product' => $product,
        'image' => $image,
        'name' => $name,
        'description' => $shortDescription((string) ($product['description'] ?? '')),
        'hasPrice' => $hasPrice,
        'hasDiscount' => $hasDiscount,
        'percent' => $percent,
        'priceLabel' => $hasPrice ? $formatPrice($price, $currency) : '',
        'oldPriceLabel' => $hasDiscount ? $formatPrice((string) $product['price'], $currency) : '',
        'isService' => ($product['kind'] ?? '') === 'service',
        'imageAlt' => (string) (($image['caption'] ?? '') ?: $name),
    ];
}
?>
<?php if ($count > 0): ?>
    <?php if ($count > 1): ?>
        @cdn('scripts', '/dashboard/service-assets/inventory-service/trending-carousel.js')
    <?php endif; ?>
    <style>
        [data-trending-carousel] [data-carousel-track] { display: grid; }
        [data-trending-carousel] [data-trending-slide] {
            grid-area: 1 / 1;
            position: absolute;
            inset: 0;
            visibility: hidden;
            opacity: 0;
            pointer-events: none;
            transform: translateX(1rem) scale(.99);
            transition: opacity .65s ease, transform .65s cubic-bezier(.2, .75, .25, 1), visibility .65s;
        }
        [data-trending-carousel] [data-trending-slide][data-active="true"] {
            position: relative;
            visibility: visible;
            opacity: 1;
            pointer-events: auto;
            transform: translateX(0) scale(1);
        }
        [data-trending-carousel] [data-trending-slide][data-leaving="true"] {
            visibility: visible;
            transform: translateX(-1rem) scale(.99);
        }
        [data-trending-carousel] [data-active="true"] .trending-banner-image {
            transform: scale(1.035);
            transition: transform 7s ease-out;
        }
        [data-trending-carousel] [data-active="true"] .trending-banner-copy {
            animation: trending-copy-in .48s both;
        }
        [data-trending-carousel] [data-active="true"] h3.trending-banner-copy { animation-delay: .06s; }
        [data-trending-carousel] [data-active="true"] p.trending-banner-copy { animation-delay: .12s; }
        [data-trending-carousel] [data-active="true"] div.trending-banner-copy { animation-delay: .18s; }
        @keyframes trending-copy-in {
            from { opacity: 0; transform: translateY(.65rem); }
            to { opacity: 1; transform: translateY(0); }
        }
        [data-trending-carousel] [data-carousel-indicator][aria-pressed="true"] {
            background: #ff7a1a;
            color: #10302c;
            transform: translateY(-2px);
        }
        @media (prefers-reduced-motion: reduce) {
            [data-trending-carousel] [data-trending-slide],
            [data-trending-carousel] .trending-banner-image {
                transition: none;
            }
            [data-trending-carousel] [data-active="true"] .trending-banner-copy { animation: none; }
        }
    </style>
    <section class="shelf mx-auto mt-8 max-w-7xl text-[#10302c] dark:text-[#cfe8dc] sm:mt-16" data-trending-carousel role="region" aria-labelledby="trending-banner-heading" aria-roledescription="carrousel">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-4 sm:mb-6">
            <div>
                <h2 id="trending-banner-heading" class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl">En ce moment, en tendance</h2>
                <p class="mt-2 hidden max-w-xl text-base leading-7 sm:block">La sélection du moment : les articles que la boutique met en avant cette semaine.</p>
            </div>
            <?php if ($count > 1): ?>
                <div class="flex items-center gap-2" data-carousel-controls hidden>
                    <button type="button" data-carousel-previous aria-label="Diapositive précédente" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md bg-white text-[#10302c] shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] <?= $focus ?>">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button type="button" data-carousel-next aria-label="Diapositive suivante" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md bg-white text-[#10302c] shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] <?= $focus ?>">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
                    </button>
                    <button type="button" data-carousel-toggle aria-label="Mettre en pause le carrousel" aria-pressed="false"
                        class="inline-flex min-h-11 items-center justify-center rounded-md bg-white px-4 text-sm font-bold text-[#10302c] shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] <?= $focus ?>">Pause</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="relative" data-carousel-track>
            <?php foreach ($items as $index => $item): ?>
                <?php $product = $item['product']; $image = $item['image']; ?>
                <article data-trending-slide data-slide-index="<?= $index ?>" data-active="<?= $index === 0 ? 'true' : 'false' ?>"
                    role="group" aria-roledescription="diapositive" aria-label="<?= $index + 1 ?> sur <?= $count ?>"
                    aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>" <?= $index === 0 ? '' : 'hidden inert' ?>
                    class="grid overflow-hidden rounded-md bg-[#10302c] text-[#cfe8dc] shadow-[0_8px_0_#0c1c1a] dark:bg-[#143632] dark:shadow-[0_8px_0_#3a5f59] md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                    <div class="order-2 p-4 sm:p-8 md:order-1">
                        <div class="relative h-72 overflow-hidden rounded-t-xl border-b-[14px] border-[#0c1c1a] bg-[#e4f3eb] dark:border-[#3a5f59] sm:h-96">
                            <?php if ($image !== null): ?>
                                <img src="<?= $escape($image['url']) ?>" alt="<?= $escape($item['imageAlt']) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" <?= $index === 0 ? 'fetchpriority="high"' : '' ?> class="trending-banner-image absolute inset-0 h-full w-full object-contain p-6 sm:p-8">
                            <?php else: ?>
                                <div class="absolute inset-0 flex items-center justify-center text-[#10302c]/40">
                                    <svg viewBox="0 0 24 24" class="h-20 w-20" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 4.3 9 5.2M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4ZM3.3 7l8.7 5 8.7-5M12 22V12"/></svg>
                                </div>
                            <?php endif; ?>
                            <span class="absolute left-4 top-4 z-10 rounded-md bg-[#ff7a1a] px-3 py-1.5 text-sm font-extrabold text-[#10302c] shadow-[0_3px_0_#10302c] [font-stretch:80%]">Tendance</span>
                            <?php if ($item['percent'] > 0): ?>
                                <span class="absolute right-4 top-4 z-10 flex h-16 w-16 rotate-6 items-center justify-center rounded-full bg-[#ff7a1a] text-lg font-extrabold tabular-nums text-[#10302c] ring-2 ring-[#10302c]">−<?= $item['percent'] ?> %</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="order-1 flex min-w-0 flex-col justify-center px-5 pb-5 pt-5 sm:px-8 sm:pb-8 md:order-2 md:py-8 lg:pr-12">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-md bg-[#cfe8dc] px-2.5 py-1 text-xs font-bold text-[#10302c]"><?= $item['isService'] ? 'Service' : 'Produit' ?></span>
                            <?php if (!empty($product['brand_name'])): ?>
                                <span class="text-sm font-bold"><?= $escape($product['brand_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="trending-banner-copy mt-2 break-words text-3xl font-extrabold leading-[0.95] [font-stretch:75%] sm:mt-4 sm:text-5xl"><?= $escape($item['name']) ?></h3>
                        <?php if ($item['description'] !== ''): ?>
                            <p class="trending-banner-copy mt-2 max-w-xl break-words text-base leading-7 sm:mt-4"><?= $escape($item['description']) ?></p>
                        <?php endif; ?>
                        <div class="trending-banner-copy mt-4 flex flex-wrap items-center gap-4 sm:mt-7">
                            <?php if ($item['hasPrice']): ?>
                                <p class="flex flex-wrap items-baseline gap-3">
                                    <span class="rounded-md bg-[#ff7a1a] px-4 py-2 text-3xl font-extrabold leading-none tabular-nums text-[#10302c] shadow-[0_4px_0_#0c1c1a] [font-stretch:80%]"><?= $escape($item['priceLabel']) ?></span>
                                    <?php if ($item['hasDiscount']): ?>
                                        <span class="text-sm tabular-nums line-through opacity-75"><?= $escape($item['oldPriceLabel']) ?></span>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <a class="inline-flex min-h-11 items-center justify-center rounded-md bg-[#cfe8dc] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#0c1c1a] transition hover:-translate-y-0.5 <?= $focusWindow ?>" href="/products/<?= $escape(rawurlencode((string) ($product['slug'] ?? ''))) ?>">Découvrir la fiche <span class="ml-2" aria-hidden="true">→</span></a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($count > 1): ?>
            <!-- Rangée de sélection : toutes les tendances visibles d'un coup -->
            <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4" role="group" aria-label="Choisir une tendance" data-carousel-controls hidden>
                <?php foreach ($items as $index => $item): ?>
                    <button type="button" data-carousel-indicator="<?= $index ?>" aria-label="Afficher <?= $escape($item['name'] !== '' ? $item['name'] : 'le produit') ?>"
                        aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                        class="flex min-h-11 min-w-0 items-center gap-3 rounded-md bg-white p-2 text-left text-[#10302c] shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] <?= $focus ?>">
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded bg-[#e4f3eb]" aria-hidden="true">
                            <?php if ($item['image'] !== null): ?>
                                <img src="<?= $escape($item['image']['url']) ?>" alt="" loading="lazy" class="h-full w-full object-contain p-1">
                            <?php endif; ?>
                        </span>
                        <span class="min-w-0">
                            <span class="block break-words text-sm font-extrabold leading-tight line-clamp-2"><?= $escape($item['name']) ?></span>
                            <?php if ($item['hasPrice']): ?>
                                <span class="mt-0.5 block text-xs font-bold tabular-nums"><?= $escape($item['priceLabel']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="sr-only">Diapositive <?= $index + 1 ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="sr-only" data-carousel-announcement aria-live="polite" aria-atomic="true"></p>
        <?php endif; ?>
    </section>
<?php endif; ?>