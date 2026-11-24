@extends('layouts.app')
<?php
$seoBaseUrl = rtrim((string) app()->environment()->get('APP_URL', ''), '/');
$seoCanonicalUrl = $seoBaseUrl . '/products/' . rawurlencode((string) $product['slug']);
$relatedProducts = is_array($related_products ?? null) ? $related_products : ['category' => [], 'brand' => []];
$seoCanonicalUrl = filter_var($seoCanonicalUrl, FILTER_VALIDATE_URL) ? $seoCanonicalUrl : '';
$seoPlainDescription = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($product['description'] ?? ''))));
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
$updatedAt = !empty($product['updated_at']) ? strtotime((string) $product['updated_at']) : false;
$shareUrl = '/products/' . rawurlencode((string) $product['slug']);

$details = array_filter([
    'Référence' => $product['sku'] ?? '',
    'Catégorie' => $product['category_name'] ?? '',
    'Marque' => $product['brand_name'] ?? '',
    'Fabricant' => $product['manufacturer'] ?? '',
], static fn($value) => $value !== null && $value !== '');
$productTags = \App\Catalog\SeoTags::parse($product['seo_tags'] ?? '');

$shareIcon = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.7 10.7 6.6-4.4m-6.6 7 6.6 4.4"/></svg>';
$linkIcon = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.2 10.8a4 4 0 0 1 0 5.6l-2.4 2.4a4 4 0 0 1-5.6-5.6l1.2-1.2m9.2-3.2 1.2-1.2a4 4 0 0 0-5.6-5.6L9.6 5.2a4 4 0 0 0 0 5.6"/></svg>';
$playIcon = '<svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z"/></svg>';

/*
 * Système visuel : mise en page « fiche technique ». Zinc pour les surfaces, encre pour l'action,
 * émeraude pour les liens et la disponibilité, rouge uniquement pour la remise.
 * Rayons : sm/md partout, pas d'ombres, la structure vient des filets.
 */
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-emerald-400 dark:focus-visible:ring-offset-zinc-950';
$link = 'font-medium text-emerald-700 underline decoration-emerald-300 underline-offset-4 transition hover:decoration-emerald-600 dark:text-emerald-300 dark:decoration-emerald-500/40 dark:hover:decoration-emerald-300 ' . $focus;
$sectionTitle = 'text-lg font-semibold tracking-tight text-zinc-950 dark:text-white';
$btnInk = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-zinc-950 px-5 text-sm font-medium text-white transition hover:bg-zinc-700 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 ' . $focus;
$btnLine = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-zinc-300 px-5 text-sm font-medium text-zinc-800 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800 ' . $focus;

$renderMedia = static function (array $item, string $fallbackAlt, bool $eager) use ($escape, $playIcon): void {
    $type = $item['media_type'] ?? 'image';
    $frame = 'aspect-[4/5]';
    ?>
    <figure>
        <?php if ($type === 'image'): ?>
            <div class="<?= $frame ?> flex items-center justify-center bg-zinc-100 dark:bg-zinc-900">
                <img src="<?= $escape($item['url']) ?>" alt="<?= $escape($item['caption'] ?: $fallbackAlt) ?>"
                    loading="<?= $eager ? 'eager' : 'lazy' ?>" class="h-full w-full object-contain p-8 sm:p-12">
            </div>
        <?php elseif (!empty($item['external_url'])): ?>
            <a href="<?= $escape($item['url']) ?>" target="_blank" rel="noopener noreferrer"
                class="<?= $frame ?> flex flex-col items-center justify-center gap-3 bg-zinc-950 text-white transition hover:bg-zinc-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-emerald-400">
                <span
                    class="flex h-14 w-14 items-center justify-center rounded-full bg-white text-zinc-950"><?= $playIcon ?></span>
                <span class="text-sm font-medium">Regarder la vidéo</span>
            </a>
        <?php else: ?>
            <video src="<?= $escape($item['url']) ?>" controls preload="metadata"
                class="<?= $frame ?> w-full bg-zinc-950 object-contain"></video>
        <?php endif; ?>
        <?php if (!empty($item['caption'])): ?>
            <figcaption class="sr-only"><?= $escape($item['caption']) ?></figcaption>
        <?php endif; ?>
    </figure>
    <?php
};
?>
@include('partials.site-nav', ['active' => ''])
<main class="min-h-screen bg-white text-zinc-700 antialiased dark:bg-zinc-950 dark:text-zinc-300">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">

        <nav aria-label="Fil d’Ariane"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
            <a href="/" class="rounded transition hover:text-zinc-950 dark:hover:text-white <?= $focus ?>">Accueil</a>
            <?php if (!empty($product['category_name'])): ?>
                <span aria-hidden="true" class="text-zinc-300 dark:text-zinc-700">/</span>
                <?php if (!empty($product['category_slug'])): ?>
                    <a href="/categories/<?= $escape(rawurlencode((string) $product['category_slug'])) ?>"
                        class="rounded transition hover:text-zinc-950 dark:hover:text-white <?= $focus ?>"><?= $escape($product['category_name']) ?></a>
                <?php else: ?>
                    <span><?= $escape($product['category_name']) ?></span>
                <?php endif; ?>
            <?php endif; ?>
            <span aria-hidden="true" class="text-zinc-300 dark:text-zinc-700">/</span>
            <span class="truncate font-medium text-zinc-950 dark:text-white"
                aria-current="page"><?= $escape($product['name']) ?></span>
        </nav>

        <!-- ================= Galerie + achat ================= -->
        <section class="mt-8 grid items-start gap-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] lg:gap-16">

            <!-- Galerie : visuel principal, vignettes dessous -->
            <div class="min-w-0">
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <?php if ($media !== []): ?>
                        <?php foreach ($media as $index => $item): ?>
                            <div data-product-slide="<?= $index ?>" <?= $index === 0 ? '' : 'hidden' ?>
                                aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                                <?php $renderMedia($item, (string) $product['name'], $index === 0); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div
                            class="flex aspect-[4/5] flex-col items-center justify-center bg-zinc-100 px-8 text-center dark:bg-zinc-900">
                            <span
                                class="flex h-14 w-14 items-center justify-center rounded-md border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
                                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="m7.5 4.3 9 5.2M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="m3.3 7 8.7 5 8.7-5M12 22v-10" />
                                </svg>
                            </span>
                            <p class="mt-4 font-medium text-zinc-950 dark:text-white">Pas encore de visuel</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Les informations sont disponibles
                                sur cette page.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($media) > 1): ?>
                    <div class="mt-3 flex gap-2 overflow-x-auto p-1" role="group" aria-label="Choisir un visuel">
                        <?php foreach ($media as $index => $item): ?>
                            <button type="button" data-product-thumb="<?= $index ?>"
                                aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                                aria-label="Afficher le visuel <?= $index + 1 ?><?= !empty($item['caption']) ? ' : ' . $escape($item['caption']) : '' ?>"
                                class="h-16 w-16 shrink-0 overflow-hidden rounded-md border border-zinc-200 bg-zinc-100 opacity-60 transition hover:opacity-100 aria-[pressed=true]:border-zinc-950 aria-[pressed=true]:opacity-100 aria-[pressed=true]:ring-1 aria-[pressed=true]:ring-zinc-950 dark:border-zinc-800 dark:bg-zinc-900 dark:aria-[pressed=true]:border-white dark:aria-[pressed=true]:ring-white sm:h-20 sm:w-20 <?= $focus ?>">
                                <?php if (($item['media_type'] ?? 'image') === 'image'): ?>
                                    <img src="<?= $escape($item['url']) ?>" alt="" loading="lazy"
                                        class="h-full w-full object-cover">
                                <?php else: ?>
                                    <span
                                        class="flex h-full w-full items-center justify-center bg-zinc-950 text-white"><?= $playIcon ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Colonne d’achat : à plat, séparée par des filets -->
            <div class="lg:sticky lg:top-6">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span
                        class="rounded border border-zinc-300 px-1.5 py-px text-xs font-medium text-zinc-600 dark:border-zinc-700 dark:text-zinc-400"><?= $kindLabel ?></span>
                    <?php if (!empty($product['brand_name'])): ?>
                        <?php if (!empty($product['brand_slug'])): ?>
                            <a class="<?= $link ?>"
                                href="/brands/<?= $escape(rawurlencode((string) $product['brand_slug'])) ?>"><?= $escape($product['brand_name']) ?></a>
                        <?php else: ?>
                            <span class="font-medium text-zinc-600 dark:text-zinc-400"><?= $escape($product['brand_name']) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <h1
                    class="mt-4 text-balance text-3xl font-semibold leading-[1.1] tracking-tight text-zinc-950 dark:text-white sm:text-4xl xl:text-[2.75rem]">
                    <?= $escape($product['name']) ?>
                </h1>

                <?php if (!empty($product['manufacturer']) || !empty($product['sku'])): ?>
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                        <?php if (!empty($product['manufacturer'])): ?>Fabriqué par
                            <?= $escape($product['manufacturer']) ?>    <?php endif; ?>
                        <?php if (!empty($product['manufacturer']) && !empty($product['sku'])): ?> — <?php endif; ?>
                        <?php if (!empty($product['sku'])): ?>Réf. <span
                                class="font-mono text-xs"><?= $escape($product['sku']) ?></span><?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="mt-6 border-y border-zinc-200 py-6 dark:border-zinc-800">
                    <?php if ($hasPrice): ?>
                        <?php if ($hasPublicDiscount): ?>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <p class="text-4xl font-semibold tabular-nums tracking-tight text-red-600 dark:text-red-400">
                                    <?= $escape($formatPublicPrice($discountFinalPrice, $currency)) ?>
                                </p>
                                <span
                                    class="rounded-sm bg-red-600 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-white dark:bg-red-500">−<?= $escape($publicDiscount['total_saving_percentage']) ?>
                                    %</span>
                            </div>
                            <p class="mt-2 text-sm tabular-nums text-zinc-500 dark:text-zinc-400">
                                Au lieu de <s class="decoration-zinc-400"><?= $escape($formattedPrice) ?></s>
                                <span class="text-emerald-700 dark:text-emerald-400">— vous économisez
                                    <?= $escape($formatPublicPrice((string) $publicDiscount['total_saving'], $currency)) ?></span>
                            </p>
                            <details class="group mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                                <summary
                                    class="cursor-pointer rounded font-medium text-zinc-700 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white <?= $focus ?>">
                                    Détail des remises</summary>
                                <ul class="mt-2 space-y-1 border-l border-zinc-300 pl-3 dark:border-zinc-700">
                                    <?php foreach ($publicAppliedDiscounts as $appliedDiscount): ?>
                                        <?php
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
                                        ?>
                                        <li>Remise
                                            <?= $escape($targetLabel) ?>            <?= $targetName !== '' ? ' (' . $escape($targetName) . ')' : '' ?>
                                            : <?= $escape($valueLabel) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php else: ?>
                            <p class="text-4xl font-semibold tabular-nums tracking-tight text-zinc-950 dark:text-white">
                                <?= $escape($formattedPrice) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-2xl font-semibold text-zinc-950 dark:text-white">Prix sur demande</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Contactez-nous pour recevoir un devis.</p>
                    <?php endif; ?>

                    <p class="mt-4 flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        <?= $isService ? 'Service disponible' : 'Produit référencé' ?>
                    </p>
                </div>

                <div class="py-6">
                    @include('partials.public.product-cart')
                </div>

                <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-6 dark:border-zinc-800">
                    <button type="button" data-product-share data-share-url="<?= $escape($shareUrl) ?>"
                        data-share-title="<?= $escape($product['name']) ?>" class="<?= $btnInk ?>">
                        <?= $shareIcon ?>Partager
                    </button>
                    <button type="button" data-product-share data-share-mode="copy"
                        data-share-url="<?= $escape($shareUrl) ?>" data-share-title="<?= $escape($product['name']) ?>"
                        class="<?= $btnLine ?>">
                        <?= $linkIcon ?>Copier le lien
                    </button>
                </div>
            </div>
        </section>

        <!-- ================= Fiche : titre à gauche, contenu à droite ================= -->
        <section class="mt-20 grid gap-4 border-t border-zinc-200 pt-8 dark:border-zinc-800 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-12">
            <h2 class="<?= $sectionTitle ?>">Description</h2>
            <div>
                <?php if ($description !== ''): ?>
                    <div class="max-w-prose whitespace-pre-line text-base leading-7 text-zinc-600 dark:text-zinc-300">
                        <?= $escape($description) ?>
                    </div>
                <?php else: ?>
                    <p
                        class="max-w-prose rounded-md border border-dashed border-zinc-300 px-5 py-6 text-sm leading-6 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        La description de ce <?= $isService ? 'service' : 'produit' ?> n’est pas encore renseignée.
                    </p>
                <?php endif; ?>
                <?php if ($productTags !== []): ?>
                    <ul class="mt-6 flex flex-wrap gap-2" aria-label="Tags">
                        <?php foreach ($productTags as $tag): ?>
                            <li>
                                <a href="/tags/<?= $escape(rawurlencode(\App\Catalog\SeoTags::slug($tag))) ?>"
                                    class="inline-flex rounded-md bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 transition hover:bg-emerald-50 hover:text-emerald-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-emerald-400/10 dark:hover:text-emerald-300 <?= $focus ?>"><?= $escape($tag) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <section class="mt-12 grid gap-4 border-t border-zinc-200 pt-8 dark:border-zinc-800 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-12">
            <h2 class="<?= $sectionTitle ?>">Caractéristiques</h2>
            <dl class="max-w-2xl text-sm">
                <?php foreach ($details as $label => $value): ?>
                    <div class="grid grid-cols-[8rem_minmax(0,1fr)] gap-4 border-b border-zinc-100 py-3 first:pt-0 dark:border-zinc-800/70 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <dt class="text-zinc-500 dark:text-zinc-400"><?= $escape($label) ?></dt>
                        <dd class="font-medium text-zinc-950 dark:text-white">
                            <?php if ($label === 'Catégorie' && !empty($product['category_slug'])): ?>
                                <a class="<?= $link ?>"
                                    href="/categories/<?= $escape(rawurlencode((string) $product['category_slug'])) ?>"><?= $escape($value) ?></a>
                            <?php elseif ($label === 'Marque' && !empty($product['brand_slug'])): ?>
                                <a class="<?= $link ?>"
                                    href="/brands/<?= $escape(rawurlencode((string) $product['brand_slug'])) ?>"><?= $escape($value) ?></a>
                            <?php elseif ($label === 'Référence'): ?>
                                <span class="font-mono text-xs"><?= $escape($value) ?></span>
                            <?php else: ?>
                                <?= $escape($value) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
                <?php if ($updatedAt !== false): ?>
                    <div class="grid grid-cols-[8rem_minmax(0,1fr)] gap-4 py-3 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <dt class="text-zinc-500 dark:text-zinc-400">Dernière mise à jour</dt>
                        <dd class="font-medium tabular-nums text-zinc-950 dark:text-white">
                            <?= $escape(date('d/m/Y', $updatedAt)) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </section>

        <!-- ================= Articles liés ================= -->
        <?php
        $relatedSections = [
            ['key' => 'category', 'title' => 'Dans la même catégorie', 'link_label' => 'Voir la catégorie', 'slug' => $product['category_slug'] ?? null, 'url' => '/categories/'],
            ['key' => 'brand', 'title' => 'De la même marque', 'link_label' => 'Voir la marque', 'slug' => $product['brand_slug'] ?? null, 'url' => '/brands/'],
        ];
        ?>
        <?php foreach ($relatedSections as $section): ?>
            <?php if (empty($section['slug']))
                continue; ?>
            <?php $items = $relatedProducts[$section['key']] ?? []; ?>

            <section class="mt-16 border-t border-zinc-200 pt-8 dark:border-zinc-800"
                aria-labelledby="related-<?= $escape($section['key']) ?>">
                <div class="mb-5 flex flex-wrap items-baseline justify-between gap-3">
                    <h2 id="related-<?= $escape($section['key']) ?>" class="<?= $sectionTitle ?>">
                        <?= $escape($section['title']) ?>
                    </h2>
                    <a href="<?= $escape($section['url'] . rawurlencode((string) $section['slug'])) ?>"
                        class="<?= $link ?> text-sm"><?= $escape($section['link_label']) ?></a>
                </div>

                <?php if ($items !== []): ?>
                    <div class="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-3 sm:mx-0 sm:px-0"
                        role="region" tabindex="0" aria-label="<?= $escape($section['title']) ?>">
                        <?php foreach ($items as $item): ?>
                            <div class="w-56 shrink-0 snap-start">
                                @include('partials.public.product-card', ['product' => $item, 'escape' => $escape])
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p
                        class="rounded-md border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        Aucun autre article disponible pour le moment.
                    </p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <div data-share-feedback role="status" aria-live="polite"
        class="pointer-events-none fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-md bg-zinc-950 px-4 py-2.5 text-sm font-medium text-white opacity-0 shadow-lg transition-opacity dark:bg-white dark:text-zinc-950"
        aria-hidden="true"></div>
</main>
@endsection