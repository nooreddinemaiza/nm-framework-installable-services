@extends('layouts.app')
<?php
$seoBaseUrl = rtrim((string) app()->environment()->get('APP_URL', ''), '/');
$seoCanonicalUrl = $seoBaseUrl . '/products/' . rawurlencode((string) $product['slug']);
$relatedProducts = is_array($related_products ?? null) ? $related_products : ['category' => [], 'brand' => []];
$seoCanonicalUrl = filter_var($seoCanonicalUrl, FILTER_VALIDATE_URL) ? $seoCanonicalUrl : '';
$seoPlainDescription = trim((string) preg_replace(
    '/\s+/u',
    ' ',
    html_entity_decode(strip_tags((string) ($product['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')
));
$seoFallbackDescription = ($product['kind'] ?? '') === 'service' ? 'Découvrez le service ' : 'Découvrez le produit ';
$seoDescriptionSource = $seoPlainDescription !== ''
    ? $seoPlainDescription
    : $seoFallbackDescription . (string) $product['name'] . '. Consultez ses informations, sa référence et ses caractéristiques.';
$seoKeywordSets = [
    $product['seo_tags'] ?? '',
    $product['category_seo_tags'] ?? '',
    $product['brand_seo_tags'] ?? '',
];
$seoKeywords = [];
foreach ($seoKeywordSets as $seoTagSet) {
    array_push($seoKeywords, ...\App\Catalog\SeoTags::parse($seoTagSet));
}
$seoKeywords = implode(', ', array_unique($seoKeywords));
$seoDescriptionLength = function_exists('iconv_strlen')
    ? iconv_strlen($seoDescriptionSource, 'UTF-8')
    : strlen($seoDescriptionSource);
$seoDescriptionLength = $seoDescriptionLength === false ? strlen($seoDescriptionSource) : $seoDescriptionLength;
$seoDescriptionTruncated = $seoDescriptionLength > 160;
$seoDescription = function_exists('iconv_substr')
    ? (iconv_substr($seoDescriptionSource, 0, 160, 'UTF-8') ?: '')
    : substr($seoDescriptionSource, 0, 160);
if ($seoDescriptionTruncated) {
    $seoDescription = rtrim($seoDescription, " \t\n\r\0\x0B.,;:-") . '…';
}
$seoImageUrl = '';
foreach (($product_media ?? []) as $seoMedia) {
    if (($seoMedia['media_type'] ?? '') !== 'image') {
        continue;
    }
    $seoImageUrl = (string) ($seoMedia['url'] ?? '');
    if ($seoImageUrl !== '' && !preg_match('#^https?://#i', $seoImageUrl)) {
        $seoImageUrl = $seoBaseUrl . '/' . ltrim($seoImageUrl, '/');
    }
    break;
}
$seoSchema = [
    '@context' => 'https://schema.org',
    '@type' => ($product['kind'] ?? '') === 'service' ? 'Service' : 'Product',
    'name' => (string) $product['name'],
    'description' => $seoDescription,
];
if (!empty($product['sku'])) {
    $seoSchema['sku'] = (string) $product['sku'];
}
if (!empty($product['category_name'])) {
    $seoSchema['category'] = (string) $product['category_name'];
}
if (!empty($product['brand_name'])) {
    $seoSchema['brand'] = ['@type' => 'Brand', 'name' => (string) $product['brand_name']];
}
if (!empty($product['manufacturer'])) {
    $seoSchema['manufacturer'] = ['@type' => 'Organization', 'name' => (string) $product['manufacturer']];
}
if ($seoKeywords !== '') {
    $seoSchema['keywords'] = $seoKeywords;
}
if ($seoCanonicalUrl !== '') {
    $seoSchema['url'] = $seoCanonicalUrl;
} else {
    unset($seoSchema['url']);
}
$seoHasImage = $seoImageUrl !== '' && filter_var($seoImageUrl, FILTER_VALIDATE_URL) !== false;
if ($seoHasImage) {
    $seoSchema['image'] = [$seoImageUrl];
}
$publicDiscount = $discount_result ?? null;
$publicAppliedDiscounts = is_array($publicDiscount['applied_discounts'] ?? null)
    ? $publicDiscount['applied_discounts']
    : [];
$hasPublicDiscount = $publicAppliedDiscounts !== [];
if (isset($product['price']) && $product['price'] !== null) {
    $seoSchema['offers'] = [
        '@type' => 'Offer',
        'price' => $hasPublicDiscount ? (string) $publicDiscount['final_price'] : (string) $product['price'],
        'priceCurrency' => strtoupper((string) ($product['currency'] ?? app()->env('APP_CURRENCY', 'MAD'))),
    ];
}
$seoJson = json_encode($seoSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
@title($product['name'] . ' | ' . app()->config('app.name'))
@description($seoDescription)
@if($seoKeywords !== '')
@meta('keywords', $seoKeywords)
@endif
@if($seoCanonicalUrl !== '')
@canonical($seoCanonicalUrl)
@endif
@meta('robots', 'index,follow')
@og('type', ($product['kind'] ?? '') === 'service' ? 'website' : 'product')
@og('title', (string) $product['name'])
@og('description', $seoDescription)
@og('site_name', (string) app()->config('app.name'))
@twitter('card', $seoHasImage ? 'summary_large_image' : 'summary')
@twitter('title', (string) $product['name'])
@twitter('description', $seoDescription)
@meta('csrf_token', $csrf_token)
@if($seoCanonicalUrl !== '')
@og('url', $seoCanonicalUrl)
@endif
@if($seoHasImage)
@og('image', $seoImageUrl)
@twitter('image', $seoImageUrl)
@endif
@push('head')
<script type="application/ld+json"><?= $seoJson ?></script>
@endpush
@cdn('scripts', '/dashboard/service-assets/inventory-service/product-showcase.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/api-client.js')
<?php if (($quote_requests_enabled ?? false) === true): ?>
@cdn('scripts', '/dashboard/service-assets/quotes/quote-request.js')
<?php endif; ?>

@section('content')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$description = trim((string) ($product['description'] ?? ''));
$media = array_values($product_media ?? []);
$isService = ($product['kind'] ?? '') === 'service';
$kindLabel = $isService ? 'Service' : 'Produit';
$hasPrice = array_key_exists('price', $product) && $product['price'] !== null;
$currency = strtoupper((string) ($product['currency'] ?? app()->env('APP_CURRENCY', 'MAD')));
$discountFinalPrice = $hasPrice && $hasPublicDiscount
    ? (string) $publicDiscount['final_price']
    : (string) ($product['price'] ?? '');
$formatPublicPrice = static function (string $price, string $priceCurrency): string {
    $parts = explode('.', $price, 2);
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $parts[0]);
    $fraction = rtrim($parts[1] ?? '', '0');

    return $integer . ($fraction !== '' ? ',' . $fraction : '') . ' ' . $priceCurrency;
};
$formattedPrice = $hasPrice ? $formatPublicPrice((string) $product['price'], $currency) : '';
$finalPriceLabel = $hasPrice ? $formatPublicPrice($discountFinalPrice, $currency) : '';
$savingLabel = $hasPrice && $hasPublicDiscount
    ? $formatPublicPrice((string) $publicDiscount['total_saving'], $currency)
    : '';
$updatedAt = !empty($product['updated_at']) ? strtotime((string) $product['updated_at']) : false;
$shareUrl = '/products/' . rawurlencode((string) $product['slug']);

$details = array_filter([
    'Référence' => $product['sku'] ?? '',
    'Catégorie' => $product['category_name'] ?? '',
    'Marque' => $product['brand_name'] ?? '',
    'Fabricant' => $product['manufacturer'] ?? '',
], static fn($value) => $value !== null && $value !== '');
$productTags = \App\Catalog\SeoTags::parse($product['seo_tags'] ?? '');

$discountLines = [];
foreach ($publicAppliedDiscounts as $appliedDiscount) {
    $targetLabel = match ($appliedDiscount['target_type'] ?? '') {
        'product' => 'Produit',
        'category' => 'Catégorie',
        'brand' => 'Marque',
        default => 'Promotion',
    };
    $targetName = match ($appliedDiscount['target_type'] ?? '') {
        'product' => (string) $product['name'],
        'category' => (string) ($product['category_name'] ?? ''),
        'brand' => (string) ($product['brand_name'] ?? ''),
        default => '',
    };
    $valueLabel = ($appliedDiscount['discount_type'] ?? '') === 'percentage'
        ? (string) $appliedDiscount['value'] . ' %'
        : (string) $appliedDiscount['value'] . ' ' . (string) ($appliedDiscount['currency'] ?? $currency);
    $discountLines[] = 'Remise ' . $targetLabel . ($targetName !== '' ? ' (' . $targetName . ')' : '') . ' : ' . $valueLabel;
}

$relatedSections = [
    ['key' => 'category', 'title' => 'Dans la même catégorie', 'link_label' => 'Voir la catégorie', 'slug' => $product['category_slug'] ?? null, 'url' => '/categories/'],
    ['key' => 'brand', 'title' => 'De la même marque', 'link_label' => 'Voir la marque', 'slug' => $product['brand_slug'] ?? null, 'url' => '/brands/'],
];

$shareIcon = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.7 10.7 6.6-4.4m-6.6 7 6.6 4.4"/></svg>';
$linkIcon = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.2 10.8a4 4 0 0 1 0 5.6l-2.4 2.4a4 4 0 0 1-5.6-5.6l1.2-1.2m9.2-3.2 1.2-1.2a4 4 0 0 0-5.6-5.6L9.6 5.2a4 4 0 0 0 0 5.6"/></svg>';
$playIcon = '<svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z"/></svg>';
$chevronIcon = '<svg class="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>';

/*
 * Version « Étagère »
 * Visuel posé sur une planche, carte d'achat suspendue comme une étiquette. Menthe + orange, vert sombre.
 */
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$link = 'rounded font-bold underline underline-offset-4 ' . $focus;
$h2 = 'text-2xl font-extrabold [font-stretch:80%] sm:text-3xl';
$panel = 'rounded-md bg-white p-6 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-8';
$btnPrimary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:shadow-[0_4px_0_#3a5f59] ' . $focus;
$btnSoft = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-white px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] ' . $focus;
$pricePlate = 'inline-block rounded-md bg-[#ff7a1a] px-4 py-2 text-4xl font-extrabold tabular-nums leading-none text-[#10302c] shadow-[0_4px_0_#10302c] [font-stretch:80%] dark:shadow-[0_4px_0_#3a5f59]';

$renderMedia = static function (array $item, string $fallbackAlt, bool $eager) use ($escape, $playIcon): void {
    $type = $item['media_type'] ?? 'image';
    $frame = 'aspect-square sm:aspect-[5/4]';
    ?>
    <figure>
        <?php if ($type === 'image'): ?>
            <div class="<?= $frame ?> flex items-center justify-center">
                <img src="<?= $escape($item['url']) ?>" alt="<?= $escape($item['caption'] ?: $fallbackAlt) ?>"
                    loading="<?= $eager ? 'eager' : 'lazy' ?>" class="h-full w-full object-contain p-6 sm:p-12">
            </div>
        <?php elseif (!empty($item['external_url'])): ?>
            <a href="<?= $escape($item['url']) ?>" target="_blank" rel="noopener noreferrer"
                class="<?= $frame ?> flex flex-col items-center justify-center gap-3 bg-[#ff7a1a] text-[#10302c] transition hover:brightness-95 focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-[#10302c]">
                <span
                    class="flex h-16 w-16 items-center justify-center rounded-full bg-[#10302c] text-[#ff7a1a]"><?= $playIcon ?></span>
                <span class="text-lg font-extrabold [font-stretch:80%]">Regarder la vidéo</span>
            </a>
        <?php else: ?>
            <video src="<?= $escape($item['url']) ?>" controls preload="metadata"
                class="<?= $frame ?> w-full bg-[#10302c] object-contain"></video>
        <?php endif; ?>
        <?php if (!empty($item['caption'])): ?>
            <figcaption class="sr-only"><?= $escape($item['caption']) ?></figcaption>
        <?php endif; ?>
    </figure>
    <?php
};
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => ''])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <?php
        $breadcrumbs = [];
        if (!empty($product['category_slug'])) {
            $breadcrumbs[] = [
                'label' => (string) $product['category_name'],
                'url' => '/categories/' . (string) $product['category_slug'],
            ];
        }
        $breadcrumbs[] = [
            'label' => (string) $product['name'],
        ];
        ?>
        <x-breadcrumb separator="/" :homeLabel="true" :items="$breadcrumbs ?? []"
            :home="['label' => 'Accueil', 'url' => '/']" />

        <section class="mt-6 grid items-start gap-8 lg:grid-cols-12 lg:gap-10">

            <!-- Visuel posé sur la planche -->
            <div class="min-w-0 lg:col-span-7">
                <div
                    class="overflow-hidden rounded-t-xl border-b-[14px] border-[#10302c] bg-[#e4f3eb] dark:border-[#3a5f59] dark:bg-[#143632]">
                    <?php if ($media !== []): ?>
                        <?php foreach ($media as $index => $item): ?>
                            <div data-product-slide="<?= $index ?>" <?= $index === 0 ? '' : 'hidden' ?>
                                aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                                <?php $renderMedia($item, (string) $product['name'], $index === 0); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div
                            class="flex aspect-square flex-col items-center justify-center px-8 text-center sm:aspect-[5/4]">
                            <span
                                class="flex h-16 w-16 items-center justify-center rounded-md bg-white text-[#10302c] shadow-[0_4px_0_#10302c] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59]">
                                <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="m7.5 4.3 9 5.2M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m3.3 7 8.7 5 8.7-5M12 22v-10" />
                                </svg>
                            </span>
                            <p class="mt-4 text-2xl font-extrabold [font-stretch:80%]">Pas encore de visuel</p>
                            <p class="mt-1 text-sm">Toutes les informations sont sur cette page.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($media) > 1): ?>
                    <div class="mt-5 flex gap-3 overflow-x-auto p-1" role="group" aria-label="Choisir un visuel">
                        <?php foreach ($media as $index => $item): ?>
                            <button type="button" data-product-thumb="<?= $index ?>"
                                aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                                aria-label="Afficher le visuel <?= $index + 1 ?><?= !empty($item['caption']) ? ' : ' . $escape($item['caption']) : '' ?>"
                                class="h-16 w-16 shrink-0 overflow-hidden rounded-md bg-white opacity-60 shadow-[0_3px_0_#10302c] transition hover:opacity-100 aria-[pressed=true]:-translate-y-0.5 aria-[pressed=true]:opacity-100 aria-[pressed=true]:ring-2 aria-[pressed=true]:ring-[#ff7a1a] dark:bg-[#143632] dark:shadow-[0_3px_0_#3a5f59] <?= $focus ?>">
                                <?php if (($item['media_type'] ?? 'image') === 'image'): ?>
                                    <img src="<?= $escape($item['url']) ?>" alt="" loading="lazy"
                                        class="h-full w-full object-cover">
                                <?php else: ?>
                                    <span
                                        class="flex h-full w-full items-center justify-center bg-[#ff7a1a] text-[#10302c]"><?= $playIcon ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Étiquette d'achat suspendue -->
            <div class="lg:sticky lg:top-6 lg:col-span-5">
                <span class="mx-auto block h-10 w-px bg-[#10302c] dark:bg-[#8fb8ad]" aria-hidden="true"></span>
                <div
                    class="<?= $panel ?> relative pt-10 before:absolute before:left-1/2 before:top-3 before:h-3.5 before:w-3.5 before:-translate-x-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] dark:before:ring-[#8fb8ad]">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                        <span
                            class="rounded-md bg-[#10302c] px-2.5 py-1 text-xs font-bold text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]"><?= $kindLabel ?></span>
                        <?php if (!empty($product['brand_name'])): ?>
                            <?php if (!empty($product['brand_slug'])): ?>
                                <a class="<?= $link ?>"
                                    href="/brands/<?= $escape(rawurlencode((string) $product['brand_slug'])) ?>"><?= $escape($product['brand_name']) ?></a>
                            <?php else: ?>
                                <span class="font-bold"><?= $escape($product['brand_name']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <h1
                        class="mt-4 break-words text-balance text-4xl font-extrabold leading-[0.95] [font-stretch:75%] sm:text-5xl">
                        <?= $escape($product['name']) ?>
                    </h1>

                    <?php if (!empty($product['manufacturer']) || !empty($product['sku'])): ?>
                        <p class="mt-3 text-sm">
                            <?php if (!empty($product['manufacturer'])): ?>Par
                                <?= $escape($product['manufacturer']) ?>     <?php endif; ?>
                            <?php if (!empty($product['manufacturer']) && !empty($product['sku'])): ?> – <?php endif; ?>
                            <?php if (!empty($product['sku'])): ?>Réf. <span
                                    class="font-mono text-xs"><?= $escape($product['sku']) ?></span><?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <div class="mt-6">
                        <?php if ($hasPrice): ?>
                            <?php if ($hasPublicDiscount): ?>
                                <div class="flex flex-wrap items-center gap-3">
                                    <p class="<?= $pricePlate ?>"><?= $escape($finalPriceLabel) ?></p>
                                    <span
                                        class="rounded-md bg-[#10302c] px-2.5 py-1 text-sm font-extrabold tabular-nums text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]">−<?= $escape($publicDiscount['total_saving_percentage']) ?>
                                        %</span>
                                </div>
                                <p class="mt-4 text-sm tabular-nums">
                                    Au lieu de <s><?= $escape($formattedPrice) ?></s>
                                </p>
                                <p class="text-sm font-bold tabular-nums">Vous économisez <?= $escape($savingLabel) ?></p>
                                <details class="group mt-3 text-sm">
                                    <summary
                                        class="flex cursor-pointer list-none items-center gap-1 rounded font-bold underline underline-offset-4 <?= $focus ?>">
                                        Détail des remises <?= $chevronIcon ?>
                                    </summary>
                                    <ul class="mt-2 space-y-1">
                                        <?php foreach ($discountLines as $line): ?>
                                            <li><?= $escape($line) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php else: ?>
                                <p class="<?= $pricePlate ?>"><?= $escape($formattedPrice) ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-3xl font-extrabold [font-stretch:80%]">Prix sur demande</p>
                            <p class="mt-1 text-sm">Contactez-nous pour recevoir un devis.</p>
                        <?php endif; ?>

                        <p
                            class="mt-5 inline-flex items-center gap-2 rounded-md border-2 border-current px-3 py-1 text-xs font-bold">
                            <span class="h-2 w-2 rounded-full bg-[#ff7a1a]" aria-hidden="true"></span>
                            <?= $isService ? 'Service disponible' : 'Produit référencé' ?>
                        </p>
                    </div>

                    <div class="mt-6">
                        @include('partials.public.product-cart')
                        <?php if (($quote_requests_enabled ?? false) === true): ?>
                            @include('partials.public.quote-request', [
                                'quote_source' => 'product',
                                'quote_item_id' => (int) ($product['id'] ?? 0),
                                'quote_item_name' => (string) ($product['name'] ?? ''),
                            ])
                        <?php endif; ?>
                    </div>

                    <div
                        class="mt-6 flex flex-wrap gap-3 border-t-2 border-dashed border-[#10302c]/30 pt-6 dark:border-[#cfe8dc]/30">
                        <button type="button" data-product-share data-share-url="<?= $escape($shareUrl) ?>"
                            data-share-title="<?= $escape($product['name']) ?>" class="<?= $btnPrimary ?>">
                            <?= $shareIcon ?>Partager
                        </button>
                        <button type="button" data-product-share data-share-mode="copy"
                            data-share-url="<?= $escape($shareUrl) ?>"
                            data-share-title="<?= $escape($product['name']) ?>" class="<?= $btnSoft ?>">
                            <?= $linkIcon ?>Copier le lien
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Description + caractéristiques -->
        <section class="mt-14 grid gap-8 lg:grid-cols-3">
            <div class="<?= $panel ?> lg:col-span-2">
                <h2 class="<?= $h2 ?>">Description</h2>
                <?php if ($description !== ''): ?>
                    <div class="mt-4 max-w-prose break-words whitespace-pre-line text-base leading-7 [overflow-wrap:anywhere] [&>:first-child]:mt-0 [&>*+*]:mt-4 [&_h1]:text-[2rem] [&_h1]:font-black [&_h1]:leading-[1.15] [&_h2]:text-[1.65rem] [&_h2]:font-black [&_h2]:leading-[1.15] [&_h3]:text-[1.35rem] [&_h3]:font-black [&_h3]:leading-[1.15] [&_h4]:font-black [&_h4]:leading-[1.15] [&_h5]:font-black [&_h5]:leading-[1.15] [&_h6]:font-black [&_h6]:leading-[1.15] [&_ul]:list-disc [&_ul]:pl-[1.6em] [&_ol]:list-decimal [&_ol]:pl-[1.6em] [&_li+li]:mt-1 [&_blockquote]:border-l-[5px] [&_blockquote]:border-[#ff7a1a] [&_blockquote]:pl-4 [&_blockquote]:font-bold [&_a]:text-[#a63d00] [&_a]:underline [&_a]:underline-offset-4 dark:[&_a]:text-[#ff9a4f] [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-[#10302c] [&_pre]:p-4 [&_pre]:text-[#cfe8dc] [&_code]:rounded-sm [&_code]:bg-[#10302c]/10 [&_code]:px-1 [&_code]:py-0.5 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_table]:block [&_table]:max-w-full [&_table]:overflow-x-auto [&_table]:border-collapse [&_th]:border [&_th]:border-current [&_th]:p-2 [&_th]:text-left [&_th]:font-extrabold [&_td]:border [&_td]:border-current [&_td]:p-2 [&_td]:text-left [&_img]:h-auto [&_img]:max-w-full [&_figure]:max-w-full [&_hr]:border-0 [&_hr]:border-t-[3px] [&_hr]:border-current"><?= $description ?></div>
                <?php else: ?>
                    <p class="mt-4 rounded-md border-2 border-dashed border-current px-5 py-6 text-sm">
                        La description de ce <?= $isService ? 'service' : 'produit' ?> n’est pas encore renseignée.
                    </p>
                <?php endif; ?>
                <?php if ($productTags !== []): ?>
                    <ul class="mt-6 flex flex-wrap gap-x-3 gap-y-3" aria-label="Tags">
                        <?php foreach ($productTags as $tag): ?>
                            <li>
                                <a href="/tags/<?= $escape(rawurlencode(\App\Catalog\SeoTags::slug($tag))) ?>"
                                    class="relative inline-flex min-h-9 items-center rounded-md bg-[#cfe8dc] py-1.5 pl-9 pr-4 text-sm font-bold text-[#10302c] shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:shadow-[0_3px_0_#3a5f59] before:absolute before:left-3 before:top-1/2 before:h-2.5 before:w-2.5 before:-translate-y-1/2 before:rounded-full before:bg-white before:ring-2 before:ring-[#10302c] dark:before:bg-[#143632] <?= $focus ?>"><?= $escape($tag) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="<?= $panel ?>">
                <h2 class="<?= $h2 ?>">Caractéristiques</h2>
                <dl class="mt-4 divide-y-2 divide-dashed divide-[#10302c]/25 text-sm dark:divide-[#cfe8dc]/25">
                    <?php foreach ($details as $label => $value): ?>
                        <div class="py-3 first:pt-0">
                            <dt class="text-xs font-bold opacity-75"><?= $escape($label) ?></dt>
                            <dd class="mt-0.5 text-base font-bold">
                                <?php if ($label === 'Catégorie' && !empty($product['category_slug'])): ?>
                                    <a class="<?= $link ?>"
                                        href="/categories/<?= $escape(rawurlencode((string) $product['category_slug'])) ?>"><?= $escape($value) ?></a>
                                <?php elseif ($label === 'Marque' && !empty($product['brand_slug'])): ?>
                                    <a class="<?= $link ?>"
                                        href="/brands/<?= $escape(rawurlencode((string) $product['brand_slug'])) ?>"><?= $escape($value) ?></a>
                                <?php elseif ($label === 'Référence'): ?>
                                    <span class="font-mono text-sm"><?= $escape($value) ?></span>
                                <?php else: ?>
                                    <?= $escape($value) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($updatedAt !== false): ?>
                        <div class="py-3 last:pb-0">
                            <dt class="text-xs font-bold opacity-75">Dernière mise à jour</dt>
                            <dd class="mt-0.5 text-base font-bold tabular-nums"><?= $escape(date('d/m/Y', $updatedAt)) ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <!-- Articles liés, sur des étagères -->
        <?php foreach ($relatedSections as $section): ?>
            <?php if (empty($section['slug']))
                continue; ?>
            <?php $items = $relatedProducts[$section['key']] ?? []; ?>
            <section class="mt-16" aria-labelledby="related-<?= $escape($section['key']) ?>">
                <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
                    <h2 id="related-<?= $escape($section['key']) ?>" class="<?= $h2 ?>"><?= $escape($section['title']) ?>
                    </h2>
                    <a href="<?= $escape($section['url'] . rawurlencode((string) $section['slug'])) ?>"
                        class="<?= $link ?> text-sm"><?= $escape($section['link_label']) ?></a>
                </div>
                <?php if ($items !== []): ?>
                    <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0"
                        aria-label="<?= $escape($section['title']) ?>">
                        <div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">
                            <ul class="flex items-end gap-3">
                                <?php foreach ($items as $item): ?>
                                    <li class="w-44 shrink-0 sm:w-52">
                                        @include('partials.public.product-card', ['product' => $item, 'escape' => $escape])
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="rounded-md border-2 border-dashed border-current p-6 text-center text-sm">
                        Aucun autre article disponible pour le moment.
                    </p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <div data-share-feedback role="status" aria-live="polite"
        class="pointer-events-none fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-md bg-[#10302c] px-5 py-2.5 text-sm font-bold text-[#cfe8dc] opacity-0 shadow-[0_4px_0_#ff7a1a] transition-opacity dark:bg-[#cfe8dc] dark:text-[#0c1c1a]"
        aria-hidden="true"></div>
</main>
@endsection